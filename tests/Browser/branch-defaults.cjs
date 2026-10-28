const { chromium } = require('playwright');
const assert = require('node:assert/strict');

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
        assert.deepEqual(errors, []);
        console.log('PASS: all branch defaults, explicit branch choices, clearing the choice, and branch-specific reading weeks.');
    } catch (error) {
        await page.screenshot({ path: `${process.env.OUT_DIR}/branch-defaults-failed.png`, fullPage: true }).catch(() => {});
        console.error(error.stack);
        process.exitCode = 1;
    } finally {
        await browser.close();
    }
})();
