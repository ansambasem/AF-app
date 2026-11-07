export const MAX_FORM_PAGES = 10;
export const FORM_PAPERS = { A4: [210, 297], A5: [148, 210], letter: [215.9, 279.4] };

export function formDimensions(layout) {
    const size = FORM_PAPERS[layout.paper] ?? FORM_PAPERS.A4;
    return layout.orientation === 'landscape' ? [...size].reverse() : [...size];
}

export function formText(text, values = {}, blank = false) {
    return String(text ?? '').replace(/\{([a-z][a-z0-9_]*)\}/g, (_, key) => blank ? '________________' : String(values[key] ?? '________________'));
}

export function formFields(layout, labels = {}) {
    const fields = new Map();
    for (const page of layout.pages ?? []) {
        for (const block of page.blocks ?? []) {
            for (const field of block.fields ?? []) fields.set(field.key, field.label);
            for (const match of (block.text ?? '').matchAll(/\{([a-z][a-z0-9_]*)\}/g)) {
                if (!fields.has(match[1])) fields.set(match[1], labels[match[1]] ?? match[1]);
            }
        }
    }
    for (const overlay of layout.overlays ?? []) fields.set(overlay.key, overlay.label);
    return [...fields].map(([key, label]) => ({ key, label }));
}

export function moveFormItem(items, index, offset) {
    const target = index + offset;
    if (target < 0 || target >= items.length) return items;
    const copy = [...items];
    [copy[index], copy[target]] = [copy[target], copy[index]];
    return copy;
}

export function printPageRules(pages) {
    return pages.map((page, index) => `@page cf_page_${index} { size: ${Number(page.width)}mm ${Number(page.height)}mm; margin: 0; }`).join('\n');
}

/**
 * A form's letterhead settings with the defaults for any it lacks, so a
 * form saved before they existed keeps its simple heading: the logo beside
 * the Arabic name and details, over one thin grey line.
 */
export const LETTERHEAD_DEFAULTS = {
    style: 'simple', logoPosition: 'center', logoWidth: 24, logoHeight: 22,
    english: '', arabicAlign: 'right', englishAlign: 'left',
    companyFont: 'plex', companySize: 17, subtitleSize: 13, englishFont: 'arial', englishSize: 14, textColor: '#17202a',
    ruleStyle: 'single', ruleWidth: 1, ruleColor: '#d8dde4', ruleColor2: '#a51d26', ruleGap: 1,
};

/** A ready formal letterhead: the logo centred, Arabic on the right, English on the left, over two coloured lines. */
export const FORMAL_LETTERHEAD = {
    style: 'letterhead', logoPosition: 'center', logoWidth: 32, logoHeight: 24, arabicAlign: 'right', englishAlign: 'left',
    ruleStyle: 'double', ruleWidth: 2, ruleColor: '#111827', ruleColor2: '#a51d26', ruleGap: 1,
};

export function letterhead(header = {}) {
    return { ...LETTERHEAD_DEFAULTS, ...Object.fromEntries(Object.entries(header ?? {}).filter(([, value]) => value !== null && value !== undefined)) };
}

/** The letterhead's three parts from right to left, the logo where it's placed. */
export function letterheadSlots(position) {
    return position === 'right' ? ['logo', 'arabic', 'english'] : position === 'left' ? ['arabic', 'english', 'logo'] : ['arabic', 'logo', 'english'];
}
