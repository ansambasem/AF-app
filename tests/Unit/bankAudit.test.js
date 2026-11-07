import { test } from 'node:test';
import assert from 'node:assert/strict';
import { bankAuditReasonChoice, splitAuditTotal } from '../../resources/js/lib/bankAudit.js';

test('choosing missing in the note box sets the bank amount to zero and supplies its clarification', () => {
    const data = { bank_amount: '223', reason: '' };
    const chosen = bankAuditReasonChoice(data, 'missing');
    assert.deepEqual(chosen, { bank_amount: '0', reason: 'غير موجودة في البنك' });
    assert.deepEqual(data, { bank_amount: '223', reason: '' });
});

test('other clarifications preserve the amount actually entered by the auditor', () => {
    assert.deepEqual(bankAuditReasonChoice({ bank_amount: '173', reason: 'قديم' }, 'partial'), { bank_amount: '173', reason: 'وصل جزء من المبلغ' });
    assert.deepEqual(bankAuditReasonChoice({ bank_amount: '173', reason: 'توضيح خاص' }, ''), { bank_amount: '173', reason: 'توضيح خاص' });
    assert.deepEqual(bankAuditReasonChoice({ bank_amount: '173', reason: 'توضيح خاص' }, 'invalid'), { bank_amount: '173', reason: 'توضيح خاص' });
});

test('a missing split transfer clears every share without changing its transaction identity', () => {
    const data = { bank_amount: '100', reason: '', parts: [{ transaction_id: 12, bank_amount: '40' }, { transaction_id: 13, bank_amount: '60' }] };
    assert.deepEqual(bankAuditReasonChoice(data, 'missing').parts, [{ transaction_id: 12, bank_amount: '0' }, { transaction_id: 13, bank_amount: '0' }]);
    assert.equal(data.parts[0].bank_amount, '40');
});

test('split totals reset allocations only for zero and exact matching and never guess a partial distribution', () => {
    const split = { total: '100.00', parts: [{ transaction_id: 12, recorded: '40.00' }, { transaction_id: 13, recorded: '60.00' }] };
    const data = { bank_amount: '0', parts: split.parts.map((part) => ({ transaction_id: part.transaction_id, bank_amount: '0' })) };
    assert.deepEqual(splitAuditTotal(data, split, '100').parts, [{ transaction_id: 12, bank_amount: '40.00' }, { transaction_id: 13, bank_amount: '60.00' }]);
    assert.equal(splitAuditTotal(data, split, '50').parts, data.parts);
    assert.equal(splitAuditTotal(data, split, '').parts, data.parts);
    assert.deepEqual(splitAuditTotal(data, split, '0').parts, data.parts);
});
