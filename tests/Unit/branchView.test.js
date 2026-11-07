import { test } from 'node:test';
import assert from 'node:assert/strict';
import { branchViewUrl } from '../../resources/js/lib/branchView.js';

test('a banner branch change keeps the report period and removes stale branch selections', () => {
    const href = 'https://company.test/reports?branch=1&branch_id=1&filter[branch_id]=1&from=2026-10-01&to=2026-10-29&section=movements&page=4&statement=15#old-row';

    const url = new URL(branchViewUrl('2', href), 'https://company.test');

    assert.equal(url.pathname, '/reports');
    assert.equal(url.searchParams.get('branch'), '2');
    assert.equal(url.searchParams.get('from'), '2026-10-01');
    assert.equal(url.searchParams.get('to'), '2026-10-29');
    assert.equal(url.searchParams.get('section'), 'movements');
    for (const key of ['branch_id', 'filter[branch_id]', 'page', 'statement']) {
        assert.equal(url.searchParams.has(key), false);
    }
    assert.equal(url.hash, '');
});

test('all branches is sent explicitly so the previous branch cannot be restored', () => {
    const url = new URL(branchViewUrl('', 'https://company.test/subscriptions?filter[branch_id]=2'), 'https://company.test');

    assert.equal(url.searchParams.get('branch'), 'all');
    assert.equal(url.searchParams.has('filter[branch_id]'), false);
});

test('branch-specific filters are cleared while shared search and payment filters stay', () => {
    const url = new URL(branchViewUrl(3, 'https://company.test/ledger?filter[meter_box_id]=7&filter[area_id]=4&filter[user_id]=8&filter[type]=payment&search=Ali&sort=date'), 'https://company.test');

    assert.equal(url.searchParams.get('branch'), '3');
    assert.equal(url.searchParams.get('filter[type]'), 'payment');
    assert.equal(url.searchParams.get('search'), 'Ali');
    assert.equal(url.searchParams.get('sort'), 'date');
    for (const key of ['filter[meter_box_id]', 'filter[area_id]', 'filter[user_id]']) {
        assert.equal(url.searchParams.has(key), false);
    }
});
