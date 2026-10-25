/**
 * The print designer's layout: everything a printout can be set to, how it
 * is remembered per page, and the pure pieces the designer draws with
 * (column totals, the CSS for the printed page). See
 * Components/Print/PrintDesigner.jsx.
 */

/** Paper sizes the printer can be asked for, in millimetres (portrait). */
export const PAPER_SIZES = {
    A4: { label: 'A4', width: 210, height: 297 },
    A3: { label: 'A3', width: 297, height: 420 },
    A5: { label: 'A5', width: 148, height: 210 },
    letter: { label: 'Letter', width: 216, height: 279 },
    legal: { label: 'Legal', width: 216, height: 356 },
};

/** Vertical padding of a table cell, in px, for each row spacing. */
export const DENSITY_PADDING = { compact: 2, normal: 5, relaxed: 9 };

const LAYOUT_PREFIX = 'print-layout:';

/**
 * A fresh layout for a page whose table has the given columns (`{ key,
 * label, extra? }`, in the table's order). The table's own columns start
 * shown, its extra print fields hidden.
 */
export function defaultLayout({ columns, title = '', company = '' }) {
    return {
        paper: 'A4',
        orientation: columns.length > 6 ? 'landscape' : 'portrait',
        margin: 12,
        fontSize: 12,
        density: 'normal',
        header: {
            logo: true,
            company,
            branch: true,
            title,
            subtitle: '',
            date: true,
            user: true,
            count: true,
            centered: false,
            repeatTitle: false,
        },
        columns: columns.map((column) => ({ key: column.key, label: column.label, extra: Boolean(column.extra), visible: !column.extra, align: 'auto', total: false })),
        // Up to three levels, `{ key, direction }`; empty keeps the table's own order.
        sort: [],
        group: { key: '', newPage: false, subtotals: true },
        table: {
            borders: 'grid',
            zebra: true,
            headerShade: true,
            rowNumbers: false,
            wrap: true,
            accent: '#111827',
        },
        footer: {
            text: '',
            pageNumbers: true,
            summary: true,
            notes: '',
            signatures: [],
        },
    };
}

/** An object's settings without the empty ones (null, as the server stores a cleared text), so they don't replace a default. */
function present(object) {
    return Object.fromEntries(Object.entries(object ?? {}).filter(([, value]) => value !== null && value !== undefined));
}

/**
 * A saved layout fitted to the table as it is now: settings missing from an
 * older save (or emptied) take their default, columns keep the saved order, names and
 * choices, a column the table no longer has is dropped, and a new one is
 * added (shown) at the end.
 */
export function fitLayout(saved, fresh) {
    if (!saved || typeof saved !== 'object') {
        return fresh;
    }

    const savedColumns = Array.isArray(saved.columns) ? saved.columns : [];
    const blanks = savedColumns.filter((column) => column?.blank && /^blank:[a-zA-Z0-9_-]{1,80}$/.test(column.key)).slice(0, 10);
    const freshKeys = new Set([...fresh.columns, ...blanks].map((column) => column.key));
    const kept = savedColumns
        .filter((column) => column && freshKeys.has(column.key))
        .map((column) => {
            const freshColumn = fresh.columns.find((item) => item.key === column.key) ?? { ...column, extra: true, blank: true };

            return { ...freshColumn, ...present(column), extra: freshColumn.extra,
                ...(column.secondaryKey ? { secondaryKey: freshKeys.has(column.secondaryKey) ? column.secondaryKey : '' } : {}),
            };
        });
    const keptKeys = new Set(kept.map((column) => column.key));

    return {
        ...fresh,
        ...pick(present(saved), ['paper', 'orientation', 'margin', 'fontSize', 'density']),
        header: { ...fresh.header, ...present(saved.header) },
        table: { ...fresh.table, ...present(saved.table) },
        footer: { ...fresh.footer, ...present(saved.footer), signatures: (saved.footer?.signatures ?? fresh.footer.signatures).map((label) => label ?? '') },
        columns: [...kept, ...fresh.columns.filter((column) => !keptKeys.has(column.key))],
        sort: (Array.isArray(saved.sort) ? saved.sort : []).filter((level) => level && freshKeys.has(level.key)).slice(0, 3),
        group: { ...fresh.group, ...present(saved.group), key: freshKeys.has(saved.group?.key) ? saved.group.key : '',
            ...(saved.group?.then !== undefined ? { then: (Array.isArray(saved.group.then) ? [...new Set(saved.group.then)] : []).filter((key) => freshKeys.has(key) && key !== saved.group.key).slice(0, 2) } : {}),
        },
    };
}

