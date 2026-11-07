import { test } from 'node:test';
import assert from 'node:assert/strict';
import { applyPrintPreset, arrangeRows, cellNumber, columnTotal, compareCells, cssString, defaultLayout, fitLayout, moveColumn, pageCss, PRINT_PRESETS, printFont, rowHighlight, matchesHighlight, sectionHeadings } from '../../resources/js/lib/printLayout.js';

const columns = [
    { key: 'name', label: 'الاسم' },
    { key: 'box', label: 'رقم الطبلون' },
    { key: 'balance', label: 'الرصيد' },
];

test('named starting designs keep the report columns and fit back into saved layouts', () => {
    const fresh = defaultLayout({ columns, title: 'الصندوق المالي', company: 'شركة أبو زايد' });
    fresh.columns[0].visible = false;
    fresh.header.companyEnglish = 'Fares Abu Zayed Sons';
    const clearing = applyPrintPreset(fresh, 'clearing');
    const saved = fitLayout(JSON.parse(JSON.stringify(clearing)), defaultLayout({ columns }));

    assert.equal(PRINT_PRESETS.length, 6);
    assert.equal(saved.header.title, 'ورقة مقاصة');
    assert.equal(saved.header.layout, 'letterhead');
    assert.equal(saved.header.logoPosition, 'center');
    assert.equal(saved.header.companyEnglish, 'Fares Abu Zayed Sons');
    assert.equal(saved.columns[0].visible, false);
    assert.deepEqual(saved.footer.signatures, ['توقيع الطرف الأول', 'توقيع الطرف الثاني']);
    assert.equal(fresh.header.layout, 'standard');
    assert.equal(fresh.footer.signatures.length, 0);
    assert.equal(applyPrintPreset(fresh, 'missing'), fresh);
});

test('legacy templates get usable header defaults and font choices cannot inject CSS', () => {
    const layout = fitLayout({ header: { centered: true }, fontFamily: 'x; } body { color:red' }, defaultLayout({ columns }));
    assert.equal(layout.header.centered, true);
    assert.equal(layout.header.layout, 'standard');
    assert.equal(layout.header.logoHeight, 13);
    assert.equal(layout.fontFamily, 'plex');
    assert.equal(printFont('unknown'), '"IBM Plex Sans Arabic", sans-serif');
    layout.fontFamily = 'messiri';
    assert.ok(pageCss(layout).includes('font-family: "El Messiri", serif'));
});

test('custom blank columns and secondary lines survive template loading without restoring removed data fields', () => {
    const fresh = defaultLayout({ columns });
    const layout = fitLayout({ columns: [
        { key: 'name', label: 'المشترك', secondaryKey: 'box', visible: true, width: 45 },
        { key: 'blank:notes-1', label: 'ملاحظات القارئ', visible: true, blank: true, width: 30 },
        { key: 'unauthorized-field', label: 'حقل محذوف', visible: true },
    ] }, fresh);
    assert.equal(layout.columns[0].secondaryKey, 'box');
    assert.equal(layout.columns[0].width, 45);
    assert.equal(layout.columns[1].key, 'blank:notes-1');
    assert.equal(layout.columns[1].blank, true);
    assert.equal(layout.columns.some((column) => column.key === 'unauthorized-field'), false);
});

test('three grouping levels keep boxes inside their reader and start a new page only for a new reader', () => {
    const row = (reader, area, box) => ({ type: 'row', cells: { reader, area, box } });
    const sections = arrangeRows([row('محمد', 'ب', '2'), row('محمد', 'أ', '1'), row('يوسف', 'أ', '3')], { group: { key: 'reader', then: ['area', 'box'], newPage: true }, sort: [] });
    assert.equal(sections.length, 3);
    assert.equal(sections[0].groups[0].value, 'محمد');
    assert.equal(sections[0].groups[1].value, 'أ');
    assert.equal(sections[1].breakBefore, false);
    assert.equal(sections[2].breakBefore, true);
});

