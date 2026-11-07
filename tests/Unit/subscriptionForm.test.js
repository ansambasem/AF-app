import { before, test } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { rolldown } from 'rolldown';

let SubscriptionForm;
let subscriptionFormData;

before(async () => {
    const bundle = await rolldown({
        input: path.resolve(import.meta.dirname, '../../resources/js/Pages/Subscriptions/SubscriptionForm.jsx'),
        platform: 'node',
        transform: { jsx: 'react-jsx' },
        resolve: { alias: { '@': path.resolve(import.meta.dirname, '../../resources/js') } },
        plugins: [{
            name: 'use-installed-react',
            resolveId(id) {
                if (/^react(?:-dom)?(?:\/|$)/.test(id)) {
                    return { id: import.meta.resolve(id), external: true };
                }
            },
        }],
    });

    try {
        const { output } = await bundle.generate({ format: 'esm' });
        const component = await import(`data:text/javascript;base64,${Buffer.from(output[0].code).toString('base64')}`);
        SubscriptionForm = component.default;
        subscriptionFormData = component.subscriptionFormData;
    } finally {
        await bundle.close();
    }
});

function renderForm(data, options = {}) {
    return renderToStaticMarkup(createElement(SubscriptionForm, {
        data,
        setData() {},
        errors: {},
        branches: [],
        tariffs: [],
        circuitBreakers: [],
        subAreas: [],
        canChooseBranch: false,
        canEditMinimumCharge: false,
        ...options,
    }));
}

test('the new subscription form renders its editable name and phone without crashing', () => {
    const html = renderForm(subscriptionFormData(null));

    assert.match(html, /id="full_name"/);
    assert.match(html, /id="phone"/);
});

test('adding a subscription prefills editable name and phone while retaining its shared identity', () => {
    const data = subscriptionFormData(null, {
        id: 12,
        full_name: 'Mohammed Hamdan',
        display_name: 'Mohammed Hamdan',
        national_id: '012345678',
        phone: '0591234567',
        contact_phone: '0561234567',
    });
    const html = renderForm(data, { sharedPersonalDetails: true });

    const nameInput = html.match(/<input(?=[^>]*id="subscription_name")[^>]*>/)[0];
    const phoneInput = html.match(/<input(?=[^>]*id="subscription_phone")[^>]*>/)[0];
    assert.match(nameInput, /value="Mohammed Hamdan"/);
    assert.match(phoneInput, /value="0561234567"/);
    assert.doesNotMatch(nameInput, /readonly/i);
    assert.doesNotMatch(phoneInput, /readonly/i);
    assert.match(html, /<input(?=[^>]*id="national_id")[^>]*readonly=""/i);
    assert.equal(data.source_subscription_id, 12);
    assert.equal(data.tariff_id, '');
});

test('editing a subscription prefills editable name and phone even before it has custom values', () => {
    const html = renderForm(subscriptionFormData({
        full_name: 'Mohammed Hamdan',
        subscription_name: null,
        phone: '0591234567',
        subscription_phone: null,
        status: 'active',
    }), { isEdit: true });

    assert.match(html, /<input(?=[^>]*id="subscription_name")[^>]*value="Mohammed Hamdan"/);
    assert.match(html, /<input(?=[^>]*id="subscription_phone")[^>]*value="0591234567"/);
    assert.doesNotMatch(html, /<input(?=[^>]*id="subscription_name")[^>]*readonly/i);
    assert.doesNotMatch(html, /<input(?=[^>]*id="subscription_phone")[^>]*readonly/i);
});

test('editing a named subscription renders its own name and phone in the preview', () => {
    const html = renderForm(subscriptionFormData({
        full_name: 'Mohammed Hamdan',
        subscription_name: 'Mohammed Hamdan house',
        phone: '0591234567',
        subscription_phone: '0567654321',
        status: 'active',
    }), { subscriptionCount: 2, isEdit: true });

    assert.match(html, /Mohammed Hamdan house/);
    assert.match(html, /0567654321/);
    assert.match(html, /id="subscription_name"/);
});

test('a subscription fee already charged to the account is shown locked, and one not yet charged can be edited', () => {
    const feeInput = (html) => html.match(/<input(?=[^>]*id="subscription_fee")[^>]*>/)[0];
    const charged = renderForm(subscriptionFormData({ full_name: 'A', status: 'active', subscription_fee: '150.00', subscription_fee_charged: true }), { isEdit: true });
    const notCharged = renderForm(subscriptionFormData({ full_name: 'A', status: 'active', subscription_fee: '150.00', subscription_fee_charged: false }), { isEdit: true });

    assert.match(feeInput(charged), /disabled=""/);
    assert.match(charged, /حُمّلت الرسوم على الحساب/);
    // Not charged yet: the field is open only once the switch to charge it is on.
    assert.doesNotMatch(charged, /تحميل رسوم اشتراك/);
    assert.match(notCharged, /تحميل رسوم اشتراك/);
});

test('the kilo price follows the tariff and is edited only through the button, for someone allowed to', () => {
    const tariffs = [{ id: 1, categoryLabel: 'تجاري', rate: '30.00' }];
    const priceInput = (html) => html.match(/<input(?=[^>]*id="tariff_rate")[^>]*>/)[0];
    const data = subscriptionFormData({ full_name: 'A', status: 'active', tariff_id: 1 });

    const allowed = renderForm(data, { tariffs, canEditKilowattPrice: true });
    const notAllowed = renderForm(data, { tariffs, canEditKilowattPrice: false });

    assert.equal(data.kilowatt_price, '');
    assert.match(priceInput(allowed), /readonly=""/i);
    assert.match(priceInput(allowed), /value="30"/);
    assert.match(allowed, /تعديل السعر/);
    assert.doesNotMatch(notAllowed, /تعديل السعر/);
});

test('a subscriber with a kilo price of their own sees it, marked, next to their tariff\'s', () => {
    const tariffs = [{ id: 1, categoryLabel: 'تجاري', rate: '30.00' }];
    const data = subscriptionFormData({ full_name: 'A', status: 'active', tariff_id: 1, kilowatt_price: '35.50' });
    const html = renderForm(data, { tariffs, canEditKilowattPrice: true, isEdit: true });

    assert.equal(data.kilowatt_price, 35.5);
    assert.match(html.match(/<input(?=[^>]*id="tariff_rate")[^>]*>/)[0], /value="35\.50"/);
    assert.match(html, /سعر خاص بهذا المشترك/);
});
