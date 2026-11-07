import { before, test } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { rolldown } from 'rolldown';

let SubscriptionDetailsModal;
let AccountTab;

before(async () => {
    const bundle = await rolldown({
        input: path.resolve(import.meta.dirname, '../../resources/js/Pages/Subscriptions/SubscriptionDetailsModal.jsx'),
        platform: 'node',
        transform: { jsx: 'react-jsx' },
        resolve: { alias: { '@': path.resolve(import.meta.dirname, '../../resources/js') } },
        plugins: [{
            name: 'render-profile-without-browser-portal',
            resolveId(id) {
                if (/^react(?:-dom)?(?:\/|$)/.test(id) || id === '@inertiajs/react') {
                    return { id: import.meta.resolve(id), external: true };
                }
                if (id === '@/Components/Modal') {
                    return '\0inline-modal';
                }
                if (id.endsWith('.css')) {
                    return '\0stylesheet';
                }
            },
            load(id) {
                if (id === '\0inline-modal') {
                    return 'export default function Modal({ show, children }) { return show ? children : null; }';
                }
                if (id === '\0stylesheet') {
                    return '';
                }
            },
        }],
    });

    try {
        const { output } = await bundle.generate({ format: 'esm' });
        const component = await import(`data:text/javascript;base64,${Buffer.from(output[0].code).toString('base64')}`);
        SubscriptionDetailsModal = component.default;
        AccountTab = component.AccountTab;
    } finally {
        await bundle.close();
    }
});

function renderProfile(subscription = {}, options = {}) {
    return renderToStaticMarkup(createElement(SubscriptionDetailsModal, {
        subscription: {
            id: 1, display_name: 'اشتراك المتجر', full_name: 'صاحب المتجر', subscriber_number: '000041',
            account_number: '202600041', status: 'active', statusLabel: 'نشط', outstandingBalance: '0.00',
            initial_reading: 0, lastReading: 0, meterReadings: [], ...subscription,
        },
        onClose() {}, onEdit() {}, onEditPersonal() {}, onOpenStatement() {}, onLoadStatement() {}, onOpenReadings() {},
        ...options,
    }));
}

test('profile preserves separate personal and subscription identities and displays a zero reading', () => {
    const html = renderProfile({ phone: '0591111111', subscription_phone: '0562222222', contact_phone: '0562222222', meterBoxName: 'طبلون السوق أ', meterBoxNumber: '068754' });

    assert.match(html, /اشتراك المتجر/);
    assert.match(html, /صاحب المتجر/);
    assert.match(html, /000041/);
    assert.match(html, /202600041/);
    assert.match(html, /رقم الجوال<\/dt><dd[^>]*><span[^>]*>0591111111<\/span>/);
    assert.match(html, /جوال الاشتراك<\/dt><dd[^>]*><span[^>]*>0562222222<\/span>/);
    assert.match(html, /القراءة الأولى<\/dt><dd[^>]*><span[^>]*>0<\/span>/);
    assert.match(html, /اسم الطبلون<\/dt><dd[^>]*><span[^>]*>طبلون السوق أ<\/span>/);
    assert.match(html, /رقم الطبلون<\/dt><dd[^>]*><span[^>]*>068754<\/span>/);
    assert.match(html, /لا توجد قراءات مسجلة بعد/);
});

test('profile hides unavailable actions and disables navigation at list boundaries', () => {
    const html = renderProfile();

    assert.doesNotMatch(html, /تسجيل دفعة|تعديل البيانات|إرسال SMS/);
    assert.doesNotMatch(html, /سجل الرسائل/);
    assert.doesNotMatch(html, /aria-label="تسجيل قراءة"/);
    assert.match(html, /<button[^>]*disabled=""[^>]*aria-label="المشترك السابق"/);
    assert.match(html, /<button[^>]*disabled=""[^>]*aria-label="المشترك التالي"/);
});

test('profile offers permitted actions but disables a reading already recorded this week', () => {
    const html = renderProfile({
        canRecordPayment: true, canRecordReading: true,
        meterReadings: [{ weekStart: '2026-09-25', weekEnd: '2026-10-01', consumption: 0, discountAmount: 0, amountDue: 0 }],
    }, {
        canUpdate: true, onSendMessage() {}, onMessageHistory() {}, onNext() {}, readingWeekOptions: [{ value: '2026-09-25' }],
    });

    assert.match(html, /تسجيل دفعة/);
    assert.match(html, /تعديل البيانات/);
    assert.match(html, /aria-label="إرسال SMS"/);
    assert.match(html, /سجل الرسائل/);
    assert.match(html, /<button[^>]*disabled=""[^>]*aria-label="تم إدخال قراءة هذا الأسبوع"/);
    assert.doesNotMatch(html, /<button[^>]*disabled=""[^>]*aria-label="المشترك التالي"/);
});

test('profile shows actual credit and status without inventing disconnection reasons', () => {
    const html = renderProfile({ status: 'disconnected', statusLabel: 'مفصول', outstandingBalance: '-25.50' });

    // Credit is the balance with its minus sign, in the subscription's favour.
    assert.match(html, /-25\.50 ₪/);
    assert.match(html, /title="له — لصالح المشترك"/);
    assert.match(html, /الاشتراك مفصول/);
    assert.doesNotMatch(html, /تأخر بالدفع|14 يوم|377/);
});

test('profile escapes subscription-entered text', () => {
    const html = renderProfile({ display_name: '<script>alert(1)</script>', notes: '<img src=x onerror=alert(1)>' });

    assert.doesNotMatch(html, /<script>|<img src=x/);
    assert.match(html, /&lt;script&gt;/);
    assert.match(html, /&lt;img src=x onerror=alert\(1\)&gt;/);
});

