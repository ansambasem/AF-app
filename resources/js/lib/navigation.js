const PREFETCH_PAGES = new Set(['/profile', '/settings/mobile-app', '/governorates', '/tariffs', '/circuit-breakers']);

/** Only small lookup pages are fetched on hover; financial lists wait for an explicit visit. */
export function shouldPrefetchPage(href) {
    return typeof href === 'string' && PREFETCH_PAGES.has(href.split(/[?#]/)[0]);
}

/**
 * Every page in the top bar and the Ctrl+K search. `can` names the flag in
 * the shared `can` props (HandleInertiaRequests) that must be true to open
 * the page; links without it are open to all. The top bar shows every page
 * to everyone, those not open to the user locked; the search lists only the
 * pages they may open.
 */
export const MAIN_LINKS = [
    { href: '/dashboard', label: 'لوحة التحكم', description: 'نظرة اليوم: التحصيل والمهام', icon: 'grid' },
    { href: '/payments', label: 'تسجيل الدفعات', description: 'استلام دفعة من مشترك وطباعة الوصل', icon: 'banknotes', can: 'recordPayments', exact: true },
    { href: '/payments/summary', label: 'ملخص التحصيل', description: 'ما حُصّل اليوم وهذا الشهر', icon: 'gauge', can: 'viewOwnCashBoxes' },
    { href: '/payments/cash-boxes', label: 'الصناديق المالية', description: 'إغلاق صناديق المحصّلين وتصفح المغلقة حسب الفترات الأسبوعية', icon: 'wallet', can: 'viewCashBoxes' },
    { href: '/ledger', label: 'السجل المالي', description: 'القيود حسب التاريخ والفرع', icon: 'ledger', can: 'viewLedger' },
    { href: '/receivables', label: 'أعمار الديون', description: 'الذمم المتأخرة حسب مدتها', icon: 'clock', can: 'viewDebtAging' },
    { href: '/transaction-audit', label: 'سجل التدقيق', description: 'من غيّر ماذا ومتى', icon: 'history', can: 'viewTransactionAudit' },
    { href: '/branch-performance', label: 'أداء الفروع', description: 'مقارنة نسب التحصيل بين الفروع', icon: 'trend', can: 'viewBranchPerformance' },
    { href: '/reports', label: 'التقارير', description: 'تقارير التحصيل والقراءات الجاهزة', icon: 'receipt', can: 'viewReports' },
    { href: '/company-forms', label: 'نماذج الشركة', description: 'تخصيص النماذج وطباعة نسخ فارغة ومعبأة وأرشفة الطلبات الورقية', icon: 'document-plus', can: 'viewCompanyForms' },
    { href: '/subscriptions', label: 'المشتركون', description: 'البحث عن مشترك وإضافته وتعديله', icon: 'users', can: 'viewSubscriptions' },
    { href: '/meter-boxes', label: 'الطبلونات', description: 'الطبلونات وأماكنها ومشتركوها', icon: 'table', can: 'viewMeterBoxes' },
    { href: '/meter-readings', label: 'القراءات الأسبوعية', description: 'قراءات العدادات لهذه الدورة', icon: 'chart', can: 'viewMeterReadings' },
    { href: '/reading-cycles', label: 'توزيع القراءات', description: 'توزيع الطبلونات على القرّاء والكشوف الورقية', icon: 'layers', can: 'viewReadingCycles' },
    { href: '/disconnection-lists', label: 'قوائم القطع', description: 'المدرجون للقطع ومواعيدهم وإنذاراتهم', icon: 'power', can: 'viewDisconnections' },
    { href: '/messages', label: 'الرسائل', description: 'تنبيهات الدفع والقطع للمشتركين', icon: 'messages', can: 'viewMessages' },
    { href: '/users', label: 'المستخدمون', description: 'الحسابات والأدوار والفروع', icon: 'user', can: 'viewUsers' },
];

export const SETTINGS_LINKS = [
    { href: '/settings/mobile-app', label: 'تحميل تطبيق الهاتف', description: 'تثبيت تطبيق PowerCollect على Android', icon: 'phone' },
    { href: '/settings/payments', label: 'إعدادات الدفعات', description: 'فترات الصناديق وأسعار الصرف وبنوك الاستلام والإرسال', icon: 'currency', can: 'managePaymentSettings' },
    { href: '/branches', label: 'الفروع', description: 'الفروع ومسؤولوها ومناطقها', icon: 'pin', can: 'viewBranches' },
    { href: '/tariffs', label: 'التعرفات', description: 'سعر الأمبير وشرائح الاشتراك', icon: 'dollar', can: 'viewTariffs' },
    { href: '/circuit-breakers', label: 'القواطع', description: 'أحجام القواطع المتاحة للمشتركين', icon: 'bolt', can: 'viewCircuitBreakers' },
    { href: '/governorates', label: 'المحافظات', description: 'المحافظات والمناطق والأحياء', icon: 'map', can: 'viewGovernorates' },
    { href: '/settings/permissions', label: 'الصلاحيات', description: 'ما يراه كل دور وما يعدّله', icon: 'shield', can: 'manageSettings' },
    { href: '/settings/reading-schedule', label: 'مواعيد القراءات', description: 'أيام فتح وإغلاق دورة القراءة', icon: 'calendar', can: 'manageReadingSchedule' },
    { href: '/settings/print-templates', label: 'قوالب الطباعة', description: 'شكل الوصل والكشوفات المطبوعة', icon: 'printer', can: 'managePrintTemplates' },
    { href: '/settings/sms', label: 'إعدادات الرسائل', description: 'ربط مزوّد الرسائل القصيرة SMS', icon: 'send', can: 'manageSmsSettings' },
];

const linksByHref = new Map([...MAIN_LINKS, ...SETTINGS_LINKS].map((link) => [link.href, link]));

/** The first section of the top bar: the dashboard, under the name of home. */
export const HOME_LINK = { href: '/dashboard', label: 'الرئيسية', icon: 'home' };

/** A page outside the sections, named in the page trail when it is open. */
export const PROFILE_LINK = { href: '/profile', label: 'الملف الشخصي', description: 'بياناتك وكلمة المرور', icon: 'user' };

/** A collector's own boxes, named as theirs. */
const OWN_LABELS = { '/payments/cash-boxes': 'صناديقي' };

/** Whether the user sees only their own boxes, not the branch's. */
function seesOwnBoxesOnly(can) {
    return Boolean(can?.viewOwnCashBoxes && !can?.viewBranchBoxes);
}

export const NAVIGATION_GROUPS = [
    { id: 'daily', label: 'المشتركون', shortLabel: 'المشتركون', icon: 'users', description: 'المشتركون والطبلونات والقراءات والرسائل', hrefs: ['/subscriptions', '/meter-boxes', '/meter-readings', '/reading-cycles', '/disconnection-lists', '/messages'] },
    { id: 'finance', label: 'المالية والتحصيل', shortLabel: 'المالية', icon: 'wallet', description: 'الدفعات والصناديق والإغلاق', hrefs: ['/payments', '/payments/summary', '/payments/cash-boxes', '/ledger', '/receivables'] },
    { id: 'reports', label: 'التقارير والرقابة', shortLabel: 'التقارير', icon: 'trend', description: 'التقارير والأداء والتدقيق', hrefs: ['/reports', '/company-forms', '/branch-performance', '/transaction-audit'] },
    { id: 'infrastructure', label: 'الفروع والشبكة', shortLabel: 'الشبكة', icon: 'bolt', description: 'الفروع والبنية الكهربائية', hrefs: ['/branches', '/governorates', '/circuit-breakers'] },
    { id: 'administration', label: 'الإدارة والإعدادات', shortLabel: 'الإدارة', icon: 'cog', description: 'المستخدمون والصلاحيات والإعدادات', hrefs: ['/users', '/settings/permissions', '/tariffs', '/settings/payments', '/settings/reading-schedule', '/settings/print-templates', '/settings/sms', '/settings/mobile-app'], featured: ['/users', '/settings/permissions'] },
];

/**
 * The top bar's task groups, the same for every user: all of each group's
 * pages, those the user may not open marked `locked` (they open the
 * no-access page), so the bar keeps its shape whatever the permissions.
 */
export function navigationGroups(can) {
    return NAVIGATION_GROUPS.map(({ hrefs, ...group }) => {
        const links = hrefs.map((href) => linksByHref.get(href));
        const open = new Set(allowedLinks(links, can));

        return { ...group, links: links.map((link) => ({ ...navigationLink(link, can), locked: !open.has(link) })) };
    });
}

/** Task groups containing only the pages the current user may open, for the search. */
export function allowedNavigationGroups(can) {
    return navigationGroups(can)
        .map((group) => ({ ...group, links: group.links.filter((link) => !link.locked) }))
        .filter((group) => group.links.length > 0);
}

/** Branch staff open the movements tab; collectors and auditors keep their box lists. */
function navigationLink(link, can) {
    if (link.href === '/payments/cash-boxes' && can?.viewBranchBoxes && !can?.auditOnly) {
        return { ...link, href: '/payments/cash-boxes/entries', activePaths: [link.href] };
    }

    return seesOwnBoxesOnly(can) && OWN_LABELS[link.href] ? { ...link, label: OWN_LABELS[link.href] } : link;
}

/**
 * What the top bar shows in its middle: home, then one button per section
 * that opens its pages. A user with a single section sees its pages directly.
 */
export function topBarItems(groups) {
    const home = { key: 'home', link: HOME_LINK };

    if (groups.length === 1) {
        return [home, ...groups[0].links.map((link) => ({ key: link.href, link }))];
    }

    return [home, ...groups.map((group) => ({ key: group.id, group }))];
}

/** The page a section opens when clicked: its first page the user may open, if any. */
export function groupLandingLink(group) {
    return group.links.find((link) => !link.locked);
}

/** A locked page's link shows its lock but opens nothing, clicked or entered. */
export function lockedLinkProps(link) {
    return link.locked ? { 'aria-disabled': true, onClick: (event) => event.preventDefault() } : {};
}

/** The group holding the current page, if any. */
export function currentGroup(groups, url) {
    return groups.find((group) => isInsideAnyLink(group.links, url));
}

/**
 * The page the user is on, its group and the group's pages, for the trail
 * above the page and the strip of the section's pages below its title.
 */
export function pageTrail(groups, url) {
    const group = currentGroup(groups, url);
    const link = group?.links.find((candidate) => isActiveLink(candidate, url))
        ?? [MAIN_LINKS[0], PROFILE_LINK].find((candidate) => isActiveLink(candidate, url));

    return link ? { group, link, exact: url.split('?')[0] === link.href } : null;
}

/** The links the current user may open. */
export function allowedLinks(links, can) {
    if (can?.auditOnly) {
        return links.filter((link) => ['/payments/cash-boxes', '/subscriptions', '/ledger', '/transaction-audit'].includes(link.href) && can?.[link.can]);
    }

    return links.filter((link) => !link.can || can?.[link.can]);
}

/** Whether `url` (the current page, query string included) is inside the link's section. */
export function isActiveLink(link, url) {
    const path = url.split('?')[0];

    return [link.href, ...(link.activePaths ?? [])].some((href) => path === href || (!link.exact && path.startsWith(`${href}/`)));
}

/** Whether `url` is inside any of the links' sections. */
export function isInsideAnyLink(links, url) {
    return links.some((link) => isActiveLink(link, url));
}
