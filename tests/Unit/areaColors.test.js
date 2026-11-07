import { test } from 'node:test';
import assert from 'node:assert/strict';
import { areaHue } from '../../resources/js/lib/areaColors.js';

test('an area keeps its colour, and areas added one after another never share one', () => {
    assert.equal(areaHue(3, 'ابو ذر'), areaHue(3, 'ابو ذر'));
    assert.equal(new Set([1, 2, 3, 4, 5, 6, 7, 8].map((id) => areaHue(id))).size, 8);
    assert.equal(areaHue(null, 'ابو ذر'), areaHue(undefined, 'ابو ذر'));
});