/** Whether a cell starts with a number ("1,421.80 شيكل", "-45"), so it sorts as one. */
function startsWithNumber(text) {
    return /^\s*-?[\d\u0660-\u0669][\d\u0660-\u0669,]*([.\u066b][\d\u0660-\u0669]+)?(\s|$)/u.test(String(text ?? ''));
}

const TEXT_ORDER = new Intl.Collator('ar', { numeric: true, sensitivity: 'base' });

/**
 * Order two cells: numbers by value, other text alphabetically with the
 * numbers in it in numeric order (BOX-9 before BOX-10); empty cells last.
 */
export function compareCells(first, second) {
    const a = String(first ?? '').trim();
    const b = String(second ?? '').trim();

    if (a === '' || b === '') {
        return a === b ? 0 : a === '' ? 1 : -1;
    }

    if (startsWithNumber(a) && startsWithNumber(b)) {
        return cellNumber(a) - cellNumber(b);
    }

    return TEXT_ORDER.compare(a, b);
}

/**
 * The rows as the printout lists them, in sections: one untitled section
 * in the table's own order, or — once sorted or grouped — the data rows
 * sorted (by the group's field first, then each sort level) and split
 * into a section per group value. The table's own heading rows (e.g. a
 * day's heading) only make sense in its own order, so they are dropped
 * then.
 *
 * @return {Array<{ title: string|null, rows: Array }>}
 */
export function arrangeRows(rows, layout) {
    const levels = (layout.sort ?? []).filter((level) => level.key);
    const groupKey = layout.group?.key ?? '';
    const groupKeys = groupKey ? [...new Set([groupKey, ...(layout.group?.then ?? [])])].slice(0, 3) : [];

    if (levels.length === 0 && groupKey === '') {
        return [{ title: null, rows }];
    }

    const sortLevels = [...groupKeys.map((key) => ({ key, direction: 'asc' })), ...levels];
    const sorted = rows
        .filter((row) => row.type === 'row')
        .map((row, index) => ({ row, index }))
        .sort((a, b) => {
            for (const level of sortLevels) {
                const order = compareCells(a.row.cells[level.key], b.row.cells[level.key]);

                if (order !== 0) {
                    return level.direction === 'desc' ? -order : order;
                }
            }

            return a.index - b.index;
        })
        .map(({ row }) => row);

    if (groupKey === '') {
        return [{ title: null, rows: sorted }];
    }

    const sections = [];

    for (const row of sorted) {
        const values = groupKeys.map((key) => String(row.cells[key] ?? '').trim() || '—');
        const value = values.join(' / ');
        const last = sections.at(-1);

        if (last && (groupKeys.length > 1 ? JSON.stringify(last.groups.map((group) => group.value)) === JSON.stringify(values) : last.title === value)) {
            last.rows.push(row);
        } else {
            sections.push({ title: value, rows: [row], ...(groupKeys.length > 1 ? {
                groups: groupKeys.map((key, index) => ({ key, value: values[index] })),
                breakBefore: !last || last.groups[0].value !== values[0],
            } : {}) });
        }
    }

    return sections;
}

function pick(object, keys) {
    return Object.fromEntries(keys.filter((key) => object[key] !== undefined).map((key) => [key, object[key]]));
}

/** Move the column at `index` one place up (-1) or down (+1). */
export function moveColumn(columns, index, step) {
    const target = index + step;

    if (target < 0 || target >= columns.length) {
        return columns;
    }

    const next = [...columns];
    [next[index], next[target]] = [next[target], next[index]];

    return next;
}

/**
 * The number in a cell's text — "1,421.80 شيكل" → 1421.8, "-45" → -45 —
 * or null when it has none. Arabic-Indic digits count too.
 */
export function cellNumber(text) {
    const western = String(text ?? '').replace(/[\u0660-\u0669]/g, (digit) => String(digit.charCodeAt(0) - 0x0660));
    // A minus sign counts only at the start of the number, not inside a code such as "BOX-9597".
    const match = western.replace(/\u066b/g, '.').match(/(?:^|[^\p{L}\p{N}])(-?\d[\d,]*(?:\.\d+)?)/u);

    if (!match) {
        return null;
    }

    const value = Number(match[1].replace(/,/g, ''));

    return Number.isFinite(value) ? value : null;
}