test('account shows foreign currency amounts and server-provided running balances without recalculating them', () => {
    const html = renderToStaticMarkup(createElement(AccountTab, {
        statement: {
            summary: { balance: '60.00', charged: '100.00', paid: '40.00', discounted: '0.00' },
            entries: [
                { id: 1, date: '2026-09-01 12:00', type: 'subscription_fee', typeLabel: 'رسوم', description: 'رسوم الاشتراك', isCredit: false, amount: '100', currencyLabel: 'شيكل', balance: '100.00' },
                { id: 2, date: '2026-09-02 12:00', type: 'payment', typeLabel: 'دفعة', description: 'دفعة بالدولار', isCredit: true, amount: '10', currencyLabel: 'دولار', balance: '60.00' },
            ],
        },
    }));

    // An amount is always positive; only the balance after it carries a sign.
    assert.match(html, /<b class="sp-number">10 دولار<\/b>/);
    assert.match(html, />60 ₪</);
    assert.match(html, />100 ₪</);
    assert.ok(html.indexOf('دفعة بالدولار') < html.indexOf('رسوم الاشتراك'));
});

test('account distinguishes loading, failed loading and a genuinely empty account', () => {
    const loading = renderToStaticMarkup(createElement(AccountTab, { loading: true }));
    const failed = renderToStaticMarkup(createElement(AccountTab, { loading: false }));
    const empty = renderToStaticMarkup(createElement(AccountTab, {
        statement: { summary: { balance: 0, charged: 0, paid: 0, discounted: 0 }, entries: [] },
    }));

    assert.match(loading, /جارٍ تحميل الحساب/);
    assert.doesNotMatch(loading, /تعذر تحميل الحساب/);
    assert.match(failed, /إعادة المحاولة/);
    assert.match(empty, /لا توجد حركات لهذا النوع/);
    assert.doesNotMatch(empty, /تعذر تحميل الحساب/);
});

test('account opens compact, leaving out a cancelled line and its reversal, and offers every line', () => {
    const html = renderToStaticMarkup(createElement(AccountTab, {
        statement: {
            summary: { balance: '80.00', charged: '80.00', paid: '0.00', discounted: '0.00' },
            entries: [
                { id: 1, date: '2026-09-01 12:00', type: 'meter_reading', typeLabel: 'قراءة', description: 'القراءة الخاطئة', isCredit: false, amount: '100', currencyLabel: 'شيكل', balance: '100.00', cancellation: { wasCorrected: true, reasonLabel: 'خطأ', correctionId: 3 } },
                { id: 2, date: '2026-09-02 12:00', type: 'reversal', typeLabel: 'قيد عكسي', description: 'عكس القراءة', isCredit: true, amount: '100', currencyLabel: 'شيكل', balance: '0.00', reverses: { id: 1 } },
                { id: 3, date: '2026-09-02 12:00', type: 'meter_reading', typeLabel: 'قراءة', description: 'القراءة الصحيحة', isCredit: false, amount: '80', currencyLabel: 'شيكل', balance: '80.00', isCorrection: true },
            ],
        },
    }));

    assert.match(html, /القراءة الصحيحة/);
    assert.doesNotMatch(html, /القراءة الخاطئة|عكس القراءة/);
    assert.match(html, /صُحّحت · 2 حركات سابقة/);
    assert.match(html, /أُخفيت 2 حركة ملغاة/);
    assert.match(html, /aria-pressed="true"[^>]*>(?:(?!<\/button>)[\s\S])*عرض مختصر/);
    assert.match(html, /كل الحركات/);
});

test('opening the account ignores a previously saved full view preference', () => {
    const previous = globalThis.window;
    globalThis.window = { localStorage: { getItem: () => 'full' } };
    try {
        const html = renderToStaticMarkup(createElement(AccountTab, {
            statement: { summary: { balance: 0, charged: 0, paid: 0, discounted: 0 }, entries: [] },
        }));

        assert.match(html, /aria-pressed="true"[^>]*>(?:(?!<\/button>)[\s\S])*عرض مختصر/);
        assert.match(html, /aria-pressed="false"[^>]*>(?:(?!<\/button>)[\s\S])*كل الحركات/);
    } finally {
        globalThis.window = previous;
    }
});

test('account colours a weekly reading and its discount alike, and a line on its own not at all', () => {
    const html = renderToStaticMarkup(createElement(AccountTab, {
        statement: {
            summary: { balance: '63.40', charged: '93.40', paid: '0.00', discounted: '30.00' },
            entries: [
                { id: 1, date: '2026-09-01 12:00', type: 'meter_reading', typeLabel: 'قراءة', description: 'قراءة أسبوعية', isCredit: false, amount: '83.40', currencyLabel: 'شيكل', balance: '83.40', meterReadingId: 7 },
                { id: 2, date: '2026-09-01 12:00', type: 'reading_discount', typeLabel: 'خصم القراءة الأسبوعية', description: 'خصم', isCredit: true, amount: '30', currencyLabel: 'شيكل', balance: '53.40', meterReadingId: 7 },
                { id: 3, date: '2026-09-02 12:00', type: 'penalty', typeLabel: 'غرامة', description: 'غرامة', isCredit: false, amount: '10', currencyLabel: 'شيكل', balance: '63.40' },
            ],
        },
    }));

    assert.equal(html.match(/data-chain="1"/g)?.length, 2);
    assert.equal(html.match(/data-chain=/g)?.length, 2);
    assert.equal(html.match(/sp-chain-dot/g)?.length, 2);
});