test('stacked group headings name a region once above its boxes, each line counting the rows under it', () => {
    const row = (area, box, name) => ({ type: 'row', cells: { area, box, name } });
    const layout = {
        columns: [{ key: 'area', label: 'المنطقة' }, { key: 'box', label: 'الطبلون' }, { key: 'name', label: 'المشترك' }],
        group: { key: 'area', then: ['box'], headings: 'stacked' },
        sort: [],
    };
    const sections = arrangeRows([row('الحاج', '5', 'علي'), row('الأبراج', '9', 'سامي'), row('الحاج', '5', 'أحمد'), row('الحاج', '6', 'منى')], layout);
    const headings = sectionHeadings(sections, layout);

    assert.deepEqual(headings.map((lines) => lines.map((line) => [line.level, line.groups[0].value, line.count])), [
        [[0, 'الأبراج', 1], [1, '9', 1]],
        [[0, 'الحاج', 3], [1, '5', 2]],
        [[1, '6', 1]],
    ]);
    assert.equal(headings[0][0].groups[0].label, 'المنطقة');

    const inline = sectionHeadings(sections, { ...layout, group: { ...layout.group, headings: 'inline' } });
    assert.deepEqual(inline[2].map((line) => line.groups.map((group) => group.value)), [['الحاج', '6']]);
    assert.deepEqual(sectionHeadings(arrangeRows([row('الحاج', '5', 'علي')], { ...layout, group: { key: '' } }), layout), [[]]);
});

test('highlight rules colour the first matching row or only its cell', () => {
    const row = { type: 'row', cells: { status: 'لم تُدخل', due: '1,250.00', notes: '' } };
    assert.equal(matchesHighlight('لم تُدخل', { operator: 'contains', value: 'تُدخل' }), true);
    assert.equal(matchesHighlight('معتمدة', { operator: 'equals', value: 'معتمد' }), false);
    assert.equal(matchesHighlight('', { operator: 'empty' }), true);
    assert.equal(matchesHighlight('1,250.00', { operator: 'gt', value: '1000' }), true);
    assert.equal(matchesHighlight('—', { operator: 'lt', value: '5' }), false);
    assert.equal(matchesHighlight('abc', { operator: 'contains', value: '' }), false);
    assert.deepEqual(rowHighlight(row, [
        { key: 'due', operator: 'lt', value: '100', color: '#dcf5e3' },
        { key: 'due', operator: 'gt', value: '1000', color: '#fde2e1', target: 'cell' },
        { key: 'status', operator: 'contains', value: 'تُدخل', color: '#fdf0c8' },
    ]), { color: '#fde2e1', key: 'due' });
    assert.deepEqual(rowHighlight(row, [{ key: 'notes', operator: 'empty', color: '#e9eaee' }]), { color: '#e9eaee', key: null });
    assert.equal(rowHighlight(row, []), null);
});

test('page numbers move where chosen and the company and date repeat on every page when asked', () => {
    const layout = defaultLayout({ columns });
    assert.ok(pageCss(layout).includes('@bottom-left { content: "صفحة "'));
    layout.footer.pageNumberPosition = 'right';
    layout.footer.text = 'العنوان';
    layout.header.repeatCompany = true;
    layout.header.repeatDate = true;
    const css = pageCss(layout, { company: 'شركة "أبو زايد"', date: 'طُبع في 29/10/2026' });
    assert.ok(css.includes('@bottom-right { content: "صفحة "'));
    assert.ok(css.includes('@bottom-left { content: "العنوان"'));
    assert.ok(css.includes('@top-left { content: "شركة \\"أبو زايد\\""'));
    assert.ok(css.includes('@top-center { content: "طُبع في 29/10/2026"'));
    assert.equal(pageCss(defaultLayout({ columns }), { company: 'x', date: 'y' }).includes('@top-left'), false);
});

