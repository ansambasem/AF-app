const { chromium } = require('playwright');
const assert = require('node:assert/strict');

(async () => {
    const browser = await chromium.launch({ headless: !process.env.HEADED,
        ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {}) });
    const page = await browser.newPage({ baseURL: process.env.BASE_URL, viewport: { width: 1600, height: 1050 }, locale: 'ar' });
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    try {
        await page.goto('/login');
        await page.locator('#username').fill('owner');
        await page.locator('#password').fill('Strong-Pass-2026');
        await page.getByRole('button', { name: 'تسجيل الدخول', exact: true }).click();
        await page.waitForURL((url) => !url.pathname.startsWith('/login'));
        await page.goto('/users?print=1&print_all=1&print_template=new&print_title=المستخدمون');
        const paper = page.getByRole('article', { name: 'معاينة الورقة المطبوعة' });
        await paper.waitFor();
        const originalRows = await paper.locator('.pd-row').count();
        assert.ok(originalRows > 0);
        await page.getByLabel('البدء من تصميم جاهز').selectOption('clearing');
        await page.locator('summary').filter({ hasText: /^الترويسة$/ }).click();
        await page.getByLabel('اسم الشركة', { exact: true }).fill('شركة أبناء فارس أبوزايد للتجارة العامة');
        await page.getByLabel('تفاصيل الشركة بالعربية').fill('إدارة: أحمد فارس أبو زايد');
        await page.getByLabel('اسم الشركة والتفاصيل بالإنجليزية').fill('Company for General Trade\nFares Abu Zayed Sons');
        await page.getByLabel('خط عنوان التقرير').selectOption('messiri');
        await page.getByLabel('حجم عنوان التقرير (بكسل)').fill('24');
        await page.getByLabel('عرض الشعار (مم)').fill('38');
        await page.getByLabel('ارتفاع الشعار (مم)').fill('25');
        await page.getByLabel('الفاصل أسفل الترويسة').selectOption('double');
        await page.getByLabel('محاذاة النص العربي').selectOption('right');
        await page.getByLabel('محاذاة النص الإنجليزي').selectOption('left');
        const assertCenteredLogo = async () => {
            const geometry = await paper.locator('[data-print-letterhead]').evaluate((header) => {
                const row = header.getBoundingClientRect();
                const logo = header.querySelector('img').getBoundingClientRect();
                return { rowCenter: (row.left + row.right) / 2, logoCenter: (logo.left + logo.right) / 2, rowWidth: row.width, scrollWidth: header.scrollWidth, clientWidth: header.clientWidth };
            });
            assert.ok(Math.abs(geometry.rowCenter - geometry.logoCenter) < 2, JSON.stringify(geometry));
            assert.ok(geometry.scrollWidth <= geometry.clientWidth + 1, 'The letterhead must fit the paper.');
        };
        await assertCenteredLogo();
        assert.equal(await paper.locator('.pd-row').count(), originalRows);
        assert.match(await paper.locator('h1').evaluate((element) => getComputedStyle(element).fontFamily), /El Messiri/);
        await page.getByLabel('حفظ التصميم كقالب جديد').fill('ورقة مقاصة');
        await page.getByRole('button', { name: 'حفظ', exact: true }).click();
        await page.getByLabel('القالب المختار').getByRole('option', { name: 'ورقة مقاصة', exact: true }).waitFor({ state: 'attached' });
        await page.reload();
        await paper.waitFor();
        await page.getByLabel('القالب المختار').selectOption({ label: 'ورقة مقاصة' });
        await paper.getByRole('heading', { name: 'ورقة مقاصة', exact: true }).waitFor();
        await assertCenteredLogo();
        assert.equal(await paper.getByText('Company for General Trade\nFares Abu Zayed Sons').count(), 1);
        await paper.locator('.pd-header').screenshot({ path: `${process.env.OUT_DIR}/letterhead-header.png` });
        await paper.screenshot({ path: `${process.env.OUT_DIR}/letterhead-paper.png` });
        await page.screenshot({ path: `${process.env.OUT_DIR}/letterhead-designer.png`, fullPage: true });
        await page.emulateMedia({ media: 'print' });
        await assertCenteredLogo();
        await paper.screenshot({ path: `${process.env.OUT_DIR}/letterhead-print.png` });
        await page.emulateMedia({ media: 'screen' });
        await page.locator('summary').filter({ hasText: /^الورق$/ }).click();
        await page.getByLabel('حجم الورق', { exact: true }).selectOption('A5');
        await assertCenteredLogo();
        await page.locator('summary').filter({ hasText: /^الترويسة$/ }).click();
        for (const position of ['left', 'right']) {
            await page.getByLabel('موضع الشعار').selectOption(position);
            const order = await paper.locator('[data-print-letterhead]').evaluate((header) => {
                const logo = header.querySelector('img').getBoundingClientRect();
                const arabic = header.querySelector('[dir="rtl"]').getBoundingClientRect();
                const english = header.querySelector('[dir="ltr"]').getBoundingClientRect();
                return { logo: logo.x, arabic: arabic.x, english: english.x, width: header.clientWidth, scroll: header.scrollWidth };
            });
            assert.ok(position === 'left' ? order.logo < order.english : order.logo > order.arabic);
            assert.ok(order.scroll <= order.width + 1);
        }
        await page.goto('/settings/print-templates');
        await page.getByRole('button', { name: 'نسخ «ورقة مقاصة»', exact: true }).click();
        await page.getByText('ورقة مقاصة (نسخة)', { exact: true }).waitFor();
        await page.getByRole('button', { name: 'إعادة تسمية «ورقة مقاصة (نسخة)»', exact: true }).click();
        await page.getByRole('dialog').getByRole('textbox').fill('ورقة مقاصة — الفرع الثاني');
        await page.getByRole('dialog').getByRole('button', { name: /حفظ/ }).click();
        await page.getByText('ورقة مقاصة — الفرع الثاني', { exact: true }).waitFor();
        await page.screenshot({ path: `${process.env.OUT_DIR}/named-templates.png`, fullPage: true });
        assert.deepEqual(errors, []);
        console.log('PASS: letterhead alignment, typography, printing, saving, reloading, copying and naming templates.');
    } catch (error) {
        await page.screenshot({ path: `${process.env.OUT_DIR}/print-failure.png`, fullPage: true }).catch(() => {});
        console.error(error.stack);
        process.exitCode = 1;
    } finally {
        await browser.close();
    }
})();
