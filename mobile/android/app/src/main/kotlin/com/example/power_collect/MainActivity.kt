package com.example.power_collect

import android.content.Intent
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

class MainActivity : FlutterActivity() {
    private var receiptImages: ReceiptImageCapture? = null

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)

        receiptImages = ReceiptImageCapture(this)
        val sessions = SessionTokenStorage(this)
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, "powercollect.session")
            .setMethodCallHandler { call, result ->
                try {
                    when (call.method) {
                        "read" -> result.success(sessions.read())
                        "write" -> { sessions.write(call.arguments as String); result.success(null) }
                        "clear" -> { sessions.clear(); result.success(null) }
                        else -> result.notImplemented()
                    }
                } catch (_: Exception) {
                    result.error("SESSION_STORAGE", "Unable to access secure session storage", null)
                }
            }
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, "powercollect.receipts")
            .setMethodCallHandler { call, result -> receiptImages!!.handle(call, result) }

        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, "powercollect.storage")
            .setMethodCallHandler { call, result ->
                if (call.method == "filesDirectory") {
                    result.success(filesDir.absolutePath)
                } else {
                    result.notImplemented()
                }
            }
    }

    override fun onActivityResult(requestCode: Int, resultCode: Int, data: Intent?) {
        if (receiptImages?.onActivityResult(requestCode, resultCode, data) == true) return
        super.onActivityResult(requestCode, resultCode, data)
    }

    override fun onDestroy() {
        receiptImages?.dispose()
        super.onDestroy()
    }
}
