const { chromium, expect: browserExpect } = require('playwright/test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const expect = browserExpect.configure({ timeout: 15_000 });

(async () => {
    const fixture = JSON.parse(readFileSync(`${process.env.OUT_DIR}/reports-fixture.json`, 'utf8'));
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
        await page.goto(`/subscriptions?statement=${fixture.credited}`);
        const statement = page.getByRole('dialog', { name: /^كشف حساب/ });
        const ledger = statement.locator('.account-statement');
        await expect(ledger).toBeVisible();
        const rows = ledger.locator('tr[id^="statement-line-"]');
        await expect(rows).toHaveCount(2);
        const balances = await rows.locator('td[data-label="الرصيد (شيكل)"]').allTextContents();
        assert.deepEqual(balances.map((value) => value.replace(/[\u2066-\u2069]/g, '').trim()).sort(), ['-50', '100']);
        const currentBalance = ledger.locator('.statement-summary > div').first();
        await expect(currentBalance).toContainText('-50');
        await expect(currentBalance).not.toContainText(/له|عليه|مسدّد/);
        await expect(ledger.locator('.balance-paper-word')).toHaveCount(0);

        const payment = rows.filter({ has: page.locator('td[data-label="نوع الحركة"]', { hasText: 'دفعة' }) });
        const charge = rows.filter({ has: page.locator('td[data-label="نوع الحركة"]', { hasText: 'غرامة' }) });
        const menu = page.getByRole('menu');
        const button = payment.getByRole('button', { name: 'إجراءات أخرى', exact: true });
        await button.click();
        await expect(menu).toBeVisible();
        await expect(menu.locator('b').first()).toHaveText('دفعة');
        const description = menu.locator('b + span').first();
        await expect(description).toHaveText(await payment.locator('td[data-label="البيان"]').innerText());
        assert.equal(await description.evaluate((element) => getComputedStyle(element).whiteSpace), 'pre-wrap');
        const paymentActions = await menu.getByRole('menuitem').allTextContents();
        assert.ok(paymentActions.some((label) => label.startsWith('تعديل بيانات الدفعة')));
        await page.keyboard.press('Escape');
        await expect(menu).toHaveCount(0);
        await expect(button).toBeFocused();

        await payment.locator('td[data-label="البيان"]').click({ button: 'right' });
        await expect(menu).toHaveCount(1);
        assert.deepEqual(await menu.getByRole('menuitem').allTextContents(), paymentActions);
        await expect(menu.locator('b').first()).toHaveText('دفعة');
        await expect(description).toBeVisible();
        const bounds = await menu.boundingBox();
        assert.ok(bounds.x >= 0 && bounds.y >= 0 && bounds.x + bounds.width <= 1500 && bounds.y + bounds.height <= 1000);
        await page.screenshot({ path: `${process.env.OUT_DIR}/statement-context-menu.png` });
        await menu.getByRole('menuitem', { name: /^تعديل بيانات الدفعة/ }).click();
        const edit = page.getByRole('dialog').last();
        await expect(page.getByRole('dialog')).toHaveCount(2);
        await expect(edit.getByRole('heading', { name: 'تعديل بيانات الحركة', exact: true })).toBeVisible();
        await expect(menu).toHaveCount(0);
        await edit.getByRole('button', { name: 'إلغاء', exact: true }).click();
        await expect(page.getByRole('dialog')).toHaveCount(1);

        await payment.focus();
        await payment.press('Shift+F10');
        await expect(menu).toHaveCount(1);
        await expect(menu.getByRole('menuitem').first()).toBeFocused();
        await page.keyboard.press('ArrowDown');
        await expect(menu.getByRole('menuitem').nth(1)).toBeFocused();
        await page.keyboard.press('Escape');
        await expect(menu).toHaveCount(0);
        await expect(payment).toBeFocused();
        await expect(statement).toBeVisible();

        await charge.locator('td[data-label="البيان"]').click({ button: 'right' });
        await expect(menu).toHaveCount(1);
        await expect(menu.getByRole('menuitem', { name: /^إرجاع الدفعة|^تعديل بيانات الدفعة/ })).toHaveCount(0);
        await charge.locator('td[data-label="اسم المستخدم"]').click();
        await expect(menu).toHaveCount(0);

        await page.setViewportSize({ width: 390, height: 844 });
        await button.click();
        await expect(menu).toBeVisible();
        assert.deepEqual(await menu.getByRole('menuitem').allTextContents(), paymentActions);
        await expect.poll(async () => {
            const bounds = await menu.boundingBox();
            return bounds && bounds.x >= 0 && bounds.x + bounds.width <= 391 && bounds.y + bounds.height <= 845;
        }).toBeTruthy();
        await page.screenshot({ path: `${process.env.OUT_DIR}/statement-mobile-menu.png` });
        await page.keyboard.press('Escape');
        await expect(menu).toHaveCount(0);
        assert.deepEqual(errors, []);
        console.log('PASS: button, right-click and keyboard share permitted transaction actions; signed balances have no side words; menus work on mobile.');
    } catch (error) {
        await page.screenshot({ path: `${process.env.OUT_DIR}/statement-actions-failed.png` }).catch(() => {});
        console.error(error.stack);
        process.exitCode = 1;
    } finally {
        await browser.close();
    }
})();