/**
 * The total of a column's cells, written like its cells: two decimals only
 * when there are any, and the cells' unit (e.g. "شيكل") when they all share
 * it. Null when no cell has a number.
 */
export function columnTotal(texts) {
    const numbers = texts.map(cellNumber).filter((value) => value !== null);

    if (numbers.length === 0) {
        return null;
    }

    const sum = Math.round(numbers.reduce((total, value) => total + value, 0) * 100) / 100;
    const digits = Number.isInteger(sum) ? 0 : 2;
    const formatted = sum.toLocaleString('en-US', { minimumFractionDigits: digits, maximumFractionDigits: digits });
    const units = new Set(texts.filter((text) => cellNumber(text) !== null).map((text) => unitOf(text)));
    const unit = units.size === 1 ? [...units][0] : '';

    return unit ? `${formatted} ${unit}` : formatted;
}

/** Report rows supply explicit amounts, excluding returned payments and keeping currencies apart. */
export function rowColumnTotal(rows, key) {
    if (!rows.some((row) => row.totals !== undefined)) {
        return columnTotal(rows.map((row) => row.cells[key] ?? ''));
    }

    const amounts = new Map();
    for (const row of rows) {
        const value = row.totals?.[key];
        if (value == null || !Number.isFinite(Number(value))) {
            continue;
        }
        const unit = row.currency ?? '';
        amounts.set(unit, (amounts.get(unit) ?? 0) + Math.round(Number(value) * 100));
    }

    return amounts.size ? [...amounts].map(([unit, cents]) => `${(cents / 100).toLocaleString('en-US', { minimumFractionDigits: cents % 100 === 0 ? 0 : 2, maximumFractionDigits: 2 })} ${unit}`.trim()).join(' · ') : null;
}

/** The words around a cell's number, e.g. "شيكل" in "942.80 شيكل". */
function unitOf(text) {
    return String(text)
        .replace(/[\u0660-\u0669\u066b\d.,-]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

/** The paper's printed width and height in millimetres, for its orientation. */
export function paperSize(layout) {
    const paper = PAPER_SIZES[layout.paper] ?? PAPER_SIZES.A4;

    return layout.orientation === 'landscape' ? { width: paper.height, height: paper.width } : { width: paper.width, height: paper.height };
}

/** Text as a quoted CSS string, safe inside `content: …`. */
export function cssString(text) {
    return `"${String(text).replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/[\r\n]+/g, ' ')}"`;
}

/**
 * The `@page` rule for the printout: paper, orientation and margins, plus
 * what repeats in every page's margin — the page number, the footer text,
 * and the title when it repeats.
 */
export function pageCss(layout) {
    const paper = PAPER_SIZES[layout.paper] ? layout.paper : 'A4';
    const margin = Math.min(Math.max(Number(layout.margin) || 0, 0), 40);
    const box = 'font-size: 9pt; color: #4b5563;';
    const boxes = [];

    if (layout.footer.pageNumbers) {
        boxes.push(`@bottom-left { content: "صفحة " counter(page) " من " counter(pages); ${box} }`);
    }

    if (layout.footer.text.trim() !== '') {
        boxes.push(`@bottom-right { content: ${cssString(layout.footer.text)}; ${box} }`);
    }

    if (layout.header.repeatTitle && layout.header.title.trim() !== '') {
        boxes.push(`@top-right { content: ${cssString(layout.header.title)}; ${box} }`);
    }

    return `@page { size: ${paper} ${layout.orientation === 'landscape' ? 'landscape' : 'portrait'}; margin: ${margin}mm; ${boxes.join(' ')} }`;
}

function readJson(key) {
    try {
        const saved = localStorage.getItem(key);

        return saved ? JSON.parse(saved) : null;
    } catch {
        return null;
    }
}

function writeJson(key, value) {
    try {
        localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // Storage may be blocked (private mode); the layout still applies to this printout.
    }
}

/** The layout last used to print this page, in this browser. */
export function rememberedLayout(pageKey) {
    return readJson(LAYOUT_PREFIX + pageKey);
}

export function rememberLayout(pageKey, layout) {
    writeJson(LAYOUT_PREFIX + pageKey, layout);
}
