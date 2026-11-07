import { test } from 'node:test';
import assert from 'node:assert/strict';
import { sectionModel } from '../../resources/js/lib/permissions.js';
import { permissionGuideEntries, permissionGuideHtml, CATEGORIES } from '../../resources/js/lib/permissionGuide.js';

test('the guide uses canonical permissions and identifies financial audit as independent from payment entry', () => {
    const payments = sectionModel({ key: 'collections', label: 'الدفعات', actions: [{ action: 'amend', permission: { id: 1, key: 'collections.amend', label: 'تعديل بيانات الدفعة', centralOnly: false } }] });
    const audit = sectionModel({ key: 'financial_audit', label: 'التدقيق المالي', actions: [{ action: 'audit', permission: { id: 2, key: 'closings.audit', label: 'التدقيق المالي', centralOnly: true } }] });
    const [paymentGuide, auditGuide] = permissionGuideEntries([payments, audit]);
    assert.match(paymentGuide.description, /الرقم المرجعي/);
    assert.match(paymentGuide.limit, /لا يغيّر مبلغ الدفعة/);
    assert.equal(auditGuide.delegation, 'يفوضها السوبر أدمن فقط.');
    assert.deepEqual(CATEGORIES.find(category => category.key === 'audit').sections, ['financial_audit']);
    assert.ok(!CATEGORIES.find(category => category.key === 'finance').sections.includes('financial_audit'));
});

test('printable guides escape all employee and permission text and preserve the selected scope without external scripts', () => {
    const html = permissionGuideHtml({ title: '<script>اسم الموظف</script>', scopeNote: '<img onerror=unsafe>', entries: [
        { section: 'users', sectionLabel: 'المستخدمون', label: '<b>صلاحية</b>', description: 'الشرح', limit: 'الحدود', delegation: 'التفويض' },
    ], auditActions: [{ label: 'اعتماد الصندوق', hint: 'يرحّل الفروق' }], lockedPermissions: ['صلاحية محفوظة'] });
    assert.match(html, /&lt;script&gt;اسم الموظف&lt;\/script&gt;/);
    assert.match(html, /&lt;img onerror=unsafe&gt;/);
    assert.match(html, /&lt;b&gt;صلاحية&lt;\/b&gt;/);
    assert.match(html, /يرحّل الفروق/);
    assert.match(html, /صلاحية محفوظة/);
    assert.match(html, /@page\{size:A4/);
    assert.doesNotMatch(html, /<script|<img|https?:\/\//);
});
