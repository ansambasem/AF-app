import { test } from 'node:test';
import assert from 'node:assert/strict';
import { changesBetween, copyWorkPermissions, filterSections, individualChanges, levelOf, permissionCells, samePermissions, sectionExtras, sectionIds, sectionModel, sectionSentence, withGroupAccess, withLevel, withSectionLevel, withToggled } from '../../resources/js/lib/permissions.js';

const entry = (action, id, label = action) => ({ action, permission: id === null ? null : { id, label } });

const subscriptions = sectionModel({
    key: 'subscriptions',
    label: 'المشتركون',
    actions: [entry('view', 1), entry('create', 2), entry('update', 3), entry('delete', 4), entry('minimum_charge', 5)],
});

test('matrix actions preserve edit-only access without granting view or create', () => {
    const edit = permissionCells(subscriptions).find((cell) => cell.key === 'update').permission;
    const selected = withToggled([99], edit.id, true);

    assert.deepEqual(selected, [99, 3]);
    assert.deepEqual(individualChanges([subscriptions], [], selected).map((change) => change.action), ['update']);
    assert.equal(permissionCells(subscriptions).find((cell) => cell.key === 'delete').permission.risk, 'danger');
    assert.equal(permissionCells(subscriptions).find((cell) => cell.key === 'export').permission, null);
});

test('copying work grants preserves the target audit access and ignores unavailable source permissions', () => {
    const audit = sectionModel({ key: 'financial_audit', label: 'التدقيق', actions: [entry('audit', 8), entry('view_all_boxes', 9)] });
    const sections = [subscriptions, audit];

    assert.deepEqual(copyWorkPermissions(sections, [1, 8, 100], [2, 3, 9, 999]), [8, 100, 2, 3]);
    assert.deepEqual(copyWorkPermissions(sections, [1], [2, 8, 9]), [2]);
    assert.deepEqual(copyWorkPermissions(sections, [1, 8], []), [8]);
});

test('matrix search ignores Arabic diacritics and hamza variants without changing grants', () => {
    const selected = [3];
    const original = [...selected];
    const section = sectionModel({ key: 'users', label: 'إدارة المُوظّفين', actions: [entry('view', 7)] });

    assert.deepEqual(filterSections([section], selected, [], 'اداره الموظفين').map((item) => item.key), ['users']);
    assert.deepEqual(selected, original);
});

test('a section climbs view, add, edit, with delete and special permissions set apart', () => {
    assert.deepEqual(subscriptions.ladder.ids, [1, 2, 3]);
    assert.deepEqual(subscriptions.ladder.levels, ['بدون', 'عرض', 'إضافة', 'تعديل']);
    assert.deepEqual(subscriptions.sensitive.map((item) => [item.id, item.label]), [[4, 'الحذف'], [5, 'تعديل الحد الأدنى للدفع']]);
    assert.deepEqual(subscriptions.switches, []);
    assert.deepEqual(sectionIds(subscriptions).sort(), [1, 2, 3, 4, 5]);
});

test('a level is held only when exactly the permissions up to it are', () => {
    assert.equal(levelOf(subscriptions.ladder, []), 0);
    assert.equal(levelOf(subscriptions.ladder, [1]), 1);
    assert.equal(levelOf(subscriptions.ladder, [1, 2, 4]), 2);
    assert.equal(levelOf(subscriptions.ladder, [1, 2, 3]), 3);
    // Edit without add, or add without view, sits on no level: it is custom.
    assert.equal(levelOf(subscriptions.ladder, [1, 3]), null);
    assert.equal(levelOf(subscriptions.ladder, [2]), null);
});

test('editing the box period is an independent switch in the boxes group', () => {
    const payments = sectionModel({ key: 'collections', label: 'الدفعات', actions: [entry('view', 1), entry('record', 2), entry('manage_box_periods', 3)] });
    const boxes = sectionExtras(payments).find((group) => group.title === 'الصناديق');

    assert.deepEqual(boxes.items.map((item) => [item.id, item.label, item.risk, item.needs]), [[3, 'تعديل بداية فترات الصناديق', 'sensitive', 0]]);
    assert.deepEqual(withSectionLevel(payments, [], 2), [1, 2]);
});

test('cross-branch financial auditing is an independent sensitive action that is never granted by the payment ladder', () => {
    const payments = sectionModel({ key: 'collections', label: 'الدفعات', actions: [entry('view', 1), entry('record', 2)] });
    const auditSection = sectionModel({ key: 'financial_audit', label: 'التدقيق المالي', actions: [entry('audit', 3)] });
    const audit = sectionExtras(auditSection)[0].items[0];

    assert.equal(audit.label, 'تفعيل التدقيق المالي');
    assert.equal(auditSection.ladder, null);
    assert.equal(audit.risk, 'sensitive');
    assert.equal(audit.needs, 0);
    assert.deepEqual(withSectionLevel(payments, [], 2), [1, 2]);
    assert.deepEqual(withGroupAccess([payments], [], true), [1]);
    assert.deepEqual(withToggled([], audit.id, true), [3]);
});

