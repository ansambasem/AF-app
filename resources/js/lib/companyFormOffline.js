const escapeHtml = (value) => String(value).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;');

/** Page markup is cloned from the application's rendered, escaped form components. */
export function companyFormOfflineHtml(title, pagesHtml, css, pageRules) {
    return `<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${escapeHtml(title)}</title><style>${css}\n${pageRules}\nbody{margin:0;background:#e9ecf0;font-family:Tahoma,sans-serif}.offline-note{padding:16px;text-align:center;color:#444}#offline-papers .cf-paper{position:relative!important;transform:none!important;margin:0 auto 24px;page-break-after:always}#offline-papers .cf-paper:last-child{page-break-after:auto}@media print{.offline-note{display:none}#offline-papers .cf-paper{margin:0!important}}</style></head><body><p class="offline-note">نسخة محفوظة للطباعة دون إنترنت. استخدم أمر الطباعة في المتصفح، ثم اختر الطابعة أو حفظ PDF.</p><main id="offline-papers">${pagesHtml}</main></body></html>`;
}

export async function downloadCompanyFormOffline(root, title, css, pageRules) {
    const papers = [...root.querySelectorAll('[data-form-paper]')];
    const clones = await Promise.all(papers.map(async (paper, index) => {
        const clone = paper.cloneNode(true);
        clone.style.transform = 'none'; clone.style.position = 'relative'; clone.style.page = `cf_page_${index}`;
        for (const image of clone.querySelectorAll('img')) {
            if (image.src.startsWith('data:')) continue;
            const response = await fetch(image.src, { credentials: 'same-origin' });
            if (!response.ok) throw new Error('تعذّر تضمين شعار الشركة في النسخة المحفوظة.');
            const blob = await response.blob();
            image.src = await new Promise((resolve, reject) => { const reader = new FileReader(); reader.onload = () => resolve(reader.result); reader.onerror = reject; reader.readAsDataURL(blob); });
        }
        return clone.outerHTML;
    }));
    const blob = new Blob([companyFormOfflineHtml(title, clones.join(''), css, pageRules)], { type: 'text/html;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a'); link.href = url; link.download = `${title.replace(/[<>:"/\\|?*\u0000-\u001f]/g, '-')}.html`;
    document.body.append(link); link.click(); link.remove(); setTimeout(() => URL.revokeObjectURL(url), 1000);
}
