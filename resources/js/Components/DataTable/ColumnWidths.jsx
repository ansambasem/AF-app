/**
 * Set widths for a `.data-table-fixed` table's columns, in pixels, with
 * `null` for the one column that takes what is left. A big or small figure
 * then never resizes a column; the table scrolls sideways when it needs
 * more room than it has (see `fixedTableStyle`).
 */
export default function ColumnWidths({ widths }) {
    return (
        <colgroup>
            {widths.map((width, index) => (
                <col key={index} style={width ? { width } : undefined} />
            ))}
        </colgroup>
    );
}

/** The table's least width: every set column, and 150px for the one left open. */
export function fixedTableStyle(widths) {
    return { '--table-min-width': `${widths.reduce((sum, width) => sum + (width ?? 150), 0)}px` };
}
