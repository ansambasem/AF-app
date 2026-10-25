import { test } from 'node:test';
import assert from 'node:assert/strict';
import { LABEL_WIDTH, labelLeft, labelledDays } from '../../resources/js/lib/chartLabels.js';

const labelledIndexes = (count, width) => labelledDays(count, width).flatMap((labelled, index) => (labelled ? [index] : []));

test('every day is labelled when there is room for all of them', () => {
    assert.deepEqual(labelledDays(7, 7 * LABEL_WIDTH), Array(7).fill(true));
});

test('labels never overlap and never spill past the chart, at any width', () => {
    for (const count of [7, 14, 30, 31]) {
        for (const width of [260, 296, 320, 480, 640, 900]) {
            const lefts = labelledIndexes(count, width).map((index) => labelLeft(index, count, width));

            lefts.forEach((left, position) => {
                assert.ok(left >= 0 && left + LABEL_WIDTH <= width, `${count} days in ${width}px: label inside the chart`);
                if (position > 0) {
                    assert.ok(left - lefts[position - 1] >= LABEL_WIDTH - 0.5, `${count} days in ${width}px: labels apart`);
                }
            });
        }
    }
});

test('today is always labelled, and the labels are evenly spaced back from it', () => {
    const indexes = labelledIndexes(30, 296);
    const gaps = indexes.slice(1).map((index, i) => index - indexes[i]);

    assert.equal(indexes.at(-1), 29);
    assert.ok(indexes.length >= 2);
    assert.equal(new Set(gaps).size, 1);
});

test('a label is centred under its column unless that would spill past an edge', () => {
    assert.equal(labelLeft(3, 7, 7 * 100), 350 - LABEL_WIDTH / 2);
    assert.equal(labelLeft(0, 30, 300), 0);
    assert.equal(labelLeft(29, 30, 300), 300 - LABEL_WIDTH);
});
