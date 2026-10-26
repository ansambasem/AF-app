import { test } from 'node:test';
import assert from 'node:assert/strict';
import { assignmentMatches, boardColumns, boardCounters, boxMatches, columnTotals, distributionAreas, distributionAreaBreakdown, branchDistributionSummary, distributionTargetPreview, distributionBoxOptions, draggedBoxIds, movePayload, readingSearchMatches, toggleBoxSelection } from '../../resources/js/lib/readingBoard.js';

const boards = [
    { id: 1, number: 'B-001', name: 'المدارس 1', subAreaId: 7, subscribers: 4, read: 1, version: 0, column: 'available', status: null },
    { id: 2, number: 'B-002', name: 'المدارس 2', subAreaId: 8, subscribers: 6, read: 6, version: 2, column: 'reader:5', status: 'approved' },
    { id: 3, number: 'B-003', name: 'السوق', subAreaId: 7, subscribers: 3, read: 0, version: 1, column: 'paper', status: 'draft' },
];

test('the box filter narrows the selected area and search without including another box', () => {
    assert.equal(boxMatches(boards[0], 'مدارس', ['7'], '1'), true);
    assert.equal(boxMatches(boards[0], '', ['8'], '1'), false);
    assert.equal(boxMatches(boards[0], '', ['7'], '3'), false);
    assert.equal(boxMatches(boards[0], 'سوق', ['7'], '1'), false);
    assert.equal(boxMatches(boards[0], '', [], 1), true);
    const visible = boards.filter((box) => boxMatches(box, '', ['7'], '3') && assignmentMatches(box, 'paper'));
    assert.deepEqual(visible.map((box) => box.id), [3]);
    assert.deepEqual([...toggleBoxSelection(new Set([2]), visible)].sort(), [2, 3]);
});

test('box choices follow areas and reader filters and show the name beside its number', () => {
    assert.deepEqual(distributionBoxOptions(boards, ['7'], 'available'), [
        { value: '1', label: 'المدارس 1 (B-001)', hint: 'بدون منطقة 2' },
    ]);
    assert.deepEqual(distributionBoxOptions(boards, ['7'], 'reader:5'), []);
    assert.deepEqual(distributionBoxOptions(boards, ['8'], 'reader:5').map((option) => option.value), ['2']);
    assert.deepEqual(distributionBoxOptions(boards, [], 'assigned').map((option) => option.value).sort(), ['2', '3']);
    assert.equal(distributionBoxOptions([{ ...boards[0], subAreaId: null, subAreaName: 'حي جديد' }], ['none'])[0].hint, 'حي جديد');
});

test('the pool comes first, then paper, then each reader', () => {
    assert.deepEqual(boardColumns([{ id: 5, name: 'أحمد' }]).map((column) => column.key), ['available', 'paper', 'reader:5']);
});

test('boxes are found by number or name within the chosen sub-area', () => {
    assert.equal(boxMatches(boards[0], 'b-001', ''), true);
    assert.equal(boxMatches(boards[0], 'المدارس', 8), false);
    assert.equal(boxMatches(boards[2], 'السوق', '7'), true);
});

test('dragging a chosen box carries every chosen box, otherwise only itself', () => {
    assert.deepEqual(draggedBoxIds(1, new Set([1, 3])), [1, 3]);
    assert.deepEqual(draggedBoxIds(2, new Set([1, 3])), [2]);
});

test('a move sends each box with the version shown and the target column', () => {
    assert.deepEqual(movePayload([1, 2], { target: 'mobile', userId: 5 }, boards), { box_ids: [1, 2], target: 'mobile', user_id: 5, versions: { 1: 0, 2: 2 }, confirm_downloaded: false });
    assert.equal(movePayload([3], { target: 'available' }, boards, true).user_id, null);
});

test('the counters and a column\'s totals add up', () => {
    assert.deepEqual(boardCounters(boards), { available: 1, mobile: 1, paper: 1, approved: 1, draft: 1, subscribers: 13 });
    assert.deepEqual(columnTotals(boards), { boxes: 3, subscribers: 13, read: 7, drafts: 1 });
});

test('search finds area names, suffixes and Arabic digits across several chosen areas', () => {
    const box = { ...boards[0], name: 'أبو ذر 8', number: '110303', subAreaName: 'الحي الشرقي' };
    assert.equal(boxMatches(box, 'الشرقي ابو', ['7', '8']), true);
    assert.equal(boxMatches(box, 'ذر 8 ١١٠٣٠٣', ['7']), true);
    assert.equal(boxMatches(box, 'أبو', ['8']), false);
    assert.equal(boxMatches(box, 'الحي الغربي', []), false);
    assert.equal(readingSearchMatches('أَبُو ذَرّ', 'ابو ذر'), true);
    assert.equal(boxMatches({ ...box, subAreaId: null }, '', ['none']), true);
});

test('selecting every matching result preserves other selections and toggles only those results', () => {
    const original = new Set([3]);
    const chosen = toggleBoxSelection(original, boards.slice(0, 2));
    assert.deepEqual([...chosen].sort(), [1, 2, 3]);
    assert.deepEqual([...original], [3]);
    assert.deepEqual([...toggleBoxSelection(chosen, boards.slice(0, 2))], [3]);
    assert.deepEqual([...toggleBoxSelection(original, [])], [3]);
    assert.deepEqual([...toggleBoxSelection(new Set([1]), boards.slice(0, 2))], [1, 2]);
});

