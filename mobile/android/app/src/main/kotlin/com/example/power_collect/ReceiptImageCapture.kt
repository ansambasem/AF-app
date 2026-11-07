package com.example.power_collect

import android.app.Activity
import android.content.ClipData
import android.content.Intent
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.ColorMatrix
import android.graphics.ColorMatrixColorFilter
import android.graphics.ImageDecoder
import android.graphics.Matrix
import android.graphics.Paint
import android.media.ExifInterface
import android.net.Uri
import android.os.Build
import android.provider.MediaStore
import androidx.core.content.FileProvider
import io.flutter.plugin.common.MethodCall
import io.flutter.plugin.common.MethodChannel
import java.io.File
import java.util.concurrent.Executors
import kotlin.math.abs
import kotlin.math.hypot
import kotlin.math.max
import kotlin.math.min

class ReceiptImageCapture(private val activity: MainActivity) {
    companion object {
        const val REQUEST_IMAGE = 4802
        private const val MAX_BYTES = 8 * 1024 * 1024
    }

    private val executor = Executors.newSingleThreadExecutor()
    private val directory = File(activity.cacheDir, "receipt-images")
    private var pending: MethodChannel.Result? = null
    private var cameraFile: File? = null

    init {
        directory.mkdirs()
        directory.listFiles()?.filter { it.lastModified() < System.currentTimeMillis() - 86400000 }
            ?.forEach { it.delete() }
    }

    fun handle(call: MethodCall, result: MethodChannel.Result) {
        when (call.method) {
            "pick" -> pick(call.argument<String>("source") ?: "gallery", result)
            "prepare" -> work(result) {
                val preview = ownedFile(call.argument<String>("previewPath"))
                val bitmap = BitmapFactory.decodeFile(preview.absolutePath)
                    ?: error("تعذر فتح الصورة.")
                try {
                    val points = call.argument<List<Number>>("corners")
                        ?: listOf(0, 0, 1, 0, 1, 1, 0, 1)
                    require(points.size == 8) { "حدد زوايا الإيصال الأربع." }
                    val corners = points.map { it.toFloat().coerceIn(0f, 1f) }.toFloatArray()
                    val prepared = warp(bitmap, corners, call.argument<Boolean>("contrast") ?: true)
                    try {
                        val file = File.createTempFile("processed-", ".jpg", directory)
                        file.outputStream().use { prepared.compress(Bitmap.CompressFormat.JPEG, 94, it) }
                        mapOf("processedPath" to file.absolutePath)
                    } finally {
                        prepared.recycle()
                    }
                } finally {
                    bitmap.recycle()
                }
            }
            "rotate" -> work(result) {
                val preview = ownedFile(call.argument<String>("previewPath"))
                val bitmap = BitmapFactory.decodeFile(preview.absolutePath)
                    ?: error("تعذر فتح الصورة.")
                try {
                    val matrix = Matrix().apply { postRotate(90f) }
                    val rotated = Bitmap.createBitmap(bitmap, 0, 0, bitmap.width, bitmap.height, matrix, true)
                    try {
                        preview.outputStream().use { rotated.compress(Bitmap.CompressFormat.JPEG, 94, it) }
                        mapOf("previewPath" to preview.absolutePath, "width" to rotated.width,
                            "height" to rotated.height, "corners" to edges(rotated))
                    } finally {
                        if (rotated !== bitmap) rotated.recycle()
                    }
                } finally {
                    bitmap.recycle()
                }
            }
            "discard" -> {
                val paths = call.argument<List<String>>("paths") ?: emptyList()
                paths.forEach { path -> runCatching { ownedFile(path).delete() } }
                result.success(null)
            }
            else -> result.notImplemented()
        }
    }

    private fun ownedFile(path: String?): File {
        require(path != null) { "الصورة غير متاحة." }
        val file = File(path).canonicalFile
        require(file.parentFile == directory.canonicalFile && file.isFile) { "الصورة غير متاحة." }
        return file
    }

    private fun pick(source: String, result: MethodChannel.Result) {
        if (pending != null) {
            result.error("busy", "انتظر اختيار الصورة الحالية.", null)
            return
        }
        pending = result
        try {
            val intent = if (source == "camera") {
                cameraFile = File.createTempFile("original-", ".jpg", directory)
                val uri = FileProvider.getUriForFile(activity,
                    activity.packageName + ".receipt-images", cameraFile!!)
                Intent(MediaStore.ACTION_IMAGE_CAPTURE).apply {
                    putExtra(MediaStore.EXTRA_OUTPUT, uri)
                    clipData = ClipData.newRawUri("receipt", uri)
                    addFlags(Intent.FLAG_GRANT_WRITE_URI_PERMISSION or Intent.FLAG_GRANT_READ_URI_PERMISSION)
                }
            } else {
                Intent(Intent.ACTION_GET_CONTENT).apply {
                    type = "image/*"
                    addCategory(Intent.CATEGORY_OPENABLE)
                }
            }
            activity.startActivityForResult(intent, REQUEST_IMAGE)
        } catch (_: Exception) {
            pending = null
            cameraFile?.delete()
            cameraFile = null
            result.error("picker_unavailable", "تعذر فتح الكاميرا أو الصور. اختر صورة من تطبيق الملفات.", null)
        }
    }