test('setting a level changes only that ladder, leaving delete and other sections as they were', () => {
    assert.deepEqual(withLevel(subscriptions.ladder, [3, 4, 99], 2).sort((a, b) => a - b), [1, 2, 4, 99]);
    assert.deepEqual(withLevel(subscriptions.ladder, [1, 2, 3, 4], 0), [4]);
    assert.deepEqual(withToggled([1, 4], 4, false), [1]);
    assert.deepEqual(withToggled([1], 4, true), [1, 4]);
});

test('a ladder stops at the first level the actor may not grant, the rest become switches', () => {
    const cut = sectionModel({ key: 'users', label: 'المستخدمون', actions: [entry('view', 1), entry('create', null), entry('update', 3)] });

    assert.deepEqual(cut.ladder.ids, [1]);
    assert.deepEqual(cut.ladder.levels, ['بدون', 'عرض']);
    assert.deepEqual(cut.switches.map((item) => [item.id, item.label]), [[3, 'تعديل']]);
});

test('report viewing and cross-branch scope are separate switches', () => {
    const reports = sectionModel({ key: 'reports', label: 'التقارير', actions: [entry('view', 7), entry('view_all', 8)] });

    assert.equal(reports.ladder, null);
    assert.deepEqual(reports.switches.map((item) => item.label), ['عرض', 'عرض كل الفروع والتقارير']);
    assert.deepEqual(reports.sensitive, []);
});

test('the changes name each new level and each permission turned on or off', () => {
    assert.deepEqual(changesBetween([subscriptions], [1], [1, 2, 4]), [
        { text: 'المشتركون: إضافة', added: true },
        { text: 'المشتركون: الحذف', added: true },
    ]);
    assert.deepEqual(changesBetween([subscriptions], [1, 2, 3], [1]), [{ text: 'المشتركون: عرض', added: false }]);
    assert.deepEqual(changesBetween([subscriptions], [1, 2], [2, 1]), []);
    assert.ok(samePermissions([1, 2], [2, 1]));
    assert.ok(!samePermissions([1, 2], [1]));
});

test('each report is its own switch, explained in a line', () => {
    const reports = sectionModel({
        key: 'reports',
        label: 'التقارير',
        actions: [entry('branch_performance', 10), entry('debt_aging', 11), entry('transaction_audit', 12)],
    });

    assert.equal(reports.ladder, null);
    assert.deepEqual(reports.switches.map((item) => item.label), ['أداء الفروع', 'أعمار الديون', 'سجل التدقيق']);
    assert.ok(reports.switches.every((item) => item.hint));
    assert.deepEqual(reports.sensitive, []);
});

test('review lists exact additions and removals when a custom level swaps actions', () => {
    const changes = individualChanges([subscriptions], [1, 2, 4], [1, 3, 5]);
    assert.deepEqual(changes.map(({ id, added, sensitive }) => ({ id, added, sensitive })), [
        { id: 2, added: false, sensitive: false }, { id: 3, added: true, sensitive: false },
        { id: 4, added: false, sensitive: true }, { id: 5, added: true, sensitive: true },
    ]);
});

test('payment permissions use action names and keep edit, refund, cancel and permanent delete independent', () => {
    const payments = sectionModel({ key: 'collections', label: 'الدفعات والحركات المالية', actions: [
        entry('view', 10), entry('record', 11), entry('correct', 12), entry('amend', 13), entry('refund', 14), entry('delete', 15), entry('force_delete', 16),
    ] });
    assert.equal(payments.ladder.labels[1], 'إضافة دفعة');
    assert.deepEqual(withToggled([10, 11], 12, true), [10, 11, 12]);
    assert.deepEqual(individualChanges([payments], [10], [10, 11]).map((item) => item.label), ['إضافة دفعة']);
    assert.equal(payments.sensitive.find((item) => item.id === 14).label, 'ردّ الدفعة');
    assert.equal(payments.sensitive.find((item) => item.id === 15).label, 'إلغاء الحركات المالية');
    assert.equal(payments.sensitive.find((item) => item.id === 16).label, 'الحذف النهائي للحركة');
});

