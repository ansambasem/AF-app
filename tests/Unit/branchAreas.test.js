import { test } from 'node:test';
import assert from 'node:assert/strict';
import { areaAfterBranchChange, areaBoxesHref, areasCountLabel, areasOfBranch, boxesCountLabel } from '../../resources/js/lib/branchAreas.js';

const areas = [
    { id: 1, name: 'المدارس', branch_ids: [10] },
    { id: 2, name: 'المدارس', branch_ids: [20] },
    { id: 3, name: 'الحاج', branch_ids: [10] },
    { id: 4, name: 'بلا فرع', branch_ids: [] },
];

test('the box form lists only the chosen branch’s areas, and none before a branch is chosen', () => {
    assert.deepEqual(areasOfBranch(areas, '10').map((area) => area.id), [1, 3]);
    assert.deepEqual(areasOfBranch(areas, 20).map((area) => area.id), [2]);
    assert.deepEqual(areasOfBranch(areas, ''), []);
    assert.deepEqual(areasOfBranch(areas, 99), []);
});

test('changing the branch keeps the area only when it is the new branch’s too', () => {
    assert.equal(areaAfterBranchChange(areas, '3', '10'), '3');
    assert.equal(areaAfterBranchChange(areas, '3', '20'), '');
    assert.equal(areaAfterBranchChange(areas, '', '20'), '');
});

test('counts and the link to an area’s boxes read as the page shows them', () => {
    assert.deepEqual([0, 1, 2, 5, 12].map(areasCountLabel), ['بلا مناطق', 'منطقة واحدة', 'منطقتان', '5 مناطق', '12 منطقة']);
    assert.deepEqual([0, 1, 2, 5, 12].map(boxesCountLabel), ['بلا طبلونات', 'طبلون واحد', 'طبلونان', '5 طبلونات', '12 طبلونًا']);
    assert.equal(areaBoxesHref(10, { id: 3 }), '/meter-boxes?filter%5Bbranch_id%5D=10&filter%5Bsub_area_id%5D=3');
});
