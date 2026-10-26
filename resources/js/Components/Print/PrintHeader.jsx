import { printFont } from '@/lib/printLayout';

export default function PrintHeader({ header, context, meta, accent }) {
    const letterhead = header.layout === 'letterhead';
    const logo = header.logo && <img
        key="logo"
        src="/images/logo-af.webp"
        alt={header.company || context.appName}
        style={{ width: `${header.logoWidth ?? 36}mm`, height: `${header.logoHeight ?? 24}mm`, objectFit: 'contain', maxWidth: '100%', justifySelf: 'center' }}
    />;
    const company = <div key="company" dir="rtl" className="min-w-0 whitespace-pre-line" style={{ textAlign: header.companyAlign, overflowWrap: 'anywhere', fontFamily: printFont(header.companyFont), fontSize: `${header.companySize ?? 16}px` }}>
        {header.company && <div style={{ fontWeight: header.companyBold === false ? 400 : 700 }}>{header.company}</div>}
        {header.companyDetails && <div className="mt-1" style={{ fontSize: '0.85em' }}>{header.companyDetails}</div>}
        {header.branch && context.branchName && <div className="mt-1 text-gray-600" style={{ fontSize: '0.85em' }}>{context.branchName}</div>}
    </div>;
    const english = <div key="english" dir="ltr" className="min-w-0 whitespace-pre-line" style={{ textAlign: header.englishAlign, overflowWrap: 'anywhere', fontFamily: printFont(header.englishFont), fontSize: `${header.englishSize ?? 14}px`, fontWeight: header.englishBold ? 700 : 400 }}>{header.companyEnglish}</div>;
    const heading = <div style={{ textAlign: header.titleAlign ?? (header.centered ? 'center' : 'end') }}>
        {header.title && <h1 style={{ color: accent, fontFamily: printFont(header.titleFont), fontSize: `${header.titleSize ?? 20}px`, fontWeight: header.titleBold === false ? 400 : 700, overflowWrap: 'anywhere' }}>{header.title}</h1>}
        {header.subtitle && <p className="mt-1 whitespace-pre-line text-gray-700" style={{ fontSize: `${header.subtitleSize ?? 12}px` }}>{header.subtitle}</p>}
        {meta.length > 0 && <div className="mt-1 text-[0.85em] text-gray-600">{meta.join(' — ')}</div>}
    </div>;
    const position = header.logoPosition ?? 'center';
    const logoSlot = <div key="logo-slot" className="flex justify-center">{logo}</div>;
    const slots = position === 'left' ? [logoSlot, english, company] : position === 'right' ? [english, company, logoSlot] : [english, logoSlot, company];
    const rule = header.ruleStyle ?? 'single';

    return <header className="pd-header mb-4">
        {letterhead ? <>
            <div data-print-letterhead dir="ltr" className="grid" style={{
                gridTemplateColumns: position === 'left' ? 'auto minmax(0, 1fr) minmax(0, 1fr)' : position === 'right' ? 'minmax(0, 1fr) minmax(0, 1fr) auto' : 'minmax(0, 1fr) auto minmax(0, 1fr)',
                alignItems: header.verticalAlign === 'top' ? 'start' : header.verticalAlign === 'bottom' ? 'end' : 'center',
                gap: `${header.gap ?? 4}mm`,
            }}>{slots}</div>
        </> : <div className={`flex gap-6 ${header.centered ? 'flex-col items-center text-center' : 'items-start justify-between'}`}>
            {(header.logo || header.company || header.branch || header.companyEnglish) && <div className={`flex min-w-0 items-center gap-3 ${header.centered ? 'flex-col' : ''}`}>
                {logo}<div>{company}{header.companyEnglish && english}</div>
            </div>}
            {heading}
        </div>}
        {rule !== 'none' && <div aria-hidden="true" className="mt-3" style={{
            borderTop: `${header.ruleWidth ?? 2}px solid ${accent}`,
            ...(rule === 'double' ? { paddingTop: '1mm', borderBottom: `${header.ruleWidth ?? 2}px solid ${header.ruleColor ?? '#A51D26'}` } : {}),
        }} />}
        {letterhead && <div className="mt-4">{heading}</div>}
    </header>;
}