    fun onActivityResult(requestCode: Int, resultCode: Int, data: Intent?): Boolean {
        if (requestCode != REQUEST_IMAGE) return false
        val result = pending
        pending = null
        val captured = cameraFile
        cameraFile = null
        if (result == null) {
            captured?.delete()
            return true
        }
        if (resultCode != Activity.RESULT_OK) {
            captured?.delete()
            result.success(null)
            return true
        }
        work(result) {
            var original: File? = captured
            var preview: File? = null
            try {
                if (original == null) {
                    val uri: Uri = data?.data ?: error("لم يتم اختيار صورة.")
                    original = File.createTempFile("original-", ".image", directory)
                    activity.contentResolver.openInputStream(uri)?.use { input ->
                        original!!.outputStream().use { output ->
                            val buffer = ByteArray(8192)
                            var total = 0
                            while (true) {
                                val read = input.read(buffer)
                                if (read == -1) break
                                total += read
                                require(total <= MAX_BYTES) { "حجم الصورة يجب ألا يتجاوز 8 ميجابايت." }
                                output.write(buffer, 0, read)
                            }
                        }
                    } ?: error("تعذر قراءة الصورة المختارة.")
                }
                require(original!!.length() in 1..MAX_BYTES.toLong()) { "حجم الصورة يجب ألا يتجاوز 8 ميجابايت." }
                val bitmap = decode(original!!)
                try {
                    require(bitmap.width >= 100 && bitmap.height >= 100) { "اختر صورة أوضح وأكبر للإيصال." }
                    preview = File.createTempFile("preview-", ".jpg", directory)
                    preview!!.outputStream().use { bitmap.compress(Bitmap.CompressFormat.JPEG, 94, it) }
                    mapOf("originalPath" to original!!.absolutePath,
                        "previewPath" to preview!!.absolutePath,
                        "width" to bitmap.width, "height" to bitmap.height, "corners" to edges(bitmap))
                } finally {
                    bitmap.recycle()
                }
            } catch (exception: Exception) {
                original?.delete()
                preview?.delete()
                throw exception
            }
        }
        return true
    }

    private fun decode(file: File): Bitmap {
        val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
        BitmapFactory.decodeFile(file.absolutePath, bounds)
        require(bounds.outWidth in 100..12000 && bounds.outHeight in 100..12000) { "أبعاد الصورة غير مناسبة." }
        if (Build.VERSION.SDK_INT >= 28) {
            return ImageDecoder.decodeBitmap(ImageDecoder.createSource(file)) { decoder, info, _ ->
                decoder.allocator = ImageDecoder.ALLOCATOR_SOFTWARE
                val scale = min(1.0, 2400.0 / max(info.size.width, info.size.height))
                decoder.setTargetSize(max(100, (info.size.width * scale).toInt()),
                    max(100, (info.size.height * scale).toInt()))
            }
        }
        val options = BitmapFactory.Options().apply {
            inSampleSize = 1
            while (max(bounds.outWidth, bounds.outHeight) / inSampleSize > 2400) inSampleSize *= 2
        }
        val bitmap = BitmapFactory.decodeFile(file.absolutePath, options) ?: error("الصورة غير صالحة.")
        val orientation = runCatching { ExifInterface(file.absolutePath)
            .getAttributeInt(ExifInterface.TAG_ORIENTATION, ExifInterface.ORIENTATION_NORMAL) }.getOrDefault(1)
        val matrix = Matrix()
        when (orientation) {
            2 -> matrix.setScale(-1f, 1f)
            3 -> matrix.setRotate(180f)
            4 -> matrix.setScale(1f, -1f)
            5 -> { matrix.setRotate(90f); matrix.postScale(-1f, 1f) }
            6 -> matrix.setRotate(90f)
            7 -> { matrix.setRotate(270f); matrix.postScale(-1f, 1f) }
            8 -> matrix.setRotate(270f)
        }
        if (matrix.isIdentity) return bitmap
        val oriented = Bitmap.createBitmap(bitmap, 0, 0, bitmap.width, bitmap.height, matrix, true)
        if (oriented !== bitmap) bitmap.recycle()
        return oriented
    }

