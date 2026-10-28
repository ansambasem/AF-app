// A company's whole workflow, clicked through the real website in Chromium (see run.sh).
// Each step saves a screenshot; a failing step saves one of the page as it failed and stops the run.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');

const BASE_URL = process.env.BASE_URL;
const OUT_DIR = process.env.OUT_DIR;
const PASSWORD = 'Strong-Pass-2026';
const steps = [];
const pageErrors = [];
let browser;
let shot = 0;

function step(name, run) {
    steps.push({ name, run });
}

/** A signed-in browser of its own for one person, as on their own computer. */
async function signIn(username, viewport = { width: 1440, height: 900 }) {
    const context = await browser.newContext({ baseURL: BASE_URL, viewport, locale: 'ar', hasTouch: viewport.width < 600, isMobile: viewport.width < 600 });
    const page = await context.newPage();
    page.on('pageerror', (error) => {
        pageErrors.push(`${username}: ${error.message}`);
        console.log(`  ! page error for ${username}: ${error.message}`);
    });
    page.on('console', (message) => {
        if (message.type() === 'error') console.log(`  ! browser error for ${username}: ${message.text()}`);
    });
    await page.goto('/login');
    try {
        await page.locator('#username').fill(username);
    } catch (error) {
        await snap(page, 'login-failed');
        throw error;
    }
    await page.locator('#password').fill(PASSWORD);
    assert.equal(await page.locator('#username').evaluate((field) => getComputedStyle(field).textAlign), 'right');
    assert.equal(await page.locator('#password').evaluate((field) => getComputedStyle(field).textAlign), 'right');
    await page.getByRole('button', { name: 'تسجيل الدخول' }).click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
    return page;
}

async function snap(page, name) {
    shot += 1;
    await page.screenshot({ path: `${OUT_DIR}/${String(shot).padStart(2, '0')}-${name}.png`, fullPage: true });
}

/** Adds a name in one column of the governorates page (المحافظات، المناطق، منطقة 2). */
async function addInColumn(page, columnTitle, noun, name) {
    const column = page.locator('section, div').filter({ has: page.getByRole('heading', { name: columnTitle, exact: true }) }).last();
    await column.getByRole('button', { name: 'إضافة' }).click();
    await page.getByLabel(`اسم ${noun}`).fill(name);
    await page.getByRole('button', { name: 'حفظ', exact: true }).click();
    await page.getByText(name, { exact: true }).first().waitFor();
}


/** Opens a page's "add" window, fills its fields by their labels and saves it. */
async function addThroughWindow(page, path, addButton, fields, { submit = 'حفظ' } = {}) {
    await page.goto(path);
    await page.getByRole('button', { name: addButton }).first().click();
    const dialog = page.getByRole('dialog').last();
    await dialog.waitFor();
    await fillFields(dialog, fields);
    await dialog.getByRole('button', { name: submit, exact: true }).click();
    await confirmIfAsked(page);
    await dialog.waitFor({ state: 'hidden' });
}

/** Answers "yes" to the question a form asks before saving (نعم، أضف / نعم، احفظ …), when it asks one. */
async function confirmIfAsked(page) {
    const yes = page.getByRole('button', { name: /^نعم/ }).last();
    try {
        await yes.waitFor({ timeout: 2000 });
    } catch {
        return;
    }
    await yes.click();
}

/** Waits until a field shows the value the server saved. */
async function expectValue(field, value) {
    for (let tries = 0; tries < 50 && (await field.inputValue()) !== value; tries++) {
        await field.page().waitForTimeout(100);
    }
    assert.equal(await field.inputValue(), value);
}

/** A field's label as shown, with or without the asterisk of a required field. */
function labelled(label) {
    return new RegExp(`^\\s*${label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\s*\\*?\\s*$`);
}

