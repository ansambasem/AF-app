import { test } from 'node:test';
import assert from 'node:assert/strict';
import { visitReturnsStatus } from '../../resources/js/lib/actionMessages.js';

test('a full visit returns the flashed status', () => {
    assert.equal(visitReturnsStatus({ only: [], except: [] }), true);
});

test('a partial reload that leaves the status out does not return it again', () => {
    assert.equal(visitReturnsStatus({ only: ['statement'], except: [] }), false);
    assert.equal(visitReturnsStatus({ only: [], except: ['status'] }), false);
    assert.equal(visitReturnsStatus({ only: ['statement', 'status'], except: [] }), true);
});
