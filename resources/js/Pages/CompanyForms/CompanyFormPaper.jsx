import { useEffect, useRef, useState } from 'react';
import { formDimensions, formText, letterhead, letterheadSlots } from '@/lib/companyForms';
import { printFont } from '@/lib/printLayout';
import './CompanyForms.css';

/**
 * The form's letterhead: simple (the logo beside the Arabic name) or
 * formal (the logo right, centred or left, Arabic text on the right and
 * English on the left), over no line, one line or two, each in its colour.
 */
function FormLetterhead({ header: saved }) {
    const header = letterhead(saved);
    const parts = {
        logo: header.logo && <img key="logo" src="/images/logo-af.webp" alt="شعار الشركة" style={{ width: `${header.logoWidth}mm`, height: `${header.logoHeight}mm` }} />,
        arabic: <div key="arabic" dir="rtl" className="cf-letterhead-text" style={{ textAlign: header.arabicAlign, fontFamily: printFont(header.companyFont), color: header.textColor }}>
            {header.company && <b style={{ fontSize: `${header.companySize}px` }}>{header.company}</b>}
            {header.subtitle && <p style={{ fontSize: `${header.subtitleSize}px` }}>{header.subtitle}</p>}
        </div>,
        english: header.english && <div key="english" dir="ltr" className="cf-letterhead-text cf-letterhead-english" style={{ textAlign: header.englishAlign, fontFamily: printFont(header.englishFont), fontSize: `${header.englishSize}px`, color: header.textColor }}>{header.english}</div>,
    };
    const slots = letterheadSlots(header.logoPosition);

    return <div className="cf-letterhead-block">
        {header.style === 'letterhead'
            ? <header className="cf-letterhead" style={{ gridTemplateColumns: slots.map((slot) => (slot === 'logo' ? 'auto' : 'minmax(0, 1fr)')).join(' ') }}>{slots.map((slot) => parts[slot] || <div key={slot} />)}</header>
            : <header className="cf-heading">{parts.logo}{parts.arabic}{parts.english}</header>}
        {header.ruleStyle !== 'none' && <div aria-hidden="true" className="cf-rule" style={{
            borderTop: `${header.ruleWidth}px solid ${header.ruleColor}`,
            ...(header.ruleStyle === 'double' ? { paddingTop: `${header.ruleGap}mm`, borderBottom: `${header.ruleWidth}px solid ${header.ruleColor2}` } : {}),
        }} />}
    </div>;
}

export default function CompanyFormPaper({ layout, page, pageIndex = 0, sourcePage = null, values = {}, blank = false, onPlace, selectedOverlay, onSelectOverlay, onOverflow }) {
    const wrapper = useRef(null);
    const body = useRef(null);
    const paper = useRef(null);
    const [scale, setScale] = useState(1);
    const [width, height] = sourcePage ? [sourcePage.width, sourcePage.height] : formDimensions(layout);
    useEffect(() => {
        const observer = new ResizeObserver(([entry]) => setScale(Math.min(1, entry.contentRect.width / (width * 96 / 25.4))));
        observer.observe(wrapper.current);
        return () => observer.disconnect();
    }, [width]);
    useEffect(() => {
        let active = true;
        const check = () => {
            const overlayOverflow = [...(paper.current?.querySelectorAll('[data-form-overlay]') ?? [])].some((node) => node.offsetTop + node.offsetHeight > paper.current.clientHeight + 2);
            if (active) onOverflow?.(pageIndex, overlayOverflow || paper.current?.scrollHeight > paper.current?.clientHeight + 2 || body.current?.scrollHeight > body.current?.clientHeight + 2);
        };
        const frame = requestAnimationFrame(check);
        document.fonts.ready.then(check);
        return () => { active = false; cancelAnimationFrame(frame); };
    }, [layout, page, values, pageIndex, onOverflow]);

    function place(event) {
        if (!onPlace || event.target.closest('[data-form-overlay]')) return;
        const bounds = event.currentTarget.getBoundingClientRect();
        onPlace(pageIndex + 1, (event.clientX - bounds.left) / bounds.width * 100, (event.clientY - bounds.top) / bounds.height * 100);
    }

    return <div ref={wrapper} className="cf-paper-container">
        <div className="cf-page-shell" style={{ height: `${height * 96 / 25.4 * scale}px`, page: `cf_page_${pageIndex}` }}>
            <article ref={paper} className={`cf-paper ${onPlace ? 'cf-placing' : ''}`} data-form-paper data-page-width={width} data-page-height={height} style={{ width: `${width}mm`, height: `${height}mm`, transform: `scale(${scale})`, fontSize: `${layout.fontSize}px`, padding: `${sourcePage ? 0 : layout.margin}mm` }} onClick={place} dir="rtl">
                {sourcePage && <img className="cf-source" src={sourcePage.image} alt="النموذج الأصلي" />}
                {layout.watermark.enabled && <div className="cf-watermark" style={{ opacity: layout.watermark.opacity }} aria-hidden="true">{layout.watermark.logo && <img src="/images/logo-af.webp" alt="" />}<b>{layout.watermark.text}</b></div>}
                <div className="cf-content" style={sourcePage ? { padding: `${layout.margin}mm` } : undefined}>
                    {layout.header.enabled && <FormLetterhead header={layout.header} />}
                    <div ref={body} className="cf-blocks">
                        {(page?.blocks ?? []).map((block) => <div key={block.id} className={`cf-block cf-block-${block.type}`} style={{ textAlign: block.align, fontWeight: block.bold ? 700 : 400, fontSize: block.fontSize ? `${block.fontSize}px` : undefined }}>
                            {block.type === 'spacer' ? <div style={{ height: `${block.height ?? 8}mm` }} />
                                : block.type === 'fields' ? <dl className="cf-fields">{(block.fields ?? []).map((field, index) => <div key={`${field.key}-${index}`}><dt>{field.label}</dt><dd>{!blank && values[field.key] !== undefined && values[field.key] !== '' ? <bdi>{values[field.key]}</bdi> : <span className="cf-fill-line" />}</dd></div>)}</dl>
                                : block.type === 'signatures' ? <div className="cf-signatures">{(block.text ?? '').split('|').filter(Boolean).map((label, index) => <div key={index}><b>{label}</b><span /></div>)}</div>
                                : <p>{formText(block.text, values, blank)}</p>}
                        </div>)}
                    </div>
                    {layout.footer && <footer className="cf-footer">{layout.footer}<span>{pageIndex + 1}</span></footer>}
                </div>
                {(layout.overlays ?? []).filter((overlay) => Number(overlay.page) === pageIndex + 1).map((overlay) => <span key={overlay.id} data-form-overlay className={`cf-overlay ${selectedOverlay === overlay.id ? 'is-selected' : ''} ${onSelectOverlay ? 'is-editable' : ''}`} title={onSelectOverlay ? overlay.label : undefined} onClick={() => onSelectOverlay?.(overlay.id)} style={{ left: `${overlay.x}%`, top: `${overlay.y}%`, width: `${overlay.width}%`, fontSize: `${overlay.fontSize}px`, textAlign: overlay.align }}>{blank ? (onSelectOverlay ? overlay.label : '') : values[overlay.key] || (onSelectOverlay ? overlay.label : '')}</span>)}
            </article>
        </div>
    </div>;
}
