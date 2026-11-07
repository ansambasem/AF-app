const { chromium, expect: browserExpect } = require('playwright/test');
const assert = require('node:assert/strict');
const expect = browserExpect.configure({ timeout: 15_000 });

(async () => {
    const browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {}) });
    const page = await browser.newPage({ baseURL: process.env.BASE_URL, viewport: { width: 1500, height: 1050 }, locale: 'ar' });
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    try {
        await page.goto('/login');
        await page.locator('#username').fill('owner');
        await page.locator('#password').fill('Strong-Pass-2026');
        await page.getByRole('button', { name: 'تسجيل الدخول', exact: true }).click();
        await page.waitForURL((url) => !url.pathname.startsWith('/login'));
        for (const path of ['/reports', '/payments/cash-boxes', '/payments/cash-boxes/entries', '/subscriptions', '/ledger', '/meter-readings']) {
            await page.goto(path);
            const banner = page.getByRole('region', { name: 'الفرع المعروض', exact: true });
            await banner.getByRole('heading', { name: 'جميع الفروع', exact: true }).waitFor();
            await page.screenshot({ path: `${process.env.OUT_DIR}/all${path.replaceAll('/', '-')}.png`, fullPage: true });
            if (path === '/reports') {
                await banner.locator('select').selectOption({ label: 'فرع باء' });
                await banner.getByRole('heading', { name: 'فرع باء', exact: true }).waitFor();
                await banner.locator('select').selectOption('all');
                await banner.getByRole('heading', { name: 'جميع الفروع', exact: true }).waitFor();
            } else if (path.startsWith('/payments/cash-boxes')) {
                await banner.getByRole('button').click();
                await page.locator('ul button').filter({ hasText: /^فرع باء$/ }).click();
                await banner.getByRole('heading', { name: 'فرع باء', exact: true }).waitFor();
                await banner.getByRole('button').click();
                await page.locator('ul button').filter({ hasText: /^جميع الفروع$/ }).click();
                await banner.getByRole('heading', { name: 'جميع الفروع', exact: true }).waitFor();
            }
        }
        await page.getByRole('link', { name: 'عرض القراءات', exact: true }).first().click();
        await page.locator('table.re-table').waitFor();
        await page.locator('[data-excel-button]').first().waitFor();
        assert.equal(await page.locator('[data-excel-button]').count(), 1);

        await page.goto('/subscriptions');
        const banner = page.getByRole('region', { name: 'الفرع المعروض', exact: true });
        async function pickBranch(name) {
            await banner.getByRole('button').click();
            await page.locator('ul button').filter({ hasText: new RegExp(`^${name}$`) }).click();
            await banner.getByRole('heading', { name, exact: true }).waitFor();
        }
        await pickBranch('فرع باء');
        for (const path of ['/meter-boxes', '/ledger', '/reports', '/dashboard']) {
            await page.goto(path);
            await banner.getByRole('heading', { name: 'فرع باء', exact: true }).waitFor();
        }
        await page.goto('/subscriptions');
        await pickBranch('فرع ألف');
        await page.goto('/reports');
        await banner.getByRole('heading', { name: 'فرع ألف', exact: true }).waitFor();
        await banner.locator('select').selectOption('all');
        await banner.getByRole('heading', { name: 'جميع الفروع', exact: true }).waitFor();
        for (const path of ['/subscriptions', '/ledger', '/payments/cash-boxes', '/dashboard']) {
            await page.goto(path);
            await banner.getByRole('heading', { name: 'جميع الفروع', exact: true }).waitFor();
        }

        for (const path of ['/subscriptions', '/payments', '/ledger', '/reports?section=movements', '/payments/cash-boxes/report']) {
            await page.goto(path);
            const exports = page.getByRole('group', { name: 'تصدير وطباعة الجدول', exact: true }).first();
            await expect(exports).toBeVisible();
            const geometry = await exports.locator(':scope > a, :scope > button').evaluateAll((buttons) => buttons.map((button) => ({
                text: button.textContent.trim(),
                x: button.getBoundingClientRect().x,
                y: button.getBoundingClientRect().y,
                height: button.getBoundingClientRect().height,
            })));
            assert.equal(await exports.getAttribute('dir'), 'rtl');
            assert.deepEqual(geometry.map((button) => button.text), ['Excel', 'طباعة']);
            assert.ok(geometry[0].x > geometry[1].x);
            assert.ok(Math.abs(geometry[0].y - geometry[1].y) < 1);
            assert.ok(geometry.every((button) => button.height === 34));
        }

        await page.goto('/payments?search=missing-subscriber-unique-test');
        await expect(page.locator('.data-table tbody')).toContainText('لا توجد نتائج مطابقة.');
        await expect(page.locator('[data-excel-button]')).toHaveCount(1);
        const paymentExports = page.locator('.data-table-toolbar [data-table-export-slot] [data-table-exports]');
        await expect(paymentExports.getByRole('link', { name: 'Excel', exact: true })).toBeVisible();
        await expect(paymentExports.getByRole('button', { name: 'طباعة', exact: true })).toBeVisible();
        await expect(page.locator('[data-auto-table-exports]')).toHaveCount(0);
        await page.screenshot({ path: `${process.env.OUT_DIR}/payments-empty-table-controls.png`, fullPage: true });

        await page.goto('/payments');
        const printOpened = page.context().waitForEvent('page');
        await paymentExports.getByRole('button', { name: 'طباعة', exact: true }).click();
        const printed = await printOpened;
        await expect(printed.getByRole('article', { name: 'معاينة الورقة المطبوعة', exact: true })).toBeVisible();
        await expect(printed.locator('.pd-row')).toHaveCount(2);
        await printed.close();

        await page.goto('/disconnection-lists');
        await expect(page.locator('[data-excel-button]')).toHaveCount(1);
        await expect(page.locator('.data-table-toolbar [data-table-export-slot] [data-auto-table-exports] [data-excel-button]')).toBeVisible();
        const fallbackButton = page.locator('[data-excel-button]');
        await fallbackButton.click();
        await expect(page.getByRole('dialog', { name: /^تصدير/ })).toBeVisible();
        await page.getByRole('dialog', { name: /^تصدير/ }).getByRole('button', { name: 'إغلاق النافذة', exact: true }).click();

        await page.goto('/subscriptions');
        await page.setViewportSize({ width: 390, height: 844 });
        const mobileExports = page.getByRole('group', { name: 'تصدير وطباعة الجدول', exact: true });
        await expect(mobileExports).toBeVisible();
        const mobileButtons = await mobileExports.locator(':scope > a, :scope > button').evaluateAll((buttons) => buttons.map((button) => button.getBoundingClientRect().y));
        assert.ok(Math.abs(mobileButtons[0] - mobileButtons[1]) < 1);
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
        await page.screenshot({ path: `${process.env.OUT_DIR}/consistent-table-controls-mobile.png`, fullPage: true });
        assert.deepEqual(errors, []);
        console.log('PASS: banner choices persist between pages and return to all; table exports share RTL order, position and size on desktop and mobile.');
    } catch (error) {
        await page.screenshot({ path: `${process.env.OUT_DIR}/branch-defaults-failed.png`, fullPage: true }).catch(() => {});
        console.error(error.stack);
        process.exitCode = 1;
    } finally {
        await browser.close();
    }
})();