/** Fills fields by label (skipping those given as null): text, a choice from a list (`{ select: value }`), a chip (`{ chip }`), a switch (`{ switch: bool }`) or a switch (`{ check: bool }`). */
async function fillFields(scope, fields) {
    for (const [label, value] of Object.entries(fields)) {
        if (value === null) continue;
        const field = scope.getByLabel(labelled(label)).first();
        if (value && typeof value === 'object' && 'select' in value) {
            if ((await field.evaluate((element) => element.tagName)) === 'SELECT') {
                await field.selectOption(value.select);
            } else {
                await field.click();
                if (value.search) {
                    const search = scope.page().getByPlaceholder('بحث...', { exact: true });
                    await search.fill(value.search);
                    const popup = search.locator('..').locator('..');
                    assert.equal(await popup.locator('ul button').count(), 2, 'search offers only the matching sub-area and the empty choice');
                }
                await scope.page().locator('ul button').filter({ hasText: value.select }).first().click();
            }
        } else if (value && typeof value === 'object' && 'chip' in value) {
            await scope.getByRole('radiogroup', { name: label }).getByRole('radio', { name: value.chip }).click();
        } else if (value && typeof value === 'object' && 'switch' in value) {
            const toggle = scope.getByRole('switch', { name: label });
            if (((await toggle.getAttribute('aria-checked')) === 'true') !== value.switch) await toggle.click();
        } else if (value && typeof value === 'object' && 'check' in value) {
            if ((await field.isChecked()) !== value.check) await field.click();
        } else {
            await field.fill(String(value));
        }
    }
}

const people = {};

step('the Super Admin signs in', async () => {
    people.owner = await signIn('owner');
    await snap(people.owner, 'owner-dashboard');
});

step('governorate, area and two sub-areas', async () => {
    const page = people.owner;
    await page.goto('/governorates');
    await addInColumn(page, 'المحافظات', 'المحافظة', 'المحافظة الوسطى');
    await page.getByText('المحافظة الوسطى', { exact: true }).first().click();
    await addInColumn(page, 'المناطق', 'المنطقة', 'مخيم النصيرات');
    await page.getByText('مخيم النصيرات', { exact: true }).first().click();
    await addInColumn(page, 'منطقة 2', 'منطقة 2', 'البلوك أ');
    await addInColumn(page, 'منطقة 2', 'منطقة 2', 'البلوك ب');
    await snap(page, 'locations');
});

step('banks and the dollar rate', async () => {
    const page = people.owner;
    await addThroughWindow(page, '/settings/payments', 'إضافة بنك أو محفظة', { 'الاسم': 'بنك الاستثمار الفلسطيني' });
    await page.getByText('بنك الاستثمار الفلسطيني').first().waitFor();
    const dollar = page.locator('form').filter({ has: page.getByRole('heading', { name: 'دولار' }) });
    await dollar.getByLabel(labelled('سعر الصرف بالشيكل')).fill('3.7');
    await dollar.getByRole('button', { name: 'حفظ السعر' }).click();
    await expectValue(dollar.getByLabel(labelled('سعر الصرف بالشيكل')), '3.7000');
    await snap(page, 'banks-and-rates');
});

step('tariffs and a circuit breaker', async () => {
    const page = people.owner;
    await addThroughWindow(page, '/tariffs', 'تعرفة جديدة', { 'الفئة': { select: 'منزلي' }, 'السعر (شيكل)': '0.50' });
    await addThroughWindow(page, '/tariffs', 'تعرفة جديدة', { 'الفئة': { select: 'تجاري' }, 'السعر (شيكل)': '0.80' });
    await snap(page, 'tariffs');
    await addThroughWindow(page, '/circuit-breakers', 'قاطع جديد', { 'الأمبير': '10', 'الحد الأدنى للدفع (شيكل)': '10' });
    await page.getByRole('cell', { name: '10' }).first().waitFor();
    await snap(page, 'circuit-breakers');
});

step('the branch and its three tablons', async () => {
    const page = people.owner;
    await addThroughWindow(page, '/branches', 'فرع جديد', {
        'الاسم': 'فرع النصيرات', 'الهاتف': '0599000000', 'المحافظة': { select: 'المحافظة الوسطى' }, 'المنطقة': { select: 'مخيم النصيرات' },
    });
    await page.getByText('فرع النصيرات').first().waitFor();
    for (const [name, suffix, number, block] of [['المدارس', '1', 'T-101', 'البلوك أ'], ['المدارس', '2', 'T-102', 'البلوك ب'], ['السوق', '', 'T-103', 'البلوك أ']]) {
        await addThroughWindow(page, '/meter-boxes', 'طبلون جديد', {
            'اسم الطبلون': name, 'لاحقة (اختياري)': suffix, 'رقم الطبلون': number,
            'المحافظة': { select: 'المحافظة الوسطى' }, 'المنطقة': { select: 'مخيم النصيرات' }, 'الفرع': { select: 'فرع النصيرات' }, 'منطقة 2': { select: block, search: block },
        });
    }
    for (const number of ['T-101', 'T-102', 'T-103']) {
        await page.getByText(number).first().waitFor();
    }
    await snap(page, 'branch-and-tablons');
});

