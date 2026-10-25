import { before, test } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { rolldown } from 'rolldown';

let SplitPaymentEditor;
let AmendTransactionModal;
before(async () => {
    const bundle = await rolldown({
        input: 'split-editor-components', platform: 'node', transform: { jsx: 'react-jsx' },
        resolve: { alias: { '@': path.resolve(import.meta.dirname, '../../resources/js') } },
        plugins: [{ name: 'split-editor', resolveId(id) {
            if (id === 'split-editor-components') return '\0entry';
            if (id === '@inertiajs/react') return '\0inertia';
            if (id.startsWith('file:')) return { id, external: true };
            if (/^react(?:-dom)?(?:\/|$)/.test(id) || id === '@inertiajs/react') return { id: import.meta.resolve(id), external: true };
            if (/Components[/\\]Modal(?:\.jsx)?$/.test(id)) return '\0modal';
        }, load(id) {
            if (id === '\0entry') return "export {SplitPaymentEditor} from '@/Pages/Payments/EditSplitPaymentModal'; export {default as AmendTransactionModal} from '@/Pages/Subscriptions/AmendTransactionModal';";
            if (id === '\0inertia') return `export {useForm, useHttp} from ${JSON.stringify(import.meta.resolve('@inertiajs/react'))}; export const usePage = () => ({props: {paymentOptions: {today: '2026-10-24'}}});`;
            if (id === '\0modal') return 'export default function Modal({show, children}) { return show ? children : null; }';
        } }],
    });
    try {
        const { output } = await bundle.generate({ format: 'esm' });
        ({ SplitPaymentEditor, AmendTransactionModal } = await import(`data:text/javascript;base64,${Buffer.from(output[0].code).toString('base64')}`));
    } finally { await bundle.close(); }
});

const split = { id: 3, total_amount: '400.00', sender_name: 'أحمد', reference_number: 'REF-12', bank_name: 'بنك فلسطين', revision: 'a'.repeat(64), parts: [{ id: 1, subscriptionName: 'المشترك الأول', amount: '150' }, { id: 2, subscriptionName: '<img src=x onerror=alert(1)>', amount: '250' }] };
const banks = { transferBanks: ['بنك فلسطين'], senderBanks: ['بنك فلسطين'] };

test('the shared editor shows every share as text and allows reference editing without money inputs', () => {
    const html = renderToStaticMarkup(createElement(SplitPaymentEditor, { split, onClose() {}, ...banks }));
    assert.match(html, /تعديل الحوالة المقسّمة/);
    assert.match(html, /المشترك الأول/);
    assert.match(html, /&lt;img/);
    assert.doesNotMatch(html, /<img/);
    assert.match(html, /150/);
    assert.match(html, /250/);
    assert.doesNotMatch(html, /<(?:input|select)[^>]*name="(?:amount|total_amount|parts)/);
    const reference = html.match(/<input[^>]*name="reference_number"[^>]*>/)?.[0];
    assert.ok(reference);
    assert.doesNotMatch(reference, /disabled|readonly/i);
    assert.match(html, /مبلغ الحوالة ثابت/);
    assert.match(html, /تاريخ وصول الحوالة إلى البنك/);
    assert.match(html, /name="bank_received_on"/);
});

test('the existing edit action routes split payments to the shared dialog while ordinary references remain editable', () => {
    const props = { onClose() {}, subscription: { id: 1 }, balance: '0', entry: { id: 1, paymentMethod: 'bank_transfer', amount: '100', currencyLabel: 'شيكل', referenceNumber: 'REF-1' }, ...banks };
    const single = renderToStaticMarkup(createElement(AmendTransactionModal, props));
    assert.match(single, /name="reference_number"/);
    const shared = renderToStaticMarkup(createElement(AmendTransactionModal, { ...props, entry: { ...props.entry, splitPayment: { id: 3 } } }));
    assert.match(shared, /تعديل الحوالة المقسّمة/);
    assert.doesNotMatch(shared, /تعديل بيانات الحركة/);
});
