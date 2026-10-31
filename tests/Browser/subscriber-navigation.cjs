const { chromium, expect: browserExpect } = require('playwright/test');
const assert = require('node:assert/strict');
const expect = browserExpect.configure({ timeout: 15_000 });

(async () => {
    const browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {}) });
    const page = await browser.newPage({ baseURL: process.env.BASE_URL, viewport: { width: 1500, height: 1000 }, locale: 'ar' });
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.stack ?? error.message));
    try {
        await page.goto('/login');
        await page.locator('#username').fill('owner');
        await page.locator('#password').fill('Strong-Pass-2026');
        await page.getByRole('button', { name: 'تسجيل الدخول', exact: true }).click();
        await page.waitForURL((url) => !url.pathname.startsWith('/login'));

        for (const path of ['/subscriptions', '/payments']) {
            await page.goto(path);
            const row = page.locator('.data-table tbody > tr[data-clickable]').first();
            const name = row.locator('.row-identity-text > button');
            await expect(name).toBeVisible();
            const subscriberName = await name.innerText();
            const statement = page.getByRole('dialog', { name: /^كشف حساب/ });
            const profile = page.locator('.subscription-profile[role="dialog"]');

            for (const target of [row.locator('td').nth(path === '/subscriptions' ? 1 : 0), name]) {
                await target.click();
                await expect(statement).toBeVisible();
                await expect(statement).toContainText(subscriberName);
                await expect(statement.locator('.account-statement')).toBeVisible();
                await expect(page.getByRole('dialog')).toHaveCount(1);
                await expect(profile).toHaveCount(0);
                await statement.getByRole('button', { name: /^إخفاء/ }).click();
                await expect(statement).toHaveCount(0);
            }

            if (path === '/subscriptions') {
                const checkbox = row.getByRole('checkbox');
                await checkbox.check();
                await expect(checkbox).toBeChecked();
                await expect(page.getByRole('dialog')).toHaveCount(0);
                await checkbox.uncheck();
                await row.getByRole('button', { name: 'عرض', exact: true }).click();
                await expect(profile).toBeVisible();
                await expect(profile.getByRole('heading', { name: /^البيانات الشخصية/ })).toBeVisible();
                await expect(page.getByRole('dialog')).toHaveCount(1);
                await expect(statement).toHaveCount(0);
                await profile.locator('button[aria-label="إغلاق"]').click();
                await expect(profile).toHaveCount(0);
            } else {
                await row.getByRole('button', { name: 'تسجيل دفعة', exact: true }).click();
                const payment = page.getByRole('dialog');
                await expect(payment).toHaveCount(1);
                await expect(payment.locator('#amount')).toBeVisible();
                await expect(statement).toHaveCount(0);
                await payment.getByRole('button', { name: 'إلغاء', exact: true }).click();
                await expect(payment).toHaveCount(0);
            }

            await row.getByRole('button', { name: 'إجراءات أخرى', exact: true }).click();
            await expect(page.getByRole('menu')).toBeVisible();
            await expect(page.getByRole('dialog')).toHaveCount(0);
            await expect(page.getByRole('menu')).toContainText(path === '/subscriptions' ? 'بيانات المشترك' : 'دفعة مقسّمة');
            await page.keyboard.press('Escape');
            await expect(page.getByRole('menu')).toHaveCount(0);
            await row.getByRole('button', { name: 'إجراءات أخرى', exact: true }).click();
            await expect(page.getByRole('menu')).toBeVisible();
            await page.setViewportSize({ width: 1480, height: 1000 });
            await expect(page.getByRole('menu')).toHaveCount(0);
            await page.setViewportSize({ width: 1500, height: 1000 });
            await page.screenshot({ path: `${process.env.OUT_DIR}/${path.slice(1)}-navigation.png`, fullPage: true });
        }

        assert.deepEqual(errors, []);
        console.log('PASS: subscriber rows and names open one statement; View opens personal details; payment, checkboxes and menus keep their own actions.');
    } catch (error) {
        await page.screenshot({ path: `${process.env.OUT_DIR}/subscriber-navigation-failed.png`, fullPage: true }).catch(() => {});
        console.error(error.stack);
        process.exitCode = 1;
    } finally {
        await browser.close();
    }
})();