const staff = {
    manager: { name: 'مدير الفرع', username: 'branch-manager' },
    collector: { name: 'محمد الجابي', username: 'collector-1' },
    reader: { name: 'قارئ العدادات', username: 'reader-1' },
    auditor: { name: 'المدقق المالي', username: 'auditor-1' },
};

step('the staff accounts', async () => {
    const page = people.owner;
    for (const { name, username } of Object.values(staff)) {
        await addThroughWindow(page, '/users', 'مستخدم جديد', {
            'الاسم': name, 'اسم المستخدم': username, 'كلمة المرور': PASSWORD, 'تأكيد كلمة المرور': PASSWORD, 'الفرع': { select: 'فرع النصيرات' },
        });
    }
    for (const { username } of Object.values(staff)) {
        await page.getByText(username).first().waitFor();
    }
    await snap(page, 'staff');
});

/** Ticks one person's permissions on the permissions page and saves them through its review window. */
async function grant(page, person, labels) {
    await page.goto('/settings/permissions');
    await page.getByRole('complementary', { name: 'الموظفون' }).getByText(person, { exact: true }).click();
    await page.getByRole('region', { name: `صلاحيات ${person}` }).waitFor();
    await page.getByRole('button', { name: 'فتح الكل' }).click();
    for (const label of labels) {
        const control = page.locator(`[role=checkbox][aria-label="${label}"], [role=switch][aria-label="${label}"]`).first();
        if ((await control.getAttribute('aria-checked')) !== 'true') await control.click();
        assert.equal(await control.getAttribute('aria-checked'), 'true', `${person}: ${label}`);
    }
    await page.getByRole('button', { name: 'مراجعة وحفظ' }).click();
    const review = page.getByRole('dialog').last();
    const acknowledge = review.getByRole('checkbox');
    if (await acknowledge.count()) await acknowledge.check();
    await review.getByRole('button', { name: 'تأكيد وحفظ الصلاحيات' }).click();
    await review.waitFor({ state: 'hidden' });
    await snap(page, `permissions-${person}`);
}

step('each person gets the permissions of their work', async () => {
    const page = people.owner;
    await grant(page, staff.manager.name, [
        'المشتركون: عرض المشتركين', 'المشتركون: إضافة المشتركين', 'المشتركون: تعديل المشتركين', 'الطبلونات: عرض الطبلونات',
        'القراءات: عرض القراءات', 'القراءات: تسجيل القراءات', 'القراءات: تصحيح القراءة الأسبوعية', 'القراءات: اعتماد القراءات',
        'توزيع القراءات: عرض توزيع القراءات', 'توزيع القراءات: توزيع الطبلونات على القرّاء', 'توزيع القراءات: طباعة الكشوف الورقية', 'توزيع القراءات: اعتماد توزيع القراءات',
        'الدفعات والحركات المالية: عرض السجل المالي', 'الدفعات والحركات المالية: إلغاء الحركات المالية',
        'الدفعات والحركات المالية: إغلاق صناديق المحصّلين', 'الدفعات والحركات المالية: إدارة واعتماد صناديق الفرع',
    ]);
    await grant(page, staff.collector.name, [
        'المشتركون: عرض المشتركين', 'الدفعات والحركات المالية: إضافة دفعة', 'الدفعات والحركات المالية: الدفع نقدًا', 'الدفعات والحركات المالية: الدفع بتحويل بنكي',
    ]);
    await grant(page, staff.reader.name, ['المشتركون: عرض المشتركين', 'القراءات: عرض القراءات', 'القراءات: تسجيل القراءات']);
    await grant(page, staff.auditor.name, [
        'تفعيل التدقيق المالي', 'عرض المشتركين وكشوف حساباتهم', 'عرض السجل المالي', 'مراجعة الحوالات وتسجيل مبلغ البنك', 'اعتماد تدقيق الصندوق', 'اعتماد تدقيق الفترة كاملة',
    ]);
});

