import { useEffect, useState } from 'react';
import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import { MAX_FORM_PAGES } from '@/lib/companyForms';

/** Render immutable source pages to images so the preview, print and offline copy use the same surface. */
export default function useCompanySource(source) {
    const [result, setResult] = useState({ key: null, pages: [], error: '' });
    const key = source?.url ?? '';
    useEffect(() => {
        if (!key) { setResult({ key, pages: [], error: '' }); return undefined; }
        let active = true;
        let task;
        const controller = new AbortController();
        async function load() {
            if (source.mime !== 'application/pdf') {
                const response = await fetch(key, { signal: controller.signal, credentials: 'same-origin' });
                if (!response.ok) throw new Error('تعذّر تحميل الملف الأصلي.');
                const blob = await response.blob();
                const dataUrl = await new Promise((resolve, reject) => { const reader = new FileReader(); reader.onload = () => resolve(reader.result); reader.onerror = reject; reader.readAsDataURL(blob); });
                const picture = new Image(); picture.src = dataUrl; await picture.decode();
                if (!picture.naturalWidth || picture.naturalHeight / picture.naturalWidth > 5) throw new Error('أبعاد الصورة لا تناسب نموذجًا مطبوعًا. استخدم PDF للصفحات الطويلة.');
                return [{ image: dataUrl, width: 210, height: 210 * picture.naturalHeight / picture.naturalWidth }];
            }
            const pdfjs = await import('pdfjs-dist');
            pdfjs.GlobalWorkerOptions.workerSrc = new URL(workerUrl, import.meta.url).href;
            const response = await fetch(key, { signal: controller.signal, credentials: 'same-origin' });
            if (!response.ok) throw new Error('تعذّر تحميل ملف PDF.');
            const assets = import.meta.env.DEV ? new URL(/* @vite-ignore */ '/pdfjs/', import.meta.url).href : `${import.meta.env.BASE_URL}pdfjs/`;
            task = pdfjs.getDocument({ data: new Uint8Array(await response.arrayBuffer()), isEvalSupported: false, enableXfa: false, cMapUrl: `${assets}cmaps/`, cMapPacked: true, standardFontDataUrl: `${assets}standard_fonts/`, wasmUrl: `${assets}wasm/` });
            const pdf = await task.promise;
            if (pdf.numPages > MAX_FORM_PAGES) throw new Error(`يمكن استيراد ${MAX_FORM_PAGES} صفحات على الأكثر. قسّم الملف إلى نماذج أصغر.`);
            const pages = [];
            for (let index = 1; index <= pdf.numPages; index++) {
                if (!active) return [];
                const page = await pdf.getPage(index);
                const original = page.getViewport({ scale: 1 });
                if (![original.width, original.height].every((value) => Number.isFinite(value) && value > 0 && value < 3400)) throw new Error('أبعاد إحدى صفحات PDF غير مناسبة للطباعة.');
                const viewport = page.getViewport({ scale: Math.min(3, 2400 / original.width, 2400 / original.height) });
                const canvas = document.createElement('canvas');
                canvas.width = Math.ceil(viewport.width); canvas.height = Math.ceil(viewport.height);
                await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
                pages.push({ image: canvas.toDataURL('image/jpeg', 0.95), width: original.width / 72 * 25.4, height: original.height / 72 * 25.4 });
                canvas.width = 0; canvas.height = 0;
                page.cleanup();
            }
            return pages;
        }
        load().then((pages) => { if (active) setResult({ key, pages, error: '' }); })
            .catch((error) => { if (active) setResult({ key, pages: [], error: error.name === 'PasswordException' ? 'الملف محمي بكلمة مرور. ارفع نسخة مفتوحة للقراءة.' : error.message || 'تعذّر قراءة الملف. اختر ملف PDF أو صورة صالحة.' }); })
            .finally(() => task?.destroy());
        return () => { active = false; controller.abort(); task?.destroy(); };
    }, [key, source?.mime]);
    return { pages: result.key === key ? result.pages : [], error: result.key === key ? result.error : '', loading: Boolean(key) && result.key !== key };
}