test('older saved layouts gain the new group, highlight and watermark settings switched off', () => {
    const layout = fitLayout({ group: { key: 'name' }, table: { zebra: false } }, defaultLayout({ columns }));
    assert.equal(layout.group.blankRows, 0);
    assert.equal(layout.group.showCount, true);
    assert.equal(layout.group.restartNumbers, false);
    assert.deepEqual(layout.table.highlights, []);
    assert.equal(layout.table.zebra, false);
    assert.deepEqual(layout.watermark, { enabled: false, text: '', logo: false, opacity: 0.08 });
});

test('a cell number is read with thousands separators, a minus sign and Arabic digits', () => {
    assert.equal(cellNumber('1,421.80 شيكل'), 1421.8);
    assert.equal(cellNumber('-45 شيكل'), -45);
    assert.equal(cellNumber('١٢٫٥'), 12.5);
    assert.equal(cellNumber('—'), null);
});

test('a dash inside a code is not read as a minus sign', () => {
    assert.equal(cellNumber('BOX-9597'), 9597);
});

test('a column total keeps the unit its cells share and two decimals only when needed', () => {
    assert.equal(columnTotal(['942.80 شيكل', '-45 شيكل', '—']), '897.80 شيكل');
    assert.equal(columnTotal(['10', '20']), '30');
    assert.equal(columnTotal(['10 شيكل', '5 كيلو']), '15');
    assert.equal(columnTotal(['—', '']), null);
});

test('a saved layout keeps its column order and names, drops removed columns and adds new ones at the end', () => {
    const fresh = defaultLayout({ columns, title: 'المشتركون' });
    const saved = {
        orientation: 'landscape',
        header: { title: 'كشف المشتركين' },
        columns: [
            { key: 'balance', label: 'المبلغ المستحق', visible: true, align: 'end', total: true },
            { key: 'gone', label: 'عمود قديم', visible: true },
            { key: 'name', label: 'الاسم', visible: false },
        ],
    };

    const layout = fitLayout(saved, fresh);

    assert.deepEqual(layout.columns.map((column) => column.key), ['balance', 'name', 'box']);
    assert.equal(layout.columns[0].label, 'المبلغ المستحق');
    assert.equal(layout.columns[0].total, true);
    assert.equal(layout.columns[1].visible, false);
    assert.equal(layout.columns[2].visible, true);
    assert.equal(layout.orientation, 'landscape');
    assert.equal(layout.header.title, 'كشف المشتركين');
    assert.equal(layout.header.logo, true);
});

test('a column moves one place and stays put at either end', () => {
    assert.deepEqual(moveColumn(['a', 'b', 'c'], 2, -1), ['a', 'c', 'b']);
    assert.deepEqual(moveColumn(['a', 'b', 'c'], 0, -1), ['a', 'b', 'c']);
    assert.deepEqual(moveColumn(['a', 'b', 'c'], 2, 1), ['a', 'b', 'c']);
});