test('each way to be paid and each kind of balance line is its own permission, and the ways follow «إضافة دفعة»', () => {
    const payments = sectionModel({ key: 'collections', label: 'الدفعات والحركات المالية', actions: [
        entry('view', 10), entry('record', 11), entry('record_cash', 12), entry('record_transfer', 13), entry('charge', 14), entry('discount', 15), entry('clearing', 16),
    ] });
    assert.deepEqual(payments.switches.map((item) => [item.id, item.label]), [[12, 'الدفع نقدًا'], [13, 'الدفع بتحويل بنكي']]);
    assert.deepEqual(payments.sensitive.map((item) => [item.id, item.label]), [[14, 'إضافة تحميل'], [15, 'إضافة خصم'], [16, 'إضافة مقاصة']]);

    // Raised to «إضافة دفعة» with no way to be paid: both ways come on; with one already, it is kept as it is.
    assert.deepEqual(withSectionLevel(payments, [14], 2).sort(), [10, 11, 12, 13, 14]);
    assert.deepEqual(withSectionLevel(payments, [13], 2).sort(), [10, 11, 13]);
    // Dropped below it: the ways go too, and the other permissions stay.
    assert.deepEqual(withSectionLevel(payments, [10, 11, 12, 13, 15], 1).sort(), [10, 15]);
});

test('extra options come in groups of related work, each with its risk and the level it needs', () => {
    const payments = sectionModel({ key: 'collections', label: 'الدفعات والحركات المالية', actions: [
        entry('view', 10), entry('record', 11), entry('record_cash', 12), entry('record_transfer', 13), entry('charge', 14), entry('refund', 15), entry('close_boxes', 16),
    ] });
    const extras = sectionExtras(payments);

    assert.deepEqual(extras.map((group) => [group.title, group.items.map((item) => item.id)]), [
        ['طرق الدفع المسموحة', [12, 13]], ['حركات على الرصيد', [14]], ['تصحيح الحركات', [15]], ['الصناديق', [16]],
    ]);
    const item = (id) => extras.flatMap((group) => group.items).find((candidate) => candidate.id === id);
    assert.deepEqual([item(12).risk, item(12).needs], [null, 2]);
    assert.deepEqual([item(14).risk, item(14).needs], ['sensitive', 0]);
    assert.deepEqual([item(15).risk, item(15).needs], ['danger', 0]);
    assert.equal(sectionSentence(payments, [10, 11]), 'يعرض السجل المالي ويضيف دفعات');
});

test('lowering a level takes away the options that need a higher one', () => {
    const readings = sectionModel({ key: 'meter_readings', label: 'القراءات', actions: [entry('view', 10), entry('record', 11), entry('browse_weeks', 12), entry('correct', 13), entry('approve', 14)] });

    // Down to «عرض»: correcting needs «تسجيل» and goes; browsing weeks and approving need only «عرض» and stay.
    assert.deepEqual(withSectionLevel(readings, [10, 11, 12, 13, 14], 1).sort(), [10, 12, 14]);
    // Down to nothing: every option that needs a level goes.
    assert.deepEqual(withSectionLevel(readings, [10, 11, 12, 13, 14], 0), []);
});

test('search and filters reveal relevant actions without mutating selected grants', () => {
    const readings = sectionModel({ key: 'meter_readings', label: 'القراءات', actions: [entry('view', 10), entry('record', 11), entry('correct', 12)] });
    const selected = [1, 4, 10];
    assert.deepEqual(filterSections([subscriptions, readings], selected, [1, 10], 'تصحيح', 'all').map((item) => item.key), ['meter_readings']);
    assert.deepEqual(filterSections([subscriptions, readings], selected, [1, 10], '', 'changed').map((item) => item.key), ['subscriptions']);
    assert.equal(filterSections([subscriptions, readings], [], [], '', 'enabled').length, 0);
    assert.deepEqual(selected, [1, 4, 10]);
});

test('view-only group shortcut preserves report access, does not grant cross-branch access or export, and leaves other sections intact', () => {
    const reportScope = sectionModel({ key: 'reports', label: 'التقارير', actions: [entry('view', 10), entry('view_all', 12)] });
    const reports = sectionModel({ key: 'reports', label: 'التقارير', actions: [entry('debt_aging', 14), entry('export', 15)] });
    assert.deepEqual(withGroupAccess([reportScope, reports], [99, 10, 15], true), [99, 10, 14]);
    assert.deepEqual(withGroupAccess([reportScope, reports], [99, 12, 15], true), [99, 10, 12, 14]);
    assert.deepEqual(withGroupAccess([reportScope, reports], [99, 12, 15], false), [99]);
});

test('browsing earlier reading weeks is a viewing switch, and editing the last reading a sensitive one', () => {
    const readings = sectionModel({
        key: 'meter_readings',
        label: 'القراءات',
        actions: [entry('view', 10), entry('record', 11), entry('browse_weeks', 12), entry('correct', 13), entry('correct_last', 14)],
    });
    assert.deepEqual(readings.switches.map((item) => [item.id, item.label]), [[12, 'الأسابيع السابقة']]);
    assert.deepEqual(readings.sensitive.map((item) => [item.id, item.label]), [[13, 'تصحيح القراءة الأسبوعية'], [14, 'تعديل القراءة السابقة واعتمادها']]);
    assert.deepEqual(withGroupAccess([readings], [10, 11, 13, 14], true), [10, 12]);
});
