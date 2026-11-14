/**
 * The header over a table's row buttons: no visible title, but named
 * «الإجراءات» for screen readers. `data-actions` keeps the column out of
 * printouts (lib/print.js) and its title off the phone cards
 * (useResponsiveTables).
 */
export default function ActionsTh() {
    return (
        <th data-actions="">
            <span className="sr-only">الإجراءات</span>
        </th>
    );
}
