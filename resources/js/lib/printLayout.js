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

export const PRINT_FONTS = {
    plex: { label: 'IBM Plex عربي', family: '"IBM Plex Sans Arabic", sans-serif' },
    messiri: { label: 'المسيري', family: '"El Messiri", serif' },
    alexandria: { label: 'الإسكندرية', family: 'Alexandria, sans-serif' },
    arial: { label: 'Arial', family: 'Arial, sans-serif' },
    times: { label: 'Times New Roman', family: '"Times New Roman", serif' },
};

export function printFont(key) {
    return PRINT_FONTS[key]?.family ?? PRINT_FONTS.plex.family;
}

export const PRINT_PRESETS = [
    { id: 'official', name: 'ترويسة الشركة', description: 'شعار في الوسط ونص على الجانبين', header: { layout: 'letterhead', logoPosition: 'center', ruleStyle: 'double' } },
    { id: 'clearing', name: 'ورقة مقاصة', description: 'ترويسة رسمية وتوقيع الطرفين', header: { layout: 'letterhead', logoPosition: 'center', ruleStyle: 'double', title: 'ورقة مقاصة' }, footer: { signatures: ['توقيع الطرف الأول', 'توقيع الطرف الثاني'], notes: 'ملاحظات: ' } },
    { id: 'collection', name: 'كشف تحصيل', description: 'جدول واضح وتوقيع المحصّل', header: { layout: 'letterhead', logoPosition: 'right', title: 'كشف تحصيل' }, footer: { signatures: ['توقيع المحصّل', 'توقيع الإدارة'] } },
    { id: 'readings', name: 'كشف قراءات', description: 'صفوف واسعة للقراءة والمراجعة', header: { layout: 'letterhead', logoPosition: 'center', title: 'كشف قراءات العدادات' }, density: 'relaxed', footer: { signatures: ['قارئ العدادات', 'المراجع'] } },
    { id: 'compact', name: 'تقرير مختصر', description: 'خط أصغر ومسافات مضغوطة', header: { layout: 'standard', centered: false, ruleStyle: 'single' }, fontSize: 10, density: 'compact' },
    { id: 'centered', name: 'تقرير بترويسة وسطية', description: 'الشعار واسم الشركة فوق عنوان التقرير', header: { layout: 'standard', centered: true, titleAlign: 'center' } },
];

/** A starting design changes presentation, keeping the current report's data and columns. */
export function applyPrintPreset(layout, id) {
    const preset = PRINT_PRESETS.find((item) => item.id === id);
    if (!preset) return layout;

    return {
        ...layout,
        fontSize: preset.fontSize ?? layout.fontSize,
        density: preset.density ?? 'normal',
        header: { ...layout.header, ...(preset.header.layout === 'letterhead' ? { logoWidth: 36, logoHeight: 24 } : {}), ...preset.header },
        footer: { ...layout.footer, ...preset.footer },
    };
}

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
        fontFamily: 'plex',
        lineHeight: 1.5,
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
            layout: 'standard',
            companyEnglish: '',
            companyDetails: '',
            logoPosition: 'center',
            logoWidth: 24,
            logoHeight: 13,
            gap: 4,
            verticalAlign: 'center',
            companyAlign: 'right',
            englishAlign: 'left',
            titleAlign: 'center',
            companyFont: 'plex',
            englishFont: 'arial',
            titleFont: 'plex',
            companySize: 16,
            englishSize: 14,
            titleSize: 20,
            subtitleSize: 12,
            companyBold: true,
            englishBold: false,
            titleBold: true,
            ruleStyle: 'single',
            ruleWidth: 2,
            ruleColor: '#A51D26',
        },
        columns: columns.map((column) => ({ key: column.key, label: column.label, extra: Boolean(column.extra), visible: !column.extra, align: 'auto', total: false })),
        // Up to three levels, `{ key, direction }`; empty keeps the table's own order.
        sort: [],
        // `headings`: 'inline' writes a group's levels on one line, 'stacked' gives each level its own
        // line, written only when its value changes (e.g. the area, then each box under it). `unit`
        // follows each heading's count, e.g. "(12 مشترك)". `repeatHeader` writes the column titles
        // again under every group's heading. `newPageAt`: a new page for each top-level value
        // ('top', e.g. each region) or for every group ('every', e.g. each box). `styles` holds
        // each heading level's `{ size, background, color }`.
        group: {
            key: '', newPage: false, subtotals: true, headings: 'inline', align: 'start', labels: true, unit: '', repeatHeader: false,
            restartNumbers: false, keepTogether: false, newPageAt: 'top', blankRows: 0, showCount: true, styles: [],
        },
        table: {
            borders: 'grid',
            zebra: true,
            headerShade: true,
            rowNumbers: false,
            wrap: true,
            accent: '#111827',
            // Rows coloured by their values, first matching rule wins (see rowHighlight).
            highlights: [],
        },
        footer: {
            text: '',
            pageNumbers: true,
            summary: true,
            notes: '',
            signatures: [],
            pageNumberPosition: 'left',
        },
        watermark: { enabled: false, text: '', logo: false, opacity: 0.08 },
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
        ...pick(present(saved), ['paper', 'orientation', 'margin', 'fontSize', 'density', 'lineHeight']),
        fontFamily: Object.hasOwn(PRINT_FONTS, saved.fontFamily) ? saved.fontFamily : fresh.fontFamily,
        header: { ...fresh.header, ...present(saved.header) },
        table: { ...fresh.table, ...present(saved.table) },
        footer: { ...fresh.footer, ...present(saved.footer), signatures: (saved.footer?.signatures ?? fresh.footer.signatures).map((label) => label ?? '') },
        watermark: { ...fresh.watermark, ...present(saved.watermark) },
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