step('readings are opened for entry today, whatever day the run is on', async () => {
    const page = people.owner;
    await page.goto('/settings/reading-schedule');
    await page.getByRole('group', { name: 'طريقة الفتح' }).getByRole('button', { name: 'مفتوح الآن' }).click();
    await page.getByRole('button', { name: 'حفظ', exact: true }).click();
    await confirmIfAsked(page);
    await page.getByText('لديك تغييرات غير محفوظة').waitFor({ state: 'hidden' });
    await snap(page, 'reading-schedule');
});

step('the branch manager registers subscribers on the tablons, one an old record without an ID or mobile', async () => {
    people.manager = await signIn(staff.manager.username);
    const page = people.manager;
    const subscriber = (fields) => addThroughWindow(page, '/subscriptions', 'مشترك جديد', {
        'الحالة': { chip: 'نشط' }, 'نوع الاشتراك': { select: 'منزلي' }, 'القاطع': { select: '10' }, 'القراءة السابقة': '1000',
        'تحميل رسوم اشتراك': { switch: true }, 'رسوم الاشتراك': '300', ...fields,
    });
    await subscriber({ 'الاسم': 'سالم عبد الله', 'رقم الهوية': '401234567', 'رقم الجوال': '0599123456', 'الطبلون (الاسم والرقم)': { select: 'T-101' } });
    await subscriber({
        'الاسم': 'خالد يوسف', 'رقم الهوية': '402345678', 'رقم الجوال': '0599765432', 'نوع الاشتراك': { select: 'تجاري' },
        'الطبلون (الاسم والرقم)': { select: 'T-103' }, 'القراءة السابقة': '500', 'رسوم الاشتراك': '400',
    });
    // An old record brought in without an identity or mobile number.
    await subscriber({ 'الاسم': 'حاج قديم بلا هوية', 'الطبلون (الاسم والرقم)': { select: 'T-102' }, 'تحميل رسوم اشتراك': { switch: false }, 'رسوم الاشتراك': null });
    await page.goto('/subscriptions');
    for (const account of ['202600001', '202600002', '202600003']) {
        await page.getByText(account).first().waitFor();
    }
    await snap(page, 'subscribers');
});

step('the reader enters this week\'s readings', async () => {
    people.reader = await signIn(staff.reader.username);
    const page = people.reader;
    await page.goto('/meter-readings');
    for (const [name, reading] of [['سالم عبد الله', '1080'], ['خالد يوسف', '620']]) {
        const field = page.getByLabel(`القراءة الحالية لـ ${name}`);
        await field.fill(reading);
        await field.press('Enter');
        await page.getByRole('row').filter({ hasText: name }).getByText('بانتظار الاعتماد').waitFor();
    }
    await snap(page, 'readings-entered');
    assert.equal(await page.getByRole('button', { name: /^اعتماد قراءة/ }).count(), 0, 'the reader cannot approve');
});

step('the branch manager approves the readings and the bills reach the balances', async () => {
    const page = people.manager;
    await page.goto('/meter-readings');
    for (const name of ['سالم عبد الله', 'خالد يوسف']) {
        await page.getByRole('button', { name: `اعتماد قراءة ${name}` }).click();
        await page.getByRole('button', { name: 'اعتماد القراءة', exact: true }).click();
        await page.getByRole('row').filter({ hasText: name }).getByText('معتمدة').waitFor();
    }
    await snap(page, 'readings-approved');
    await page.goto('/subscriptions');
    await page.getByRole('row').filter({ hasText: 'سالم عبد الله' }).getByText('340').waitFor();
    await page.getByRole('row').filter({ hasText: 'خالد يوسف' }).getByText('496').waitFor();
});

