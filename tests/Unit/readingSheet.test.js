import { test } from 'node:test';
import assert from 'node:assert/strict';
import { activeFilterChips, activeReadingStatus, readingStatusFilters, readingWeekParams } from '../../resources/js/lib/readingSheet.js';

test('the current sheet follows the branch reading day through search and pagination', () => {
    assert.deepEqual(readingWeekParams('2026-09-18', '2026-09-18'), {});
    assert.deepEqual(readingWeekParams('2026-09-25', '2026-09-25'), {});
});

test('choosing a previous reading week keeps its date through search and pagination', () => {
    assert.deepEqual(readingWeekParams('2026-09-11', '2026-09-18'), { week: '2026-09-11' });
});

test('switching status clears the previous status while retaining location filters', () => {
    const filters = { meter_box_id: '42', tariff_id: '3', entry: 'missing', approval: '' };
    const pending = { ...filters, ...readingStatusFilters('pending') };
    assert.deepEqual(pending, { meter_box_id: '42', tariff_id: '3', entry: '', approval: 'pending' });
    assert.equal(activeReadingStatus(pending), 'pending');
    const approved = { ...pending, ...readingStatusFilters('approved') };
    assert.equal(activeReadingStatus(approved), 'approved');
    const missing = { ...approved, ...readingStatusFilters('missing') };
    assert.equal(missing.approval, '');
    assert.equal(activeReadingStatus(missing), 'missing');
    assert.equal(activeReadingStatus({ ...missing, ...readingStatusFilters('all') }), 'all');
});

test('custom legacy filters do not highlight a tab that misrepresents the results', () => {
    assert.equal(activeReadingStatus({ entry: 'entered' }), null);
    assert.equal(activeReadingStatus({ entry: 'missing', approval: 'approved' }), null);
    assert.equal(activeReadingStatus({ entry: 'entered', approval: 'pending' }), 'pending');
});

test('the pinned bar names each active filter by its label and option, then the search', () => {
    const groups = [
        { key: 'branch_id', label: 'الفرع', options: [{ value: 3, label: 'فرع العشار' }] },
        { key: 'sub_area_id', label: 'منطقة 2', options: [{ value: '7', label: 'حي الجزائر' }] },
        { key: 'meter_box_id', label: 'الطبلون', options: [{ value: 12, label: 'BOX-9597' }] },
    ];

    assert.deepEqual(activeFilterChips(groups, { branch_id: '3', sub_area_id: '', meter_box_id: '12' }, '  أحمد '), [
        { key: 'branch_id', label: 'الفرع', value: 'فرع العشار' },
        { key: 'meter_box_id', label: 'الطبلون', value: 'BOX-9597' },
        { key: 'search', label: 'بحث', value: 'أحمد' },
    ]);
    assert.deepEqual(activeFilterChips(groups, {}, ''), []);
});
