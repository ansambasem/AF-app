import { arrangeRows, rowColumnTotal, rowHighlight, sectionHeadings, DENSITY_PADDING, paperSize, printFont } from '@/lib/printLayout';
import PrintHeader from './PrintHeader';

// The time printed under the heading, in the app's Arabic with Western digits.
const PRINTED_AT_FORMAT = new Intl.DateTimeFormat('ar-EG-u-nu-latn', { dateStyle: 'long', timeStyle: 'short' });

const ALIGN_CLASSES = { auto: 'text-start', start: 'text-start', center: 'text-center', end: 'text-end' };

/**
 * The printout itself, drawn from the page's table (`table`, see
 * lib/print.js extractTable) as the layout says: the heading, the chosen
 * columns in their order and names, the rows sorted and grouped (each
 * group titled — on one line, or a line per level where it changes, at
 * the chosen alignment — with its count and subtotals, on a new page if asked),
 * totals, the page's summary, notes and signature boxes. On screen it is a sheet of the chosen paper; printed,
 * the paper and margins come from the @page rule (lib/printLayout.js
 * pageCss) instead.
 */
export default function PrintPaper({ layout, table, summary, context, zoom }) {
    const { header, footer } = layout;
    const details = (context.details ?? []).filter((field) => !header.reportFields || header.reportFields.includes(field.key));
    const style = layout.table;
    const columns = layout.columns.filter((column) => column.visible);
    const dataRows = table.rows.filter((row) => row.type === 'row');
    const sections = arrangeRows(table.rows, layout);
    const headings = sectionHeadings(sections, layout);
    const headingAlign = layout.group.align === 'center' ? 'text-center' : 'text-start';
    const group = layout.group;
    const blankRows = Math.min(Math.max(Number(group.blankRows) || 0, 0), 10);
    const highlights = style.highlights ?? [];
    const watermark = layout.watermark ?? {};
    // Repeated under every group's heading, the column titles take the place of the table's own head.
    const titlesPerGroup = Boolean(layout.group.repeatHeader) && sections.some((section) => section.title !== null);
    const columnTitles = (className) => (
        <tr className={className}>
            {style.rowNumbers && <th className="pd-number">#</th>}
            {columns.map((column) => (
                <th key={column.key} className={ALIGN_CLASSES[column.align]}>
                    {column.label}
                </th>
            ))}
        </tr>
    );
    const totalsOf = (rows) => columns.map((column) => (column.total && !column.blank ? rowColumnTotal(rows, column.key) : null));
    const totals = totalsOf(dataRows);
    const hasTotals = totals.some((total) => total !== null);
    const size = paperSize(layout);
    const span = columns.length + (style.rowNumbers ? 1 : 0);
    const meta = [
        header.date && PRINTED_AT_FORMAT.format(new Date()),
        header.user && context.userName,
        header.count && `عدد الصفوف: ${dataRows.length.toLocaleString('en')}`,
    ].filter(Boolean);
    let rowNumber = 0;

    return (
        <article
            aria-label="معاينة الورقة المطبوعة"
            className="pd-paper"
            style={{
                width: `${size.width}mm`,
                minHeight: `${size.height}mm`,
                padding: `${layout.margin}mm`,
                fontSize: `${layout.fontSize}px`,
                fontFamily: printFont(layout.fontFamily),
                lineHeight: layout.lineHeight ?? 1.5,
                zoom,
                '--pd-pad': `${DENSITY_PADDING[layout.density] ?? DENSITY_PADDING.normal}px`,
                '--pd-accent': style.accent,
            }}
        >
            {watermark.enabled && (watermark.text || watermark.logo) && <div className="pd-watermark" aria-hidden="true" style={{ opacity: Math.min(Math.max(Number(watermark.opacity) || 0.08, 0.02), 0.3) }}>
                {watermark.logo && <img src="/images/logo-af.webp" alt="" />}
                {watermark.text && <b>{watermark.text}</b>}
            </div>}

            <PrintHeader header={header} context={context} meta={meta} accent={style.accent} />

            {details.length > 0 && <dl className="mb-4 grid break-inside-avoid grid-cols-3 gap-x-5 gap-y-3 rounded border border-gray-200 p-3">
                {details.map((field) => <div key={field.key}>
                    <dt className="text-[0.85em] text-gray-500">{field.label}</dt><dd className="mt-1 whitespace-pre-line font-semibold">{field.value}</dd>
                </div>)}
            </dl>}

            {columns.length === 0 ? (
                <p className="pd-screen-only rounded border border-dashed border-gray-300 p-6 text-center text-gray-500">
                    كل الأعمدة مخفية — اختر عمودًا واحدًا على الأقل من «الأعمدة».
                </p>
            ) : (
                <table
                    className={[
                        'pd-table',
                        `pd-borders-${style.borders}`,
                        style.zebra && 'pd-zebra',
                        style.headerShade && 'pd-shade',
                        !style.wrap && 'pd-nowrap',
                    ]
                        .filter(Boolean)
                        .join(' ')}
                >
                    <colgroup>
                        {style.rowNumbers && <col style={{ width: '8mm' }} />}
                        {columns.map((column) => <col key={column.key} style={Number(column.width) > 0 && Number(column.width) <= 120 ? { width: `${Number(column.width)}mm` } : undefined} />)}
                    </colgroup>
                    {!titlesPerGroup && <thead>{columnTitles(undefined)}</thead>}
                    {sections.map((section, sectionIndex) => {
                        if (group.restartNumbers && section.title !== null) {
                            rowNumber = 0;
                        }
                        const breaks = group.newPage && sectionIndex > 0 && (group.newPageAt === 'every' || section.breakBefore !== false);

                        return (
                        <tbody key={sectionIndex} className={[breaks && 'pd-new-page', group.keepTogether && section.title !== null && 'pd-keep'].filter(Boolean).join(' ') || undefined}>
                            {headings[sectionIndex].map((heading) => {
                                const look = group.styles?.[heading.level] ?? {};

                                return (
                                <tr key={`heading-${heading.level}`} className={`pd-group pd-group-title pd-group-level-${heading.level}`}>
                                    <td colSpan={span} className={headingAlign} style={{
                                        ...(Number(look.size) > 0 ? { fontSize: `${Math.min(Math.max(Number(look.size), 0.8), 2)}em` } : {}),
                                        ...(look.background ? { background: look.background } : {}),
                                        ...(look.color ? { color: look.color } : {}),
                                    }}>
                                        {heading.groups.map((item) => (group.labels === false ? item.value : `${item.label}: ${item.value}`)).join(' / ')}
                                        {group.showCount !== false && <span className="ms-2 font-normal opacity-70">({[heading.count.toLocaleString('en'), group.unit].filter(Boolean).join(' ')})</span>}
                                    </td>
                                </tr>
                                );
                            })}
                            {titlesPerGroup && section.title !== null && columnTitles('pd-column-titles')}
                            {section.rows.map((row, index) =>
                                row.type === 'group' ? (
                                    <tr key={index} className="pd-group">
                                        <td colSpan={span}>{row.text}</td>
                                    </tr>
                                ) : (() => {
                                    const highlight = rowHighlight(row, highlights);
                                    const tint = (key) => (highlight && (highlight.key === null || highlight.key === key) ? { background: highlight.color } : undefined);

                                    return (
                                    <tr key={index} className="pd-row">
                                        {style.rowNumbers && <td className="pd-number" style={tint(null)}>{++rowNumber}</td>}
                                        {columns.map((column) => (
                                            <td key={column.key} className={`${ALIGN_CLASSES[column.align]} whitespace-pre-line`} style={tint(column.key)}>
                                                {!column.blank && <>{row.cells[column.key] ?? ''}{column.secondaryKey && <bdi className="mt-1 block text-[0.85em] font-normal text-gray-600">{row.cells[column.secondaryKey] ?? ''}</bdi>}</>}
                                            </td>
                                        ))}
                                    </tr>
                                    );
                                })(),
                            )}
                            {section.title !== null && Array.from({ length: blankRows }, (_, index) => (
                                <tr key={`blank-${index}`} className="pd-row pd-blank-row">
                                    {style.rowNumbers && <td className="pd-number" />}
                                    {columns.map((column) => <td key={column.key}>&nbsp;</td>)}
                                </tr>
                            ))}
                            {section.title !== null && group.subtotals && hasTotals && (
                                <tr className="pd-subtotal">
                                    {style.rowNumbers && <td className="pd-number" />}
                                    {totalsOf(section.rows).map((total, index) => (
                                        <td key={columns[index].key} className={ALIGN_CLASSES[columns[index].align]}>
                                            {total ?? (index === 0 ? `مجموع ${section.title}` : '')}
                                        </td>
                                    ))}
                                </tr>
                            )}
                        </tbody>
                        );
                    })}
                    {hasTotals && (
                        <tfoot>
                            <tr>
                                {style.rowNumbers && <td className="pd-number" />}
                                {columns.map((column, index) => (
                                    <td key={column.key} className={ALIGN_CLASSES[column.align]}>
                                        {totals[index] ?? (index === 0 ? (sections.length > 1 ? 'المجموع الكلي' : 'المجموع') : '')}
                                    </td>
                                ))}
                            </tr>
                        </tfoot>
                    )}
                </table>
            )}

            {footer.summary && summary && <p className="mt-3 text-[0.95em] font-semibold text-gray-800">{summary}</p>}

            {footer.notes && <p className="mt-4 whitespace-pre-line text-[0.95em] text-gray-800">{footer.notes}</p>}

            {footer.signatures.length > 0 && (
                <div className="pd-signatures mt-10 grid gap-8" style={{ gridTemplateColumns: `repeat(${Math.min(footer.signatures.length, 4)}, minmax(0, 1fr))` }}>
                    {footer.signatures.map((label, index) => (
                        <div key={index} className="text-center">
                            <div className="mb-1 h-12 border-b border-gray-500" />
                            <div className="text-[0.9em] font-semibold">{label || 'التوقيع'}</div>
                        </div>
                    ))}
                </div>
            )}

            {(footer.pageNumbers || footer.text) && (
                <div className="pd-screen-only mt-8 flex justify-between gap-4 border-t border-dashed border-gray-300 pt-2 text-[0.75em] text-gray-500">
                    <span>{footer.text}</span>
                    {footer.pageNumbers && <span>صفحة 1 من …</span>}
                </div>
            )}
        </article>
    );
}