/** Takes a payment from the payments page the way the collector does, and closes the receipt. */
async function takePayment(page, subscriber, { amount, method, currency, bank, reference, credited }) {
    await page.goto('/payments');
    await page.getByRole('row').filter({ hasText: subscriber }).getByRole('button', { name: 'تسجيل دفعة' }).click();
    const window = page.getByRole('dialog').last();
    await window.locator('#amount').fill(amount);
    if (currency) {
        await window.locator('#payment-currency').click();
        await page.locator('ul button').filter({ hasText: currency }).first().click();
    }
    await window.getByText(method === 'cash' ? 'نقد' : 'تحويل بنكي أو محفظة', { exact: true }).click();
    if (method === 'transfer') {
        await window.getByText(bank, { exact: true }).first().click();
        await window.locator('#reference_number').fill(reference);
    }
    await snap(page, `payment-${subscriber}-${amount}`);
    await window.getByRole('button', { name: /^تسجيل / }).click();
    await confirmIfAsked(page);
    const receipt = page.getByRole('dialog', { name: 'سُجّلت الدفعة' });
    await receipt.waitFor();
    if (credited) await receipt.getByText(`${credited} شيكل من ${subscriber}`).waitFor();
    await snap(page, `receipt-${subscriber}-${amount}`);
    await receipt.getByRole('button', { name: 'تم', exact: true }).click();
    await receipt.waitFor({ state: 'hidden' });
}

step('the collector takes cash, a bank transfer and dollars on the website', async () => {
    people.collector = await signIn(staff.collector.username);
    const page = people.collector;
    await takePayment(page, 'سالم عبد الله', { amount: '100', method: 'cash', credited: '100' });
    await takePayment(page, 'خالد يوسف', { amount: '200', method: 'transfer', bank: 'بنك فلسطين', reference: 'WEB-TRF-1' });
    await takePayment(page, 'خالد يوسف', { amount: '20', method: 'cash', currency: 'USD', credited: '74' });
    await page.goto('/payments');
    await page.getByRole('row').filter({ hasText: 'سالم عبد الله' }).getByText('240').waitFor();
    await page.getByRole('row').filter({ hasText: 'خالد يوسف' }).getByText('222').waitFor();
    await snap(page, 'balances-after-payments');
});

step('the branch manager reviews and closes the collector\'s cash and transfer boxes', async () => {
    const page = people.manager;
    await page.goto('/payments/cash-boxes?place=open');
    await page.getByRole('button').filter({ hasText: 'الصندوق النقدي' }).filter({ hasText: '174' }).waitFor();
    await page.getByRole('button').filter({ hasText: 'صندوق الحوالات البنكية' }).filter({ hasText: '200' }).waitFor();
    for (let closed = 0; closed < 2; closed++) {
        await page.getByRole('button', { name: 'مراجعة وإغلاق' }).first().click();
        const window = page.getByRole('dialog').last();
        await window.getByRole('button', { name: 'تأكيد إغلاق الصندوق' }).click();
        await confirmIfAsked(page);
        await window.waitFor({ state: 'hidden' });
    }
    await page.getByRole('link', { name: /الصناديق المفتوحة\s*0/ }).waitFor();
    await page.getByRole('link', { name: /الصناديق المغلقة\s*2/ }).waitFor();
    await snap(page, 'boxes-closed');
});

step('the auditor checks the transfer against the bank and approves the period', async () => {
    people.auditor = await signIn(staff.auditor.username);
    const page = people.auditor;
    assert.match(page.url(), /\/payments\/cash-boxes\?place=closed/);
    await page.getByRole('row').filter({ hasText: 'حوالات بنكية' }).getByRole('button', { name: 'عرض الصندوق' }).click();
    await page.getByRole('dialog').last().getByRole('button', { name: 'تعديل الحوالة' }).click();
    const review = page.getByRole('dialog').last();
    await review.getByLabel(labelled('المبلغ الذي وصل البنك (شيكل)')).fill('180');
    await review.getByLabel(labelled('سبب الفرق')).fill('وصل البنك أقل من المسجل');
    await review.getByRole('button', { name: 'حفظ مراجعة الحوالة' }).click();
    await review.getByRole('button', { name: 'حفظ مراجعة الحوالة' }).waitFor({ state: 'hidden' });
    await snap(page, 'transfer-reviewed');
    await page.goto('/payments/cash-boxes?place=closed');

    await page.getByRole('button', { name: 'اعتماد الفترة كاملة' }).click();
    const approval = page.getByRole('alertdialog', { name: 'اعتماد تدقيق الفترة كاملة؟' });
    await approval.getByText('تحميل على المشتركين').waitFor();
    assert.match((await approval.innerText()).replace(/\s+/g, ' '), /حوالات فيها فروق 1 .*تحميل على المشتركين 20 شيكل/);
    await approval.getByRole('checkbox').check();
    const hold = approval.getByRole('button', { name: /اعتماد التدقيق/ });
    await hold.hover();
    await page.mouse.down();
    await page.waitForTimeout(3600);
    await page.mouse.up();
    await approval.waitFor({ state: 'hidden' });
    await snap(page, 'period-approved');
});

