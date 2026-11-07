import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    ROLE_COLORS,
    UNSET_ROLE_COLOR,
    comparePermissions,
    filterSections,
    matchesQuery,
    matrixCells,
    matrixExtras,
    normalizeArabic,
    permissionsWord,
    riskCounts,
    riskLevel,
    roleColors,
    sectionModel,
    withSectionsAll,
    withToggled,
} from '../../resources/js/lib/permissions.js';

const entry = (action, id, label = action) => ({ action, permission: id === null ? null : { id, label } });
const sorted = (ids) => [...ids].sort((a, b) => a - b);

const subscriptions = sectionModel({
    key: 'subscriptions',
    label: 'المشتركون',
    actions: [entry('view', 1), entry('create', 2), entry('update', 3), entry('delete', 4), entry('minimum_charge', 5)],
});
const payments = sectionModel({
    key: 'collections',
    label: 'الدفعات والحركات المالية',
    actions: [
        entry('view', 10),
        entry('record', 11),
        entry('record_cash', 12),
        entry('record_transfer', 13),
        entry('refund', 14),
        entry('delete', 15),
    ],
});
const readings = sectionModel({
    key: 'meter_readings',
    label: 'القراءات',
    actions: [entry('view', 20), entry('record', 21), entry('correct', 22), entry('approve', 23)],
});
const reports = sectionModel({ key: 'reports', label: 'التقارير', actions: [entry('view', 30), entry('export', 31)] });

test('each section reads across the same columns, one grant per cell, or nothing', () => {
    const cells = matrixCells(subscriptions);

    assert.deepEqual(
        cells.map((cell) => [cell.column.key, cell.kind, cell.id ?? null]),
        [
            ['view', 'permission', 1],
            ['create', 'permission', 2],
            ['update', 'permission', 3],
            ['delete', 'permission', 4],
            ['export', null, null],
        ],
    );
    assert.equal(cells[3].risk, 'danger');
    assert.ok(cells.every((cell) => !cell.kind || cell.needs === 0));
    // The minimum charge has no column of its own: it stays among the section's options.
    assert.deepEqual(
        matrixExtras(subscriptions).flatMap((group) => group.items.map((item) => item.id)),
        [5],
    );
});

test('a payment is «added» by recording it, and a section without a ladder still fills its columns', () => {
    assert.deepEqual(
        matrixCells(payments).map((cell) => cell.id ?? null),
        [10, 11, null, 15, null],
    );
    assert.deepEqual(
        matrixExtras(payments).flatMap((group) => group.items.map((item) => item.id)),
        [12, 13, 14],
    );
    assert.deepEqual(
        matrixCells(reports).map((cell) => [cell.kind, cell.id ?? null, cell.risk ?? null]),
        [
            ['permission', 30, null],
            [null, null, null],
            [null, null, null],
            [null, null, null],
            ['permission', 31, 'sensitive'],
        ],
    );
});

test('ticking or clearing a cell changes that grant alone', () => {
    const [view, add, edit, remove] = matrixCells(subscriptions);

    assert.deepEqual(withToggled([], edit.id, true), [3]);
    assert.deepEqual(sorted(withToggled([1, 2, 3, 4], add.id, false)), [1, 3, 4]);
    assert.deepEqual(sorted(withToggled([1, 2, 4], view.id, false)), [2, 4]);
    assert.deepEqual(withToggled([], remove.id, true), [4]);
    assert.deepEqual(sorted(withToggled([10], matrixCells(payments)[1].id, true)), [10, 11]);
});

test('granting or taking a whole section leaves the other sections as they were', () => {
    assert.deepEqual(sorted(withSectionsAll([subscriptions], [99, 20], true)), [1, 2, 3, 4, 5, 20, 99]);
    assert.deepEqual(withSectionsAll([subscriptions, readings], [1, 4, 20, 99], false), [99]);
});

test('the counts name how many held permissions are sensitive or dangerous', () => {
    assert.deepEqual(riskCounts([subscriptions, payments], [1, 4, 5, 10, 14]), { total: 5, sensitive: 1, danger: 2 });
    assert.deepEqual(riskCounts([subscriptions], []), { total: 0, sensitive: 0, danger: 0 });
});

test('search folds the ways Arabic is typed: hamza, alef maqsura, taa marbuta and diacritics', () => {
    assert.equal(normalizeArabic('إعتمادُ القراءةِ'), 'اعتماد القراءه');
    assert.ok(matchesQuery('ردّ الدفعة', 'رد الدفعه'));
    assert.ok(matchesQuery('اعتماد القراءات', 'إعتماد'));
    assert.ok(matchesQuery('أي شيء', '  '));
    assert.ok(!matchesQuery('الحذف', 'خصم'));
    assert.deepEqual(
        filterSections([subscriptions, payments], [], [], 'رد الدفعه').map((section) => section.key),
        ['collections'],
    );
});

test('each job title keeps one colour, in the order the titles are listed', () => {
    const colorOf = roleColors(['محصل', 'كهربائي', 'محصل', 'غير محدد']);

    assert.equal(colorOf('محصل'), ROLE_COLORS[0]);
    assert.equal(colorOf('كهربائي'), ROLE_COLORS[1]);
    assert.equal(colorOf('غير محدد'), UNSET_ROLE_COLOR);
    assert.equal(colorOf(null), UNSET_ROLE_COLOR);
    assert.equal(colorOf('محاسب'), roleColors([])('محاسب'));
    assert.ok(ROLE_COLORS.includes(colorOf('محاسب')));
});

test('the risk level rises with dangerous permissions, never past 100', () => {
    assert.deepEqual(riskLevel([subscriptions], []), { score: 0, level: 'low', label: 'منخفضة' });
    assert.equal(riskLevel([subscriptions, payments, readings], [1]).level, 'low');
    assert.equal(riskLevel([subscriptions, payments, readings], [1, 4]).level, 'mid');
    assert.equal(riskLevel([subscriptions, payments, readings], [4, 14, 15]).level, 'high');
    assert.equal(riskLevel([subscriptions], [1, 2, 3, 4, 5]).score, 100);
});

test('comparing two employees splits what only each holds from what both hold', () => {
    const { onlyMine, onlyTheirs, shared } = comparePermissions([subscriptions, payments], [1, 2, 10], [1, 4, 14, 999]);

    assert.deepEqual(
        onlyMine.map((item) => item.id),
        [2, 10],
    );
    assert.deepEqual(
        onlyTheirs.map((item) => item.id),
        [4, 14],
    );
    assert.deepEqual(
        shared.map((item) => item.id),
        [1],
    );
});

test('counts use the Arabic plural from three to ten', () => {
    assert.deepEqual([0, 1, 2, 3, 10, 11, 91, 103].map(permissionsWord), [
        'صلاحية',
        'صلاحية',
        'صلاحية',
        'صلاحيات',
        'صلاحيات',
        'صلاحية',
        'صلاحية',
        'صلاحيات',
    ]);
});
