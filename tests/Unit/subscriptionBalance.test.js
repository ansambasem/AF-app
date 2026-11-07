import { test } from 'node:test';
import assert from 'node:assert/strict';
import { balanceFilterLabel, subscriptionFilterKey } from '../../resources/js/lib/subscriptionBalance.js';
import { openQuickMessage, QUICK_MESSAGE_EVENT } from '../../resources/js/lib/quickMessage.js';

test('balance descriptions express debt and credit as positive amounts', () => {
    assert.equal(balanceFilterLabel({ balance_side: 'debit', balance_operator: 'gt', balance_amount: '20' }), 'عليه للشركة أكثر من 20 شيكل');
    assert.equal(balanceFilterLabel({ balance_side: 'credit', balance_operator: 'lt', balance_amount: '20' }), 'له عند الشركة أقل من 20 شيكل');
    assert.equal(balanceFilterLabel({ balance_side: 'credit', balance_operator: 'between', balance_amount: '10', balance_to: '20' }), 'له عند الشركة بين 10 و20 شيكل');
});

test('an unset balance filter does not describe an active selection', () => {
    assert.equal(balanceFilterLabel({}), '');
    assert.equal(balanceFilterLabel({ balance_side: 'debit', balance_operator: 'gt', balance_amount: '' }), '');
});

test('URL normalization does not leave bulk actions waiting after results have loaded', () => {
    assert.equal(subscriptionFilterKey({ balance_amount: '20', balance_to: '', branch_id: 2 }), subscriptionFilterKey({ branch_id: '2', balance_to: null, balance_amount: '20' }));
    assert.notEqual(subscriptionFilterKey({ balance_amount: '20' }), subscriptionFilterKey({ balance_amount: '30' }));
});

test('quick messages carry the complete list selection without resolving or sending messages', () => {
    const previousWindow = globalThis.window;
    let event;
    globalThis.window = { dispatchEvent: (next) => { event = next; } };
    const selection = { all: true, search: 'أحمد', filter: { balance_side: 'debit', balance_operator: 'gt', balance_amount: '20', tariff_id: '2' } };
    try {
        openQuickMessage([], selection);
        assert.equal(event.type, QUICK_MESSAGE_EVENT);
        assert.deepEqual(event.detail, { subscriptionIds: [], selection });
    } finally {
        globalThis.window = previousWindow;
    }
});
