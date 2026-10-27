import { test } from 'node:test';
import assert from 'node:assert/strict';
import { loadReportLines } from '../../resources/js/lib/reportLines.js';

test('financial exports follow every cursor with the original branch, dates and kind', async () => {
    const requested = [];
    const progress = [];
    const signal = new AbortController().signal;
    const lines = await loadReportLines('branch=7&from=2026-10-01&to=2026-10-02&kind=payments', {
        signal,
        onProgress: (count) => progress.push(count),
        fetcher: async (url, options) => {
            requested.push(url);
            assert.equal(options.signal, signal);
            return { ok: true, json: async () => requested.length === 1 ? { lines: [{ id: 1 }, { id: 2 }], nextCursor: 'next+cursor=' } : { lines: [{ id: 3 }], nextCursor: null } };
        },
    });

    assert.deepEqual(lines.map((line) => line.id), [1, 2, 3]);
    assert.deepEqual(progress, [2, 3]);
    const params = new URL(requested[1], 'https://example.test').searchParams;
    assert.deepEqual(Object.fromEntries(params), { branch: '7', from: '2026-10-01', to: '2026-10-02', kind: 'payments', cursor: 'next+cursor=' });
});

test('a failed export page rejects instead of returning a partial financial report', async () => {
    let requests = 0;
    await assert.rejects(loadReportLines('', {
        fetcher: async () => ++requests === 1
            ? { ok: true, json: async () => ({ lines: [{ id: 1 }], nextCursor: 'next' }) }
            : { ok: false },
    }), /تعذّر تحميل/);
    assert.equal(requests, 2);
});
