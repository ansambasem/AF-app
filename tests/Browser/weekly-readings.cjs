const { chromium } = require('playwright');
const { expect } = require('playwright/test');
const assert = require('node:assert/strict');
const { readFileSync, writeFileSync } = require('node:fs');
const path = require('node:path');

(async () => {
    const fixture = JSON.parse(readFileSync(`${process.env.OUT_DIR}/fixture.json`, 'utf8'));
    const clock = process.env.BROWSER_TEST_CLOCK;
    assert.equal(path.basename(clock), 'clock.txt');
    assert.ok(path.basename(path.dirname(clock)).startsWith('powercollect-browser-'));
    const browser = await chromium.launch({ headless: !process.env.HEADED,
        ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {}) });
    const page = await browser.newPage({ baseURL: process.env.BASE_URL, viewport: { width: 1600, height: 1050 }, locale: 'ar', reducedMotion: 'reduce' });
    const errors = [];
    const evidence = [];
    const network = [];
    page.on('pageerror', (error) => errors.push(error.message));
    page.on('response', (response) => {
        const url = new URL(response.url());
        if (!url.pathname.startsWith('/build/') && url.origin === process.env.BASE_URL) network.push({ method: response.request().method(), status: response.status(), path: url.pathname });
    });
    let version;
    let subject = fixture.subscribers[0];
    const history = () => page.getByRole('dialog', { name: `سجل قراءات ${subject.name}`, exact: true });
    const latestStatus = (surface) => surface === 'history' ? history().locator('.rh-live .rh-status') : page.locator('.re-table .re-pill');
    const currentInput = (surface) => surface === 'history' ? history().getByRole('spinbutton', { name: 'القراءة الجديدة', exact: true }) : page.getByLabel(`القراءة الحالية لـ ${subject.name}`, { exact: true });
    const pageUpdated = () => page.waitForResponse((response) => response.status() === 200 && response.request().method() === 'GET' && ['/subscriptions', '/meter-readings'].includes(new URL(response.url()).pathname));
    const number = (text) => Number(text.replace(/[^\d.-]/g, ''));

    async function sheet() {
        await page.goto(`/meter-readings?${new URLSearchParams({ search: subject.account, 'filter[branch_id]': fixture.branchId })}`);
        await currentInput('sheet').waitFor();
    }

    async function openHistory() {
        await page.goto(`/subscriptions?${new URLSearchParams({ search: subject.account })}`);
        await page.evaluate(() => document.fonts.ready);
        const trigger = page.getByRole('row').filter({ hasText: subject.account }).getByRole('button', { name: 'إجراءات أخرى', exact: true });
        await trigger.scrollIntoViewIfNeeded();
        await trigger.click();
        await page.getByRole('menuitem', { name: /سجل القراءات/ }).click();
        await history().waitFor();
    }

    async function balance(expected, label) {
        const response = await page.request.get(`/subscriptions/${subject.id}/statement`, { headers: { 'X-Inertia': 'true', 'X-Inertia-Version': version } });
        assert.equal(response.status(), 200, await response.text());
        const actual = Number((await response.json()).props.summary.balance);
        assert.equal(actual, expected, label);
        evidence.push({ label, balance: actual, clock: readFileSync(clock, 'utf8') });
    }

    async function saveCurrent(surface, value, correctingApproved = false) {
        const input = currentInput(surface);
        await input.fill(String(value));
        if (correctingApproved) {
            await input.press('Enter');
            const updated = pageUpdated();
            await page.getByRole('button', { name: 'نعم، عدّل', exact: true }).click();
            await updated;
        } else {
            const updated = pageUpdated();
            await input.press('Enter');
            await updated;
        }
        await expect(input).toHaveValue(String(value));
        await expect(latestStatus(surface)).toContainText('بانتظار الاعتماد');
    }

    async function approve(surface) {
        const container = surface === 'history' ? history() : page.locator('.re-table');
        await container.getByRole('button', { name: `اعتماد قراءة ${subject.name}`, exact: true }).click();
        const updated = pageUpdated();
        await page.getByRole('button', { name: 'اعتماد القراءة', exact: true }).click();
        await updated;
        await expect(latestStatus(surface)).toContainText('معتمدة');
    }

    async function adjust(surface, before, after, { reset = false, previousAmount, correctedAmount, removedAmount = 0, screenshot } = {}) {
        const container = surface === 'history' ? history() : page.locator('.re-table');
        await container.getByRole('button', { name: surface === 'history' ? 'تعديل القراءة السابقة' : `تعديل القراءة السابقة لـ ${subject.name}`, exact: true }).click();
        const dialog = page.getByRole('dialog', { name: 'تعديل القراءة السابقة', exact: true });
        await dialog.locator(`input[value="${reset ? 'meter_change' : 'correction'}"]`).check();
        if (reset) await dialog.getByRole('textbox', { name: /سبب تغيير العداد/ }).fill('استبدال عداد تالف أثناء اختبار دورة القراءات');
        await dialog.getByRole('button', { name: 'متابعة', exact: true }).click();
        await dialog.getByRole('spinbutton', { name: 'القراءة السابقة الجديدة', exact: true }).fill(String(after));
        const previewed = page.waitForResponse((response) => response.request().method() === 'POST' && /\/(?:adjustment-preview|reading-baseline\/preview)$/.test(new URL(response.url()).pathname));
        await dialog.getByRole('button', { name: 'مراجعة التعديل', exact: true }).click();
        const preview = await (await previewed).json();
        assert.equal(Number(preview.before), before);
        assert.equal(Number(preview.after), after);
        assert.equal(Number(preview.removedCurrentAmount), removedAmount);
        if (previousAmount !== undefined) {
            assert.equal(Number(preview.weeks[0].before.amountDue), previousAmount);
            assert.equal(Number(preview.weeks[0].after.amountDue), correctedAmount);
        }
        await expect(dialog.getByText('قبل التعديل', { exact: true })).toBeVisible();
        await expect(dialog.getByText('بعد التعديل', { exact: true })).toBeVisible();
        if (screenshot) await page.screenshot({ path: `${process.env.OUT_DIR}/${screenshot}.png`, fullPage: true });
        const updated = pageUpdated();
        await dialog.getByRole('button', { name: reset ? 'حفظ نقطة البداية' : 'حفظ التصحيح', exact: true }).click();
        await updated;
        await dialog.waitFor({ state: 'hidden' });
        evidence.push({ label: 'baseline-adjustment', surface, before, after, preview });
    }

    async function login() {
        await page.goto('/login');
        version = JSON.parse(await page.locator('script[data-page]').textContent()).version;
        await page.locator('#username').fill('owner');
        await page.locator('#password').fill('Strong-Pass-2026');
        await page.getByRole('button', { name: 'تسجيل الدخول', exact: true }).click();
        await page.waitForURL((url) => !url.pathname.startsWith('/login'));
    }

    async function advance(date) {
        writeFileSync(clock, `${date}T10:00:00+03:00`);
        console.log(`Reading test clock: ${date}`);
        await login();
    }

    try {
        await login();

        await openHistory();
        await adjust('history', 1000, 1010, { screenshot: 'initial-reading-before-after' });
        await saveCurrent('history', 1110);
        await approve('history');
        await balance(50, 'first week: initial baseline and current reading entered from history');
        await sheet();
        await expect(currentInput('sheet')).toHaveValue('1110');
        assert.equal(number(await page.locator('.re-last-number').innerText()), 1010);

        await advance(fixture.readingDays[1]);
        await sheet();
        await expect(currentInput('sheet')).toHaveValue('');
        await saveCurrent('sheet', 1200);
        await approve('sheet');
        await balance(95, 'second week inherits the first reading');
        await openHistory();
        await adjust('history', 1110, 1120, { previousAmount: 50, correctedAmount: 55, removedAmount: 45, screenshot: 'previous-reading-before-after' });
        await expect(currentInput('history')).toHaveValue('');
        await balance(55, 'correcting the previous reading removes the current bill until re-entry');
        await saveCurrent('history', 1210);
        await approve('history');
        await balance(100, 'current reading is entered and approved again from the history modal');
        await sheet();
        await saveCurrent('sheet', 1220, true);
        await balance(55, 'correcting an approved current reading reopens its bill');
        await approve('sheet');
        await balance(105, 'reapproving the corrected current reading bills it once');

        await advance(fixture.readingDays[2]);
        await openHistory();
        const oldest = history().getByRole('row').filter({ hasText: `${fixture.weekStarts[0].slice(8, 10)}/${fixture.weekStarts[0].slice(5, 7)}` });
        await expect(oldest.locator('input, .rh-last-pencil')).toHaveCount(0);
        await adjust('history', 1220, 0, { reset: true, previousAmount: 50, correctedAmount: 50, screenshot: 'meter-replacement-before-after' });
        await balance(105, 'meter replacement preserves every previous bill');
        await sheet();
        assert.equal(number(await page.locator('.re-last-number').innerText()), 0);
        await saveCurrent('sheet', 30);
        await approve('sheet');
        await balance(120, 'new meter starts its current reading from zero');
        await adjust('sheet', 0, 5, { reset: true, previousAmount: 50, correctedAmount: 50, removedAmount: 15 });
        await openHistory();
        await expect(currentInput('history')).toHaveValue('');
        await saveCurrent('history', 40);
        await approve('history');
        await balance(122.5, 'current week recalculates from the replacement meter baseline');

        await sheet();
        const print = await page.getByRole('link', { name: 'طباعة وتخصيص', exact: true }).boundingBox();
        const message = await page.getByRole('link', { name: 'إرسال رسائل القراءات المعتمدة', exact: true }).boundingBox();
        assert.ok(Math.abs(print.height - message.height) < 1, JSON.stringify({ print, message }));
        assert.ok(Math.abs(print.y - message.y) < 1, JSON.stringify({ print, message }));
        await page.locator('.re-heading').screenshot({ path: `${process.env.OUT_DIR}/aligned-weekly-actions.png` });
        await page.setViewportSize({ width: 390, height: 844 });
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
        await page.locator('.re-heading').screenshot({ path: `${process.env.OUT_DIR}/aligned-weekly-actions-mobile.png` });
        await page.setViewportSize({ width: 1600, height: 1050 });

        subject = fixture.subscribers[1];
        await openHistory();
        await saveCurrent('history', 1110);
        await approve('history');
        await balance(44, 'discounted subscriber is charged using the standing discount');
        await currentInput('history').fill('1120');
        await expect(history().locator('.rh-live .rh-due b')).toContainText('48.00');
        await expect(history().locator('.rh-live .rh-discount')).toContainText('12.00');
        await saveCurrent('history', 1120, true);
        await approve('history');
        await balance(48, 'history live preview and the saved corrected discounted bill agree');

        subject = fixture.subscribers[0];
        await advance(fixture.readingDays[3]);
        await openHistory();
        const locked = history().getByRole('row').filter({ hasText: `${fixture.weekStarts[1].slice(8, 10)}/${fixture.weekStarts[1].slice(5, 7)}` });
        await expect(locked.locator('input, .rh-last-pencil')).toHaveCount(0);
        await expect(currentInput('history')).toHaveValue('');
        await expect(history().getByRole('button', { name: 'تعديل القراءة السابقة', exact: true })).toHaveCount(1);
        await balance(122.5, 'advancing again locks older weeks without changing the ledger');
        await page.screenshot({ path: `${process.env.OUT_DIR}/history-after-week-rollover.png`, fullPage: true });
        assert.deepEqual(errors, []);
        writeFileSync(`${process.env.OUT_DIR}/reading-journey-results.json`, JSON.stringify(evidence, null, 2));
        console.log('PASS: initial baseline, three weekly readings, two entry surfaces, before/after previews, previous/current corrections, re-entry, reapproval, meter replacement, zero baseline, captured discounts, historical locks and aligned print actions.');
    } catch (error) {
        await page.screenshot({ path: `${process.env.OUT_DIR}/weekly-readings-failure.png`, fullPage: true }).catch(() => {});
        console.error(await page.locator('body').innerText().catch(() => ''));
        throw error;
    } finally {
        writeFileSync(`${process.env.OUT_DIR}/reading-journey-network.json`, JSON.stringify(network, null, 2));
        await browser.close();
    }
})().catch((error) => { console.error(error); process.exitCode = 1; });