step('the subscribers\' balances carry the audit and the books agree', async () => {
    const page = people.manager;
    await page.goto('/subscriptions');
    await page.getByRole('row').filter({ hasText: 'سالم عبد الله' }).getByText('240').waitFor();
    await page.getByRole('row').filter({ hasText: 'خالد يوسف' }).getByText('242').waitFor();
    await snap(page, 'final-balances');
});

step('a collector on a phone-sized screen takes a payment without the page spilling sideways', async () => {
    const page = await signIn(staff.collector.username, { width: 390, height: 844 });
    people.phone = page;
    for (const path of ['/dashboard', '/payments']) {
        await page.goto(path);
        await page.waitForLoadState('networkidle');
        const [scrollWidth, width] = await page.evaluate(() => [document.documentElement.scrollWidth, window.innerWidth]);
        assert.ok(scrollWidth <= width + 1, `${path} is ${scrollWidth}px wide on a ${width}px phone`);
    }
    await takePayment(page, 'سالم عبد الله', { amount: '10', method: 'cash', credited: '10' });
    await page.goto('/payments');
    await page.getByText('230').first().waitFor();
    await snap(page, 'phone-payments');
});

step('subscribers and their account statements export formatted Excel, with readable balances and user names', async () => {
    const page = people.manager;
    await page.goto('/subscriptions');
    const opened = page.context().waitForEvent('page');
    await page.locator('.data-table-toolbar [data-excel-button]').click();
    const excel = await opened;
    const tableStudio = excel.getByRole('dialog', { name: /تصدير.*إلى Excel/ });
    await tableStudio.getByText('سالم عبد الله', { exact: false }).first().waitFor();
    const downloadReady = excel.waitForEvent('download');
    await tableStudio.getByRole('button', { name: 'تنزيل ملف Excel' }).click();
    const listFile = await downloadReady;
    const listWorkbook = new (require('exceljs').Workbook)();
    await listWorkbook.xlsx.readFile(await listFile.path());
    assert.ok(listWorkbook.worksheets[0].rowCount >= 3);
    await excel.close();

    await page.getByRole('row').filter({ hasText: 'سالم عبد الله' }).getByRole('button', { name: 'عرض', exact: true }).click();
    const statement = page.locator('.account-statement');
    await statement.locator('tbody tr').first().waitFor();
    await statement.locator('td[data-label="اسم المستخدم"]').first().evaluate((cell) => { cell.textContent = 'Super Admin'; });
    const balance = statement.locator('td[data-label="الرصيد (شيكل)"]').first();
    await balance.locator(':scope > span').first().evaluate((element) => { element.textContent = '100,315.50'; });
    const geometry = await balance.evaluate((cell) => {
        const text = cell.firstElementChild.getBoundingClientRect();
        const box = cell.getBoundingClientRect();
        return { left: box.left, right: box.right, textLeft: text.left, textRight: text.right, width: box.width };
    });
    assert.ok(geometry.width >= 140, JSON.stringify(geometry));
    assert.ok(geometry.textLeft >= geometry.left && geometry.textRight <= geometry.right, JSON.stringify(geometry));
    await statement.locator('.data-table-container').evaluate((container) => { container.scrollLeft = -container.scrollWidth; });
    await snap(page, 'statement-wide-balances');
    await statement.getByRole('button', { name: 'Excel', exact: true }).click();
    const studio = page.getByRole('dialog', { name: /تصدير كشف حساب.*إلى Excel/ });
    await studio.getByRole('tab', { name: 'كشف الحساب', exact: true }).waitFor();
    const statementDownload = page.waitForEvent('download');
    await studio.getByRole('button', { name: 'تنزيل ملف Excel' }).click();
    const file = await statementDownload;
    const workbook = new (require('exceljs').Workbook)();
    await workbook.xlsx.readFile(await file.path());
    assert.ok(workbook.getWorksheet('كشف الحساب'));
    assert.ok(workbook.getWorksheet('ملخص الحساب الكامل'));
    await page.goto('/subscriptions');
});