test('area choices count all their boxes and subscribers including unclassified boxes', () => {
    const areas = distributionAreas([
        { ...boards[0], subAreaName: 'الحي الشرقي' },
        { ...boards[1], subAreaName: 'الحي الغربي' },
        { ...boards[2], subAreaName: 'الحي الشرقي' },
        { ...boards[0], id: 4, subAreaId: null, subAreaName: null },
    ]);
    assert.deepEqual(areas.find((area) => area.id === '7'), { id: '7', name: 'الحي الشرقي', boxes: 2, subscribers: 7, assigned: 1, unassigned: 1, matchingBoxes: 2, matchingSubscribers: 7 });
    assert.deepEqual(areas.find((area) => area.id === 'none'), { id: 'none', name: 'بدون منطقة 2', boxes: 1, subscribers: 4, assigned: 0, unassigned: 1, matchingBoxes: 1, matchingSubscribers: 4 });
    assert.equal(areas.length, 3);
});

test('assignment filters include both paper and reader work while keeping unassigned separate', () => {
    assert.deepEqual(boards.filter((box) => assignmentMatches(box, 'assigned')).map((box) => box.id), [2, 3]);
    assert.deepEqual(boards.filter((box) => assignmentMatches(box, 'available')).map((box) => box.id), [1]);
    assert.deepEqual(boards.filter((box) => assignmentMatches(box, 'paper')).map((box) => box.id), [3]);
    assert.deepEqual(boards.filter((box) => assignmentMatches(box, 'reader:5')).map((box) => box.id), [2]);
});

test('sidebar areas show only matching work and keep full assigned and remaining totals', () => {
    const unassigned = distributionAreas(boards, 'available');
    assert.equal(unassigned.length, 1);
    assert.equal(unassigned[0].id, '7');
    assert.equal(unassigned[0].matchingBoxes, 1);
    assert.equal(unassigned[0].matchingSubscribers, 4);
    assert.equal(unassigned[0].assigned, 1);
    assert.equal(unassigned[0].unassigned, 1);
    assert.equal(distributionAreas(boards, 'assigned').length, 2);
});

test('assigning the last remaining box removes its area from the unassigned sidebar', () => {
    const updated = boards.map((box) => box.id === 1 ? { ...box, column: 'reader:5', status: 'approved' } : box);
    assert.deepEqual(distributionAreas(updated, 'available'), []);
    const assigned = distributionAreas(updated, 'assigned').find((area) => area.id === '7');
    assert.equal(assigned.assigned, 2);
    assert.equal(assigned.unassigned, 0);
    assert.equal(assigned.matchingBoxes, 2);
});

test('the whole branch summary reconciles readers, paper and remaining boxes', () => {
    const boxes = Array.from({ length: 100 }, (_, i) => ({ ...boards[0], id: i + 1, subAreaId: Math.floor(i / 20), subAreaName: `منطقة ${Math.floor(i / 20)}`, column: i < 20 ? 'reader:1' : i < 40 ? 'reader:2' : i < 70 ? 'reader:3' : i < 90 ? 'paper' : 'available' }));
    const summary = branchDistributionSummary(boxes, [{ id: 1, name: 'محمد' }, { id: 2, name: 'عمر' }, { id: 3, name: 'خليل' }]);
    assert.deepEqual(summary.map((column) => column.boxes), [10, 20, 20, 20, 30]);
    assert.equal(summary.reduce((sum, column) => sum + column.subscribers, 0), 400);
    const khalil = summary.find((column) => column.key === 'reader:3');
    assert.deepEqual(khalil.areas.map((area) => [area.boxes, area.total, area.complete]), [[20, 20, true], [10, 20, false]]);
});

test('area completeness uses identities and branch totals, including uncategorized boxes', () => {
    const all = [
        { ...boards[0], subAreaName: 'نفس الاسم' }, { ...boards[1], subAreaName: 'نفس الاسم' },
        { ...boards[2], subAreaName: 'نفس الاسم' }, { ...boards[0], id: 4, subAreaId: null },
    ];
    const groups = distributionAreaBreakdown(all, [all[0], all[1], all[3]]);
    assert.equal(groups.length, 3);
    assert.equal(groups.find((area) => area.id === '7').complete, false);
    assert.equal(groups.find((area) => area.id === '8').complete, true);
    assert.equal(groups.find((area) => area.id === 'none').total, 1);
    assert.equal(groups.find((area) => area.id === '7').items[0].name, 'المدارس 1');
});

test('idle and unavailable readers are retained without losing any assigned work', () => {
    const summary = branchDistributionSummary(boards, [{ id: 9, name: 'قارئ جديد' }]);
    assert.equal(summary.find((column) => column.key === 'reader:9').boxes, 0);
    assert.equal(summary.find((column) => column.key === 'reader:5').unavailable, true);
    assert.equal(summary.reduce((sum, column) => sum + column.boxes, 0), boards.length);
    assert.deepEqual(branchDistributionSummary([], []).map((column) => column.boxes), [0, 0]);
});

test('target workload preview counts selected boxes already at the destination only once', () => {
    const original = structuredClone(boards);
    const preview = distributionTargetPreview(boards, boards, 'reader:5');
    assert.equal(preview.before.boxes, 1);
    assert.deepEqual(preview.after, { boxes: 3, subscribers: 13 });
    assert.deepEqual(distributionTargetPreview(boards, [], 'paper').after, { boxes: 1, subscribers: 3 });
    assert.deepEqual(boards, original);
});
