import { test } from 'node:test';
import assert from 'node:assert/strict';
import { MAIN_LINKS, SETTINGS_LINKS, allowedNavigationGroups, groupLandingLink, isActiveLink, lockedLinkProps, navigationGroups, isInsideAnyLink, pageTrail, topBarItems, shouldPrefetchPage } from '../../resources/js/lib/navigation.js';

test('hover navigation avoids background requests for financial and reading pages', () => {
    for (const href of ['/dashboard', '/subscriptions?sort=name', '/meter-readings', '/reading-cycles', '/reports', '/ledger', '/payments/summary', '/disconnection-lists', '/unknown']) {
        assert.equal(shouldPrefetchPage(href), false, href);
    }
    for (const href of ['/profile', '/settings/mobile-app', '/governorates?selected=1', '/tariffs', '/circuit-breakers#list']) {
        assert.equal(shouldPrefetchPage(href), true, href);
    }
    assert.equal(shouldPrefetchPage('https://untrusted.test/profile'), false);
});

test('auditors see subscriber accounts and audit destinations without administration even with legacy grants', () => {
    const can = Object.fromEntries([...MAIN_LINKS, ...SETTINGS_LINKS].filter((link) => link.can).map((link) => [link.can, true]));
    const groups = allowedNavigationGroups({ ...can, auditOnly: true });

    assert.deepEqual(groups.flatMap((group) => group.links.map((link) => link.href)), ['/subscriptions', '/payments/cash-boxes', '/ledger', '/transaction-audit']);
});

test('the settings group counts as current on any settings page, including its create and edit pages', () => {
    assert.equal(isInsideAnyLink(SETTINGS_LINKS, '/settings/permissions'), true);
    assert.equal(isInsideAnyLink(SETTINGS_LINKS, '/meter-boxes/4/edit?tab=boxes'), true);
    assert.equal(isInsideAnyLink(SETTINGS_LINKS, '/subscriptions'), false);
    assert.equal(isInsideAnyLink(SETTINGS_LINKS, '/branch-performance'), false);
});

test('currencies and banks share one payment settings destination', () => {
    const links = allowedNavigationGroups({ managePaymentSettings: true }).flatMap((group) => group.links);

    assert.deepEqual(links.map((link) => [link.href, link.label]), [['/settings/payments', 'إعدادات الدفعات'], ['/settings/mobile-app', 'تحميل تطبيق الهاتف']]);
});

test('regrouping keeps every permitted destination exactly once', () => {
    const allLinks = [...MAIN_LINKS, ...SETTINGS_LINKS];
    const can = Object.fromEntries(allLinks.filter((link) => link.can).map((link) => [link.can, true]));

    const links = allowedNavigationGroups(can).flatMap((group) => group.links);

    assert.deepEqual(links.map((link) => link.href).sort(), allLinks.filter((link) => link.href !== '/dashboard').map((link) => link.href).sort());
});

test('users see permitted business links and can always reach the mobile app download', () => {
    const groups = allowedNavigationGroups({ viewSubscriptions: true, viewTariffs: true });

    assert.deepEqual(groups.map((group) => ({ id: group.id, hrefs: group.links.map((link) => link.href) })), [
        { id: 'daily', hrefs: ['/subscriptions'] },
        { id: 'administration', hrefs: ['/tariffs', '/settings/mobile-app'] },
    ]);
    assert.deepEqual(allowedNavigationGroups().flatMap((group) => group.links.map((link) => link.href)), ['/settings/mobile-app']);
    assert.deepEqual(allowedNavigationGroups({ viewSubscriptions: false }).flatMap((group) => group.links.map((link) => link.href)), ['/settings/mobile-app']);
});

test('nested and query-string pages identify their task group without matching a similar prefix', () => {
    const groups = allowedNavigationGroups({ viewMeterBoxes: true, manageSettings: true });
    const infrastructure = groups.find((group) => group.id === 'infrastructure');
    const administration = groups.find((group) => group.id === 'administration');

    assert.equal(isInsideAnyLink(infrastructure.links, '/meter-boxes/4/edit?tab=boxes'), true);
    assert.equal(isInsideAnyLink(administration.links, '/settings/permissions?tab=roles'), true);
    assert.equal(isActiveLink(infrastructure.links[0], '/meter-boxes-archive'), false);
});

test('retired closing destinations stay absent even with old permission flags', () => {
    const links = allowedNavigationGroups({ viewClosings: true, manageClosingSchedule: true, viewLedger: true, viewReports: true }).flatMap((group) => group.links);

    assert.deepEqual(links.map((link) => link.href), ['/ledger', '/reports', '/settings/mobile-app']);
    assert.equal([...MAIN_LINKS, ...SETTINGS_LINKS].some((link) => link.href.includes('closing') || link.href.includes('cash-transfers')), false);
});

test('staff with box access can reach their boxes without payment access and only the box link is active', () => {
    const groups = allowedNavigationGroups({ viewOwnCashBoxes: true, viewCashBoxes: true });
    const boxes = groups[0].links.find((link) => link.href === '/payments/cash-boxes');
    assert.deepEqual(groups.flatMap((group) => group.links.map((link) => link.href)), ['/payments/summary', '/payments/cash-boxes', '/settings/mobile-app']);
    assert.equal(isActiveLink(MAIN_LINKS.find((link) => link.href === '/payments'), '/payments/cash-boxes/12'), false);
    assert.equal(isActiveLink(boxes, '/payments/cash-boxes/12'), true);
});