step('financial reports export Excel, retain rejected template names, and print summaries or filtered lines', async () => {
    const page = people.owner;
    await page.goto('/reports?branch=all');
    await page.getByRole('region', { name: 'المقارنة بالفترة السابقة' }).waitFor();
    await snap(page, 'financial-reports-comparison');
    await page.getByRole('button', { name: 'تصدير Excel' }).click();
    const studio = page.getByRole('dialog', { name: 'تصدير التقرير إلى Excel' });
    const templateName = studio.getByLabel('اسم قالب الشركة', { exact: true });
    await templateName.fill('تقرير الشركة التجريبي');
    await studio.getByRole('button', { name: 'حفظ للشركة', exact: true }).click();
    await studio.getByRole('button', { name: 'تقرير الشركة التجريبي', exact: false }).first().waitFor();
    await templateName.fill('تقرير الشركة التجريبي');
    const rejected = page.waitForResponse((response) => response.url().endsWith('/excel-templates') && response.request().method() === 'POST' && response.status() === 422);
    await studio.getByRole('button', { name: 'حفظ للشركة', exact: true }).click();
    await rejected;
    await studio.getByText('يوجد قالب بهذا الاسم، اختر اسمًا آخر.', { exact: true }).waitFor();
    assert.equal(await templateName.inputValue(), 'تقرير الشركة التجريبي');
    const downloadReady = page.waitForEvent('download');
    await studio.getByRole('button', { name: 'تنزيل ملف Excel' }).click();
    const download = await downloadReady;
    const workbook = new (require('exceljs').Workbook)();
    await workbook.xlsx.readFile(await download.path());
    assert.ok(workbook.worksheets.some((sheet) => sheet.name === 'الحركات'));
    assert.ok(workbook.worksheets.some((sheet) => sheet.name === 'معايير التقرير'));
    await studio.waitFor({ state: 'hidden' });
    for (const [view, label] of [['summary', 'البند'], ['lines', 'السند']]) {
        await page.getByLabel('محتوى الطباعة').selectOption(view);
        const opened = page.context().waitForEvent('page');
        await page.getByRole('link', { name: 'طباعة وتخصيص / PDF' }).click();
        const print = await opened;
        await print.getByRole('article', { name: 'معاينة الورقة المطبوعة' }).waitFor();
        assert.ok(await print.getByRole('article', { name: 'معاينة الورقة المطبوعة' }).getByText(label, { exact: true }).count());
        await print.getByLabel('حفظ التصميم كقالب جديد').waitFor();
        await print.emulateMedia({ media: 'print' });
        assert.equal(await print.locator('.report-print').count(), 0);
        assert.equal(await print.getByRole('article', { name: 'معاينة الورقة المطبوعة' }).isVisible(), true);
        await snap(print, `financial-print-${view}`);
        await print.close();
    }
});

step('staff are kept out of the work that is not theirs', async () => {
    // One device per account: signing in on the phone ended the collector's session on the computer.
    await people.collector.goto('/payments');
    assert.match(people.collector.url(), /\/login/);
    for (const [person, path] of [['phone', '/governorates'], ['phone', '/settings/permissions'], ['reader', '/payments/cash-boxes'], ['reader', '/users'], ['manager', '/settings/payments']]) {
        const response = await people[person].goto(path);
        assert.equal(response.status(), 403, `${person} opening ${path}`);
    }
    const auditorHome = await people.auditor.goto('/dashboard');
    assert.match(people.auditor.url(), /\/payments\/cash-boxes\?place=closed/, `auditor landed on ${people.auditor.url()} (${auditorHome.status()})`);
});

(async () => {
    browser = await chromium.launch({ headless: !process.env.HEADED,
        ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {}) });
    for (const [index, { name, run }] of steps.entries()) {
        const started = Date.now();
        try {
            await run();
            assert.deepEqual(pageErrors, [], 'The website must not raise unhandled JavaScript errors.');
            console.log(`✓ ${index + 1}. ${name} (${Date.now() - started}ms)`);
        } catch (error) {
            console.log(`✗ ${index + 1}. ${name}\n${error.stack}`);
            for (const [who, page] of Object.entries(people)) {
                await page.screenshot({ path: `${OUT_DIR}/failed-${who}.png`, fullPage: true }).catch(() => {});
            }
            await browser.close();
            process.exit(1);
        }
    }
    await browser.close();
    console.log(`All ${steps.length} steps passed. Screenshots: ${OUT_DIR}`);
})();