/**
 * The heading lines above each section from arrangeRows. Inline, one line
 * holds every level; stacked, each level gets its own line, written only
 * where its value changes from the section before, so an area is named
 * once above all of its boxes. Each line counts the rows under it.
 *
 * @return {Array<Array<{ level: number, groups: Array<{ key: string, label: string, value: string }>, count: number }>>}
 */
export function sectionHeadings(sections, layout) {
    const labelOf = (key) => layout.columns.find((column) => column.key === key)?.label ?? '';
    const levelsOf = (section) => (section.groups ?? [{ key: layout.group?.key ?? '', value: section.title }])
        .map((group) => ({ ...group, label: labelOf(group.key) }));
    const sameUpTo = (first, second, level) => first.title !== null && second.title !== null
        && levelsOf(first).slice(0, level + 1).every((group, index) => group.value === levelsOf(second)[index]?.value);

    return sections.map((section, index) => {
        if (section.title === null) {
            return [];
        }

        const levels = levelsOf(section);

        if (layout.group?.headings !== 'stacked') {
            return [{ level: 0, groups: levels, count: section.rows.length }];
        }

        const previous = sections[index - 1];
        const changed = previous ? levels.findIndex((_, level) => !sameUpTo(section, previous, level)) : 0;

        return levels.slice(changed === -1 ? levels.length - 1 : changed).map((group, offset) => {
            const level = (changed === -1 ? levels.length - 1 : changed) + offset;
            let count = 0;

            for (let next = index; next < sections.length && sameUpTo(section, sections[next], level); next++) {
                count += sections[next].rows.length;
            }

            return { level, groups: [group], count };
        });
    });
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

/** The page-margin boxes the page number can sit in, and where the footer text goes beside it. */
const PAGE_NUMBER_BOXES = { left: '@bottom-left', center: '@bottom-center', right: '@bottom-right' };

/**
 * The `@page` rule for the printout: paper, orientation and margins, plus
 * what repeats in every page's margin — the page number, the footer text,
 * the title, the company's name and the print date when they repeat.
 * `repeated` gives the company name and date to repeat.
 */
export function pageCss(layout, repeated = {}) {
    const paper = PAPER_SIZES[layout.paper] ? layout.paper : 'A4';
    const margin = Math.min(Math.max(Number(layout.margin) || 0, 0), 40);
    const box = `font-size: 9pt; color: #4b5563; font-family: ${printFont(layout.fontFamily)};`;
    const boxes = [];
    const numberBox = PAGE_NUMBER_BOXES[layout.footer.pageNumberPosition] ?? PAGE_NUMBER_BOXES.left;

    if (layout.footer.pageNumbers) {
        boxes.push(`${numberBox} { content: "صفحة " counter(page) " من " counter(pages); ${box} }`);
    }

    if (layout.footer.text.trim() !== '') {
        const textBox = layout.footer.pageNumbers && numberBox === '@bottom-right' ? '@bottom-left' : '@bottom-right';
        boxes.push(`${textBox} { content: ${cssString(layout.footer.text)}; ${box} }`);
    }

    if (layout.header.repeatTitle && layout.header.title.trim() !== '') {
        boxes.push(`@top-right { content: ${cssString(layout.header.title)}; ${box} }`);
    }

    if (layout.header.repeatCompany && String(repeated.company ?? '').trim() !== '') {
        boxes.push(`@top-left { content: ${cssString(repeated.company)}; ${box} }`);
    }

    if (layout.header.repeatDate && String(repeated.date ?? '').trim() !== '') {
        boxes.push(`@top-center { content: ${cssString(repeated.date)}; ${box} }`);
    }

    return `@page { size: ${paper} ${layout.orientation === 'landscape' ? 'landscape' : 'portrait'}; margin: ${margin}mm; ${boxes.join(' ')} }`;
}

/** The ways a highlight rule can compare a cell with its value. */
export const HIGHLIGHT_OPERATORS = {
    contains: 'يحتوي على',
    equals: 'يساوي',
    empty: 'فارغ',
    notEmpty: 'غير فارغ',
    gt: 'أكبر من',
    lt: 'أصغر من',
};

/** Whether a cell's text meets a highlight rule. */
export function matchesHighlight(text, rule) {
    const cell = String(text ?? '').trim();
    const value = String(rule.value ?? '').trim();

    switch (rule.operator) {
        case 'empty':
            return cell === '';
        case 'notEmpty':
            return cell !== '';
        case 'equals':
            return value !== '' && cell === value;
        case 'gt':
        case 'lt': {
            const number = cellNumber(cell);
            const limit = cellNumber(value);

            return number !== null && limit !== null && (rule.operator === 'gt' ? number > limit : number < limit);
        }
        default:
            return value !== '' && cell.includes(value);
    }
}

/**
 * The colour a data row prints in: the first highlight rule its cell
 * meets, as `{ color, key }` — `key` null to colour the whole row, else
 * only that column's cell — or null when no rule matches.
 */
export function rowHighlight(row, rules = []) {
    const rule = rules.find((item) => item?.key && matchesHighlight(row.cells?.[item.key], item));

    return rule ? { color: rule.color, key: rule.target === 'cell' ? rule.key : null } : null;
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