test('the printed page gets its paper, margins, page numbers, footer and repeated title', () => {
    const layout = defaultLayout({ columns, title: 'تقرير "خاص"' });
    layout.paper = 'A3';
    layout.orientation = 'landscape';
    layout.margin = 8;
    layout.footer.text = 'شركة الكهرباء';
    layout.header.repeatTitle = true;

    const css = pageCss(layout);

    assert.match(css, /size: A3 landscape; margin: 8mm;/);
    assert.match(css, /@bottom-left \{ content: "صفحة " counter\(page\) " من " counter\(pages\)/);
    assert.match(css, /@bottom-right \{ content: "شركة الكهرباء";/);
    assert.match(css, /@top-right \{ content: "تقرير \\"خاص\\"";/);
});

test('text placed in the page margin cannot close its CSS string', () => {
    assert.equal(cssString('a"} body { color: red'), '"a\\"} body { color: red"');
    assert.equal(cssString('back\\slash\nline'), '"back\\\\slash line"');
});

const row = (cells) => ({ type: 'row', cells });

test('extra print fields start hidden while the table columns start shown', () => {
    const layout = defaultLayout({ columns: [...columns, { key: 'field:phone', label: 'رقم الجوال', extra: true }] });

    assert.deepEqual(
        layout.columns.map((column) => column.visible),
        [true, true, true, false],
    );
});

test('cells sort by number, then text with numbers in numeric order, empty cells last', () => {
    assert.ok(compareCells('942.80 شيكل', '1,421.80 شيكل') < 0);
    assert.ok(compareCells('BOX-9', 'BOX-10') < 0);
    assert.ok(compareCells('', 'أحمد') > 0);
    assert.ok(compareCells('أحمد', 'باسم') < 0);
});

test('rows sort by several levels and keep the table order for ties', () => {
    const layout = defaultLayout({ columns });
    layout.sort = [
        { key: 'box', direction: 'asc' },
        { key: 'name', direction: 'desc' },
    ];
    const rows = [
        row({ name: 'أ', box: 'BOX-10' }),
        row({ name: 'ب', box: 'BOX-9' }),
        row({ name: 'ج', box: 'BOX-10' }),
        { type: 'group', text: 'يوم' },
    ];

    const [section] = arrangeRows(rows, layout);

    assert.deepEqual(
        section.rows.map((item) => item.cells.name),
        ['ب', 'ج', 'أ'],
    );
});

test('grouping splits sorted rows into a section per value', () => {
    const layout = defaultLayout({ columns });
    layout.group.key = 'box';
    layout.sort = [{ key: 'name', direction: 'asc' }];
    const rows = [row({ name: 'ج', box: 'BOX-2' }), row({ name: 'أ', box: 'BOX-1' }), row({ name: 'ب', box: 'BOX-2' }), row({ name: 'د', box: '' })];

    const sections = arrangeRows(rows, layout);

    assert.deepEqual(
        sections.map((section) => [section.title, section.rows.map((item) => item.cells.name)]),
        [
            ['BOX-1', ['أ']],
            ['BOX-2', ['ب', 'ج']],
            ['—', ['د']],
        ],
    );
});

test('without sorting or grouping the table order and its heading rows stay', () => {
    const rows = [{ type: 'group', text: 'يوم' }, row({ name: 'ب' }), row({ name: 'أ' })];

    assert.deepEqual(arrangeRows(rows, defaultLayout({ columns })), [{ title: null, rows }]);
});

test('a saved sort or group on a column the table no longer has is dropped', () => {
    const layout = fitLayout({ sort: [{ key: 'gone', direction: 'asc' }, { key: 'box', direction: 'desc' }], group: { key: 'gone', newPage: true } }, defaultLayout({ columns }));

    assert.deepEqual(layout.sort, [{ key: 'box', direction: 'desc' }]);
    assert.equal(layout.group.key, '');
    assert.equal(layout.group.newPage, true);
});

test('older templates discard unavailable secondary fields and malformed nested groups', () => {
    const layout = fitLayout({
        columns: [{ key: 'name', secondaryKey: 'removed' }],
        group: { key: 'box', then: { key: 'name' } },
    }, defaultLayout({ columns }));
    assert.equal(layout.columns[0].secondaryKey, '');
    assert.deepEqual(layout.group.then, []);
    assert.equal(arrangeRows([row({ name: 'محمد', box: 'BOX-1' })], layout)[0].title, 'BOX-1');
});

test('settings the server stored as empty fall back to their defaults', () => {
    const layout = fitLayout(
        {
            paper: 'A3',
            header: { subtitle: null, title: 'كشف' },
            footer: { text: null, signatures: ['المحاسب', null] },
            columns: [{ key: 'name', label: null, visible: false }],
        },
        defaultLayout({ columns }),
    );

    assert.equal(layout.paper, 'A3');
    assert.equal(layout.header.subtitle, '');
    assert.equal(layout.header.title, 'كشف');
    assert.equal(layout.footer.text, '');
    assert.deepEqual(layout.footer.signatures, ['المحاسب', '']);
    assert.equal(layout.columns[0].label, 'الاسم');
    assert.equal(layout.columns[0].visible, false);
});