test('closing and reviewing boxes lives in the financial boxes link, whose tabs light it too', () => {
    const links = allowedNavigationGroups({ viewCashBoxes: true }).flatMap((group) => group.links);

    assert.deepEqual(links.map((link) => [link.href, link.label]), [['/payments/cash-boxes', 'الصناديق المالية'], ['/settings/mobile-app', 'تحميل تطبيق الهاتف']]);
    assert.equal(isActiveLink(links[0], '/payments/cash-boxes?branch_id=2&place=sent&box=7'), true);
    assert.equal(isActiveLink(links[0], '/payments/cash-boxes/entries?branch_id=2'), true);
});

test('the top bar keeps every section and page for every user, locking those they may not open', () => {
    const everything = Object.fromEntries([...MAIN_LINKS, ...SETTINGS_LINKS].filter((link) => link.can).map((link) => [link.can, true]));
    const collector = navigationGroups({ recordPayments: true, viewOwnCashBoxes: true, viewCashBoxes: true });
    const admin = navigationGroups(everything);
    const shape = (groups) => groups.map((group) => [group.id, group.links.map((link) => link.href)]);

    assert.deepEqual(shape(collector), shape(admin));
    assert.deepEqual(shape(navigationGroups()), shape(admin));
    assert.equal(admin.every((group) => group.links.every((link) => !link.locked)), true);
    assert.deepEqual(
        collector.flatMap((group) => group.links).filter((link) => !link.locked).map((link) => link.label),
        ['تسجيل الدفعات', 'ملخص التحصيل', 'صناديقي', 'تحميل تطبيق الهاتف'],
    );
    assert.equal(collector.find((group) => group.id === 'administration').links.filter((link) => link.href !== '/settings/mobile-app').every((link) => link.locked), true);
});

test('a collector who sees only their own boxes finds them named as theirs', () => {
    const own = allowedNavigationGroups({ recordPayments: true, viewOwnCashBoxes: true, viewCashBoxes: true }).flatMap((group) => group.links);
    const branch = allowedNavigationGroups({ viewOwnCashBoxes: true, viewCashBoxes: true, viewBranchBoxes: true }).flatMap((group) => group.links);

    assert.deepEqual(own.map((link) => link.label), ['تسجيل الدفعات', 'ملخص التحصيل', 'صناديقي', 'تحميل تطبيق الهاتف']);
    assert.deepEqual(branch.map((link) => link.label).slice(0, 2), ['ملخص التحصيل', 'الصناديق المالية']);
});

test('the top bar shows home and one button per section, or a single section\'s pages directly', () => {
    const single = topBarItems(allowedNavigationGroups());
    const several = topBarItems(allowedNavigationGroups({ viewSubscriptions: true, viewLedger: true }));

    assert.deepEqual(single.map((item) => item.key), ['home', '/settings/mobile-app']);
    assert.equal(single.every((item) => item.link && !item.group), true);
    assert.deepEqual(several.map((item) => item.key), ['home', 'daily', 'finance', 'administration']);
    assert.equal(several[0].link.href, '/dashboard');
});

test('clicking a section opens its first page the user may open, or nothing when all are locked', () => {
    const everything = Object.fromEntries([...MAIN_LINKS, ...SETTINGS_LINKS].filter((link) => link.can).map((link) => [link.can, true]));
    const landing = (groups) => Object.fromEntries(groups.map((group) => [group.id, groupLandingLink(group)?.href ?? null]));

    assert.deepEqual(landing(navigationGroups(everything)), {
        daily: '/subscriptions',
        finance: '/payments',
        reports: '/reports',
        infrastructure: '/branches',
        administration: '/users',
    });
    assert.deepEqual(landing(navigationGroups({ viewOwnCashBoxes: true, viewMeterReadings: true })), {
        daily: '/meter-readings',
        finance: '/payments/summary',
        reports: null,
        infrastructure: null,
        administration: '/settings/mobile-app',
    });
});

test('a locked page\'s link opens nothing while an open one is left alone', () => {
    const [locked, open] = [{ href: '/users', locked: true }, { href: '/subscriptions', locked: false }];
    const event = { defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };

    lockedLinkProps(locked).onClick(event);

    assert.equal(lockedLinkProps(locked)['aria-disabled'], true);
    assert.equal(event.defaultPrevented, true);
    assert.deepEqual(lockedLinkProps(open), {});
});

test('the page trail names the section and page, also from a page inside it', () => {
    const groups = allowedNavigationGroups({ viewCashBoxes: true, viewSubscriptions: true });
    const box = pageTrail(groups, '/payments/cash-boxes/12?tab=entries');
    const list = pageTrail(groups, '/subscriptions');

    assert.deepEqual([box.group.id, box.link.href, box.exact], ['finance', '/payments/cash-boxes', false]);
    assert.deepEqual([list.group.id, list.link.href, list.exact], ['daily', '/subscriptions', true]);
    assert.deepEqual([pageTrail(groups, '/dashboard').group, pageTrail(groups, '/dashboard').link.label], [undefined, 'لوحة التحكم']);
    assert.equal(pageTrail(groups, '/profile').link.label, 'الملف الشخصي');
    assert.equal(pageTrail(groups, '/ledger'), null);
});
