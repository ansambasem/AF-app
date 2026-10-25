import { arrangeRows, rowColumnTotal, DENSITY_PADDING, paperSize } from '@/lib/printLayout';

// The time printed under the heading, in the app's Arabic with Western digits.
const PRINTED_AT_FORMAT = new Intl.DateTimeFormat('ar-SY-u-nu-latn', { dateStyle: 'long', timeStyle: 'short' });

const ALIGN_CLASSES = { auto: 'text-start', start: 'text-start', center: 'text-center', end: 'text-end' };

/**
 * The printout itself, drawn from the page's table (`table`, see
 * lib/print.js extractTable) as the layout says: the heading, the chosen
 * columns in their order and names, the rows sorted and grouped (each
 * group titled, with its count and subtotals, on a new page if asked),
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
    const groupColumn = layout.columns.find((column) => column.key === layout.group.key);
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
                zoom,
                '--pd-pad': `${DENSITY_PADDING[layout.density] ?? DENSITY_PADDING.normal}px`,
                '--pd-accent': style.accent,
            }}
        >
            <header
                className={`pd-header mb-4 flex gap-6 border-b-2 pb-3 ${header.centered ? 'flex-col items-center text-center' : 'items-start justify-between'}`}
                style={{ borderColor: style.accent }}
            >
                {(header.logo || header.company || header.branch) && (
                    <div className={`flex items-center gap-3 ${header.centered ? 'flex-col' : ''}`}>
                        {header.logo && <img src="/images/logo-af.webp" alt={header.company || context.appName} className="h-12 w-auto" />}
                        <div>
                            {header.company && <div className="text-[1.35em] font-bold">{header.company}</div>}
                            {header.branch && context.branchName && <div className="text-[0.95em] text-gray-600">{context.branchName}</div>}
                        </div>
                    </div>
                )}
                <div className={header.centered ? '' : 'text-end'}>
                    {header.title && (
                        <h1 className="text-[1.6em] font-bold" style={{ color: style.accent }}>
                            {header.title}
                        </h1>
                    )}
                    {header.subtitle && <p className="mt-1 whitespace-pre-line text-[1em] text-gray-700">{header.subtitle}</p>}
                    {meta.length > 0 && <div className="mt-1 text-[0.85em] text-gray-600">{meta.join(' — ')}</div>}
                </div>
            </header>

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
                    <thead>
                        <tr>
                            {style.rowNumbers && <th className="pd-number">#</th>}
                            {columns.map((column) => (
                                <th key={column.key} className={ALIGN_CLASSES[column.align]}>
                                    {column.label}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    {sections.map((section, sectionIndex) => (
                        <tbody key={sectionIndex} className={layout.group.newPage && sectionIndex > 0 && section.breakBefore !== false ? 'pd-new-page' : undefined}>
                            {section.title !== null && (
                                <tr className="pd-group pd-group-title">
                                    <td colSpan={span}>
                                        {section.groups ? section.groups.map((group) => `${layout.columns.find((column) => column.key === group.key)?.label}: ${group.value}`).join(' / ') : `${groupColumn?.label}: ${section.title}`}
                                        <span className="ms-2 font-normal text-gray-600">({section.rows.length.toLocaleString('en')})</span>
                                    </td>
                                </tr>
                            )}
                            {section.rows.map((row, index) =>
                                row.type === 'group' ? (
                                    <tr key={index} className="pd-group">
                                        <td colSpan={span}>{row.text}</td>
                                    </tr>
                                ) : (
                                    <tr key={index} className="pd-row">
                                        {style.rowNumbers && <td className="pd-number">{++rowNumber}</td>}
                                        {columns.map((column) => (
                                            <td key={column.key} className={`${ALIGN_CLASSES[column.align]} whitespace-pre-line`}>
                                                {!column.blank && <>{row.cells[column.key] ?? ''}{column.secondaryKey && <bdi className="mt-1 block text-[0.85em] font-normal text-gray-600">{row.cells[column.secondaryKey] ?? ''}</bdi>}</>}
                                            </td>
                                        ))}
                                    </tr>
                                ),
                            )}
                            {section.title !== null && layout.group.subtotals && hasTotals && (
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
                    ))}
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
