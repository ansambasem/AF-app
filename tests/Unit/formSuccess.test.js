import { before, beforeEach, test } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { rolldown } from 'rolldown';

let PaymentModal;
let Payments;
let CurrencyRatesSection;
let state;

before(async () => {
    const bundle = await rolldown({
        input: 'form-success-components', platform: 'node', transform: { jsx: 'react-jsx' },
        resolve: { alias: { '@': path.resolve(import.meta.dirname, '../../resources/js') } },
        plugins: [{
            name: 'form-success-interactions',
            resolveId(id) {
                if (id === 'form-success-components') return '\0entry';
                if (id === '@inertiajs/react') return '\0forms';
                if (id === 'react/jsx-runtime') return '\0jsx';
                if (id.startsWith('file:')) return { id, external: true };
                if (/^react(?:-dom)?(?:\/|$)/.test(id)) return { id: import.meta.resolve(id), external: true };
                if (/(?:Components[/\\]Modal|Layouts[/\\]SettingsLayout|[/\\]BanksSection)(?:\.jsx)?$/.test(id)) return '\0container';
                if (/Components[/\\]ConfirmDialog(?:\.jsx)?$/.test(id)) return '\0confirm';
            },
            load(id) {
                if (id === '\0entry') return `
                    export {default as PaymentModal} from '@/Pages/Subscriptions/PaymentModal';
                    export {default as Payments} from '@/Pages/Settings/Payments';
                    export {default as CurrencyRatesSection} from '@/Pages/Settings/CurrencyRatesSection';
                    export {state} from '@inertiajs/react';
                `;
                if (id === '\0container') return 'export default function Container({children}) { return children; }';
                if (id === '\0confirm') return `
                    import {state} from '@inertiajs/react';
                    export default function Confirm(props) { state.confirmations.push(props); return null; }
                `;
                if (id === '\0jsx') return `
                    import {jsx as originalJsx, jsxs as originalJsxs, Fragment} from ${JSON.stringify(import.meta.resolve('react/jsx-runtime'))};
                    import {state} from '@inertiajs/react';
                    export {Fragment};
                    function capture(factory, type, props, key) {
                        if (type === 'form') state.renderedForms.push(props);
                        return factory(type, props, key);
                    }
                    export const jsx = (type, props, key) => capture(originalJsx, type, props, key);
                    export const jsxs = (type, props, key) => capture(originalJsxs, type, props, key);
                `;
                if (id === '\0forms') return `
                    export const state = {forms: [], renderedForms: [], confirmations: [], requests: []};
                    export const Head = () => null;
                    export const Link = () => null;
                    export const router = {};
                    export const usePage = () => ({props: {auth: {user: {name: 'Collector'}}}});
                    export const useHttp = () => ({data: {}, errors: {}, processing: false});
                    export function useForm(initial) {
                        let defaults = {...initial};
                        const form = {
                            data: {...initial}, errors: {}, processing: false,
                            get isDirty() { return JSON.stringify(form.data) !== JSON.stringify(defaults); },
                            setData(key, value) { form.data = {...form.data, [key]: value}; },
                            setDefaults(key, value) { defaults = key === undefined ? {...form.data} : {...defaults, [key]: value}; },
                            resetAndClearErrors() { form.data = {...defaults}; form.errors = {}; },
                            post(url, options) { state.requests.push({method: 'post', url, options}); },
                            put(url, options) { state.requests.push({method: 'put', url, options}); },
                        };
                        state.forms.push(form);
                        return form;
                    }
                `;
            },
        }],
    });
    try {
        const { output } = await bundle.generate({ format: 'esm' });
        ({ PaymentModal, Payments, CurrencyRatesSection, state } = await import(`data:text/javascript;base64,${Buffer.from(output[0].code).toString('base64')}`));
    } finally { await bundle.close(); }
});

beforeEach(() => {
    for (const values of Object.values(state)) values.length = 0;
});

test('bank arrival is optional and resets to blank after a successful payment', () => {
    renderToStaticMarkup(createElement(PaymentModal, {
        show: true, onClose() {}, subscription: { id: 1, fullName: 'أحمد' }, balance: '100',
        paymentMethods: [{ value: 'bank_transfer', label: 'تحويل بنكي' }], transferBanks: [], senderBanks: [],
    }));
    const form = state.forms[0];
    assert.equal(form.data.bank_received_on, '');
    assert.equal('late_payment' in form.data, false);
    form.setData('bank_received_on', '2026-10-23');
    state.confirmations.find((dialog) => dialog.title === 'تأكيد تسجيل الدفعة؟').onConfirm();

    state.requests[0].options.onSuccess({ flash: {} });

    form.resetAndClearErrors();
    assert.equal(form.data.bank_received_on, '');
    assert.equal(form.data.amount, '');
    assert.equal('paid_on' in form.data, false);
    assert.equal('late_reason' in form.data, false);
});

test('saving a currency rate makes the saved rate the reset value without a success callback error', () => {
    renderToStaticMarkup(createElement(CurrencyRatesSection, {
        currencies: [{ code: 'USD', label: 'دولار', rate: '3', updateUrl: '/settings/currencies/USD' }],
    }));
    const form = state.forms[0];
    form.setData('rate', '3.5');
    state.renderedForms[0].onSubmit({ preventDefault() {} });

    state.requests[0].options.onSuccess();

    assert.equal(form.isDirty, false);
    form.setData('rate', '4');
    form.resetAndClearErrors();
    assert.equal(form.data.rate, '3.5');
});

test('saving the box week start updates the reset value without a success callback error', () => {
    renderToStaticMarkup(createElement(Payments, {
        boxPeriod: { week_starts_on: 6, branch: { id: 1, name: 'مخيم 2' }, canSelectBranch: false, updateUrl: '/settings/box-period' },
        canManagePaymentCatalog: false,
    }));
    const form = state.forms[0];
    form.setData('week_starts_on', 0);
    state.renderedForms[0].onSubmit({ preventDefault() {} });

    state.requests[0].options.onSuccess();

    assert.equal(form.isDirty, false);
    form.setData('week_starts_on', 1);
    form.resetAndClearErrors();
    assert.equal(form.data.week_starts_on, 0);
});