    /** Suggest a paper boundary on a darker background; use full image for screenshots. */
    private fun edges(bitmap: Bitmap): List<Double> {
        val full = listOf(0.0, 0.0, 1.0, 0.0, 1.0, 1.0, 0.0, 1.0)
        val small = Bitmap.createScaledBitmap(bitmap, 80, 80, true)
        try {
            val pixels = IntArray(6400)
            small.getPixels(pixels, 0, 80, 0, 0, 80, 80)
            fun luminance(index: Int): Int {
                val c = pixels[index]
                return (Color.red(c) * 299 + Color.green(c) * 587 + Color.blue(c) * 114) / 1000
            }
            val border = (0 until 80).flatMap { listOf(it, 6320 + it, it * 80, it * 80 + 79) }
                .map { luminance(it) }.sorted()
            val background = border[border.size / 2]
            if (background > 180) return full
            val visited = BooleanArray(6400)
            var largest = emptyList<Int>()
            for (index in pixels.indices) {
                if (visited[index] || luminance(index) < max(150, background + 35)) continue
                val queue = java.util.ArrayDeque<Int>()
                val component = mutableListOf<Int>()
                queue.add(index)
                visited[index] = true
                while (queue.isNotEmpty()) {
                    val at = queue.removeFirst()
                    component.add(at)
                    val neighbors = listOf(at - 80, at + 80,
                        if (at % 80 > 0) at - 1 else -1,
                        if (at % 80 < 79) at + 1 else -1)
                    for (next in neighbors) {
                        if (next in pixels.indices && !visited[next] &&
                            luminance(next) >= max(150, background + 35)) {
                            visited[next] = true
                            queue.add(next)
                        }
                    }
                }
                if (component.size > largest.size) largest = component
            }
            if (largest.size !in 1200..5900) return full
            val tl = largest.minBy { it % 80 + it / 80 }
            val tr = largest.maxBy { it % 80 - it / 80 }
            val br = largest.maxBy { it % 80 + it / 80 }
            val bl = largest.minBy { it % 80 - it / 80 }
            if (setOf(tl, tr, br, bl).size < 4) return full
            return listOf(tl, tr, br, bl).flatMap {
                listOf((it % 80 / 79.0).coerceIn(0.0, 1.0), (it / 80 / 79.0).coerceIn(0.0, 1.0))
            }
        } finally {
            if (small !== bitmap) small.recycle()
        }
    }

    private fun warp(bitmap: Bitmap, points: FloatArray, contrast: Boolean): Bitmap {
        fun distance(a: Int, b: Int): Float =
            hypot((points[a * 2] - points[b * 2]) * bitmap.width,
                (points[a * 2 + 1] - points[b * 2 + 1]) * bitmap.height)
        var area = 0f
        var previousSign = 0f
        for (i in 0..3) {
            val j = (i + 1) % 4
            val k = (i + 2) % 4
            val cross = (points[j * 2] - points[i * 2]) * (points[k * 2 + 1] - points[j * 2 + 1]) -
                (points[j * 2 + 1] - points[i * 2 + 1]) * (points[k * 2] - points[j * 2])
            require(abs(cross) > .001f && (previousSign == 0f || cross * previousSign > 0f)) { "الزوايا متقاطعة؛ أعد تحديد حدود الإيصال." }
            previousSign = cross
            area += points[i * 2] * points[j * 2 + 1] - points[j * 2] * points[i * 2 + 1]
        }
        require(abs(area) > .02f) { "حدد مساحة أكبر من الإيصال." }
        val width = max(distance(0, 1), distance(3, 2)).toInt().coerceIn(100, 3000)
        val height = max(distance(0, 3), distance(1, 2)).toInt().coerceIn(100, 3000)
        val source = FloatArray(8) { index -> points[index] * if (index % 2 == 0) bitmap.width else bitmap.height }
        val target = floatArrayOf(0f, 0f, width.toFloat(), 0f, width.toFloat(), height.toFloat(), 0f, height.toFloat())
        val matrix = Matrix()
        require(matrix.setPolyToPoly(source, 0, target, 0, 4)) { "تعذر تصحيح حدود الإيصال." }
        val output = Bitmap.createBitmap(width, height, Bitmap.Config.ARGB_8888)
        val canvas = Canvas(output)
        canvas.drawColor(Color.WHITE)
        val paint = Paint(Paint.ANTI_ALIAS_FLAG or Paint.FILTER_BITMAP_FLAG)
        if (contrast) {
            val saturation = ColorMatrix().apply { setSaturation(0f) }
            val increase = ColorMatrix(floatArrayOf(
                1.15f, 0f, 0f, 0f, -12f,
                0f, 1.15f, 0f, 0f, -12f,
                0f, 0f, 1.15f, 0f, -12f,
                0f, 0f, 0f, 1f, 0f))
            saturation.postConcat(increase)
            paint.colorFilter = ColorMatrixColorFilter(saturation)
        }
        canvas.drawBitmap(bitmap, matrix, paint)
        return output
    }

    private fun work(result: MethodChannel.Result, operation: () -> Any?) {
        executor.execute {
            try {
                val value = operation()
                activity.runOnUiThread { result.success(value) }
            } catch (exception: Exception) {
                activity.runOnUiThread { result.error("image_error", exception.message ?: "تعذر تجهيز الصورة.", null) }
            } catch (_: OutOfMemoryError) {
                activity.runOnUiThread { result.error("image_error", "الصورة كبيرة جدًا. اختر صورة أصغر.", null) }
            }
        }
    }

    fun dispose() {
        pending?.success(null)
        pending = null
        executor.shutdown()
    }
}

