/**
 * The permissions editor's model (Pages/Settings/Permissions.jsx). Each
 * section's everyday permissions climb a ladder — view, then add, then edit
 * — so one level stands for all the permissions up to it. Sensitive ones
 * (delete, approve, charges and discounts…) are never part of a level: each is
 * its own switch. Every level is just a set of the real permissions, so
 * an employee whose permissions don't sit on the ladder (say, edit
 * without add) shows as "custom" with the exact switches, never rounded.
 */

/** Each section's ladder: its everyday actions in order, and what each level is called. */
const LADDERS = {
    crud: { actions: ['view', 'create', 'update'], levels: ['بدون', 'عرض', 'إضافة', 'تعديل'], long: ['لا يرى القسم', 'يرى فقط', 'يرى ويضيف', 'يرى ويضيف ويعدّل'] },
    meter_readings: { actions: ['view', 'record'], levels: ['بدون', 'عرض', 'تسجيل'], long: ['لا يرى القراءات', 'يرى القراءات', 'يرى ويسجّل القراءات'] },
    collections: { actions: ['view', 'record'], levels: ['بدون', 'عرض السجل', 'إضافة دفعة'], long: ['لا يملك عرض السجل أو إضافة دفعة', 'يعرض السجل المالي', 'يعرض السجل المالي ويضيف دفعات'] },
    disconnections: { actions: ['view', 'manage'], levels: ['بدون', 'عرض', 'إدارة'], long: ['لا يرى قوائم القطع', 'يرى قوائم القطع', 'يرى القوائم ويضيف ويزيل ويمدد المواعيد'] },
    reading_assignments: { actions: ['view', 'manage'], levels: ['بدون', 'عرض', 'توزيع'], long: ['لا يرى توزيع القراءات', 'يرى توزيع القراءات', 'يرى ويوزّع الطبلونات على القرّاء والورق'] },
    messages: { actions: ['view', 'send'], levels: ['بدون', 'عرض', 'إرسال'], long: ['لا يرى الرسائل', 'يرى الرسائل', 'يرى ويرسل الرسائل'] },
    print_templates: { actions: ['customize', 'manage'], levels: ['بدون', 'تخصيص', 'إدارة'], long: ['يطبع بالقوالب المحفوظة كما هي', 'يغيّر الأعمدة والترتيب والشكل قبل أن يطبع', 'يخصص ويحفظ ويدير قوالب الطباعة المشتركة'] },
};

/** Sections whose everyday actions aren't a ladder (each is its own switch). */
const NO_LADDER = ['reports', 'financial_audit'];

/**
 * The permissions set apart from the levels, keyed by action or by
 * `section.action`, each with how much care it takes: `sensitive` ones
 * touch balances or people's data, `danger` ones change money or the
 * service outright and must be acknowledged before they are saved.
 */
export const SENSITIVE = {
    'subscriptions.archive': { label: 'أرشفة الاشتراكات المنتهية', hint: 'للاشتراك المفصول فقط، بعد استكمال القراءات، مع سبب وتاريخ إنهاء الخدمة؛ لا يحذف السجل أو يصفّر الرصيد', risk: 'danger' },
    'subscriptions.view_archived': { label: 'عرض الاشتراكات المؤرشفة', hint: 'يعرض الملف والقراءات وكشف الحساب وسجل الأرشفة ضمن نطاق الفرع', risk: 'sensitive' },
    'subscriptions.restore_archived': { label: 'استعادة الاشتراكات المؤرشفة', hint: 'يعيد الاشتراك إلى القائمة بحالة مفصول؛ إعادة تشغيل الخدمة إجراء مستقل', risk: 'danger' },
    'financial_audit.audit': { label: 'تفعيل التدقيق المالي', hint: 'يمنحها السوبر أدمن لأشخاص محددين، مع تحديد الفروع وإجراءات مراجعة البنك واعتماد الصناديق والفترات', risk: 'sensitive' },
    'financial_audit.view_all_boxes': { label: 'عرض الصناديق المغلقة لجميع الفروع', hint: 'عرض فقط؛ لا يمنح تسجيل مبلغ البنك أو ترحيل فروق التدقيق أو اعتمادها', risk: 'sensitive' },
    'print_templates.print_company_forms': { label: 'طباعة وأرشفة نماذج الشركة', hint: 'يصدر نماذج فارغة أو معبأة ويؤرشف الطلبات الورقية في فرعه؛ تخصيص القوالب يحتاج صلاحية الإدارة', risk: 'sensitive' },
    'branches.manage_domains': { label: 'إدارة روابط الفروع', hint: 'يربط دومينًا بفرع أو يوقفه من اللوحة المركزية؛ يحتاج ربط DNS وHTTPS في الاستضافة', risk: 'danger' },
    'users.manage_permissions': { label: 'إدارة صلاحيات موظفي الفرع', hint: 'يمنح ويسحب الصلاحيات المسموح بتفويضها والتي يملكها، لموظفي فرعه فقط. إضافة حساب موظف تحتاج خيار «إضافة المستخدمين»؛ تفويض إدارة الصلاحيات يبقى للسوبر أدمن', risk: 'danger' },
    'users.manage_passwords': { label: 'تغيير كلمات مرور موظفي الفرع', hint: 'يحتاج أيضًا صلاحية تعديل المستخدمين؛ لا يغيّر كلمة مرور مدير صلاحيات آخر', risk: 'danger' },
    'users.manage_branch_schedules': { label: 'إدارة مواعيد الفرع', hint: 'يضبط مواعيد إدخال القراءات لفرعه فقط', risk: 'sensitive' },
    'collections.manage_branch_closings': { label: 'إدارة واعتماد صناديق الفرع', hint: 'يدير تسليم صناديق فرعه واعتمادها وإعدادات المسؤولين عنها', risk: 'danger' },
    delete: { label: 'الحذف', hint: 'يحذف ما لا يرتبط به شيء', risk: 'danger' },
    minimum_charge: { label: 'تعديل الحد الأدنى للدفع', hint: 'يغيّر الحد الأدنى الأسبوعي لمشترك', risk: 'sensitive' },
    kilowatt_price: { label: 'تعديل سعر الكيلو للمشترك', hint: 'يعطي المشترك سعرًا غير سعر تعرفته، أعلى أو أقل', risk: 'danger' },
    approve: { label: 'اعتماد القراءات', hint: 'تُضاف مبالغها إلى حسابات المشتركين', risk: 'danger' },
    'collections.charge': { label: 'إضافة تحميل', hint: 'غرامة أو رسوم تُضاف على حساب المشترك', risk: 'sensitive' },
    'collections.discount': { label: 'إضافة خصم', hint: 'خصم مرة واحدة، أو خصم دائم على القراءات الأسبوعية وإيقافه', risk: 'sensitive' },
    'collections.clearing': { label: 'إضافة مقاصة', hint: 'تسوية رصيد المشترك بقيد مقاصة', risk: 'sensitive' },
    correct: { label: 'تعديل مبلغ الحركة', hint: 'تصحيح مبلغ تحميل أو دفعة، مع حفظ السبب والأثر المالي', risk: 'danger' },
    'meter_readings.correct': { label: 'تصحيح القراءة الأسبوعية', hint: 'يعدّل قراءة الأسبوع المتاح؛ المعتمدة تعود للاعتماد', risk: 'sensitive' },
    'meter_readings.correct_last': { label: 'تعديل القراءة السابقة واعتمادها', hint: 'من خانة «القراءة السابقة»؛ بعد التأكيد تُعتمد المعتمدة منهما مباشرة', risk: 'sensitive' },
    'subscriptions.bulk_update': { label: 'تعديل المشتركين جماعيًا', hint: 'الحالة أو الحد الأدنى لمجموعة، مع صلاحية تعديل الحقل نفسه', risk: 'sensitive' },
    'collections.amend': { label: 'تعديل بيانات الدفعة', hint: 'البنك والمرسل والرقم المرجعي والملاحظات؛ لا يغيّر المبلغ', risk: 'sensitive' },
    'collections.close_boxes': { label: 'إغلاق صناديق المحصّلين', hint: 'يراجع صناديق محصّلي الفرع ويؤكّد إغلاقها؛ لا صناديقه هو', risk: 'sensitive' },
    'collections.manage_box_periods': { label: 'تعديل بداية فترات الصناديق', hint: 'يمنحها المدير العام لمسؤول الفرع لتعديل يوم بداية فترات فرعه فقط', risk: 'sensitive' },
    'collections.refund': { label: 'ردّ الدفعة', hint: 'يردّ المبلغ بقيد مرتبط؛ تبقى الدفعة وسجل الردّ ظاهرين', risk: 'danger' },
    'collections.delete': { label: 'إلغاء الحركات المالية', hint: 'إلغاء حركة بقيد عكسي، وتبقى ظاهرة في الكشف', risk: 'danger' },
    'collections.force_delete': { label: 'الحذف النهائي للحركة', hint: 'يحذفها من الكشف مع الاحتفاظ بسجل تدقيق؛ لا يحذف القراءة', risk: 'danger' },
    'disconnections.warn': { label: 'إرسال إنذارات القطع', hint: 'يرسل رسائل للمشتركين ويرى أرقام هواتفهم', risk: 'sensitive' },
    'disconnections.execute': { label: 'تأكيد تنفيذ القطع', hint: 'يفصل خدمة المشترك ويحمّل عليه رسوم قطع الخدمة', risk: 'danger' },
    'disconnections.change_fee': { label: 'تغيير رسوم القطع عند التأكيد', hint: 'يغيّر رسوم القطع لأي مشترك أو يعفيه منها', risk: 'danger' },
    'reading_assignments.approve': { label: 'اعتماد توزيع القراءات', hint: 'يجعل الطبلونات متاحة للقرّاء لتنزيلها', risk: 'sensitive' },
    'reading_assignments.review': { label: 'مراجعة القراءات الميدانية', hint: 'يقبل أو يرفض ما وصل خارج يوم القراءة أو مع تعارض', risk: 'sensitive' },
    'reports.export': { label: 'تنزيل التقارير المالية', hint: 'تنزيل CSV للتقارير التي يملك عرضها فقط', risk: 'sensitive' },
};

/** The risk labels the editor shows beside a permission. */
export const RISK_LABELS = { sensitive: 'حساس', danger: 'خطير' };

/**
 * A section's extra options (everything off its levels) in small groups
 * of related work, in order; anything not named falls in a last group.
 */
const EXTRA_GROUPS = {
    subscriptions: [['تعديلات خاصة', ['minimum_charge', 'kilowatt_price', 'bulk_update']], ['الأرشفة وإنهاء الخدمة', ['view_archived', 'archive', 'restore_archived']], ['الحذف', ['delete']]],
    meter_readings: [['العمل على القراءات', ['browse_weeks', 'correct', 'correct_last']], ['الاعتماد', ['approve']]],
    collections: [
        ['طرق الدفع المسموحة', ['record_cash', 'record_transfer']],
        ['حركات على الرصيد', ['charge', 'discount', 'clearing']],
        ['تصحيح الحركات', ['amend', 'correct', 'refund', 'delete', 'force_delete']],
        ['الصناديق', ['close_boxes', 'manage_box_periods']],
    ],
    reading_assignments: [['الطباعة', ['print_sheets']], ['الاعتماد والمراجعة', ['approve', 'review']]],
    disconnections: [['التنفيذ', ['warn', 'execute', 'change_fee']]],
    reports: [['التقارير', ['view', 'view_all', 'branch_performance', 'debt_aging', 'transaction_audit']], ['التنزيل', ['export']]],
};

/**
 * The level an extra option needs first, as the action on its section's
 * ladder (`section.action`, or any section's `delete`). Without it the
 * option is offered off; lowering the level below it takes it away.
 */
const NEEDS = {
    'subscriptions.archive': 'view',
    'subscriptions.view_archived': 'view',
    'subscriptions.restore_archived': 'view',
    'subscriptions.minimum_charge': 'update',
    'subscriptions.kilowatt_price': 'update',
    'subscriptions.bulk_update': 'update',
    'meter_readings.browse_weeks': 'view',
    'meter_readings.correct': 'record',
    'meter_readings.correct_last': 'record',
    'meter_readings.approve': 'view',
    'collections.record_cash': 'record',
    'collections.record_transfer': 'record',
    'disconnections.warn': 'view',
    'disconnections.execute': 'manage',
    'disconnections.change_fee': 'manage',
    'reading_assignments.print_sheets': 'view',
    'reading_assignments.approve': 'view',
    'reading_assignments.review': 'view',
    delete: 'view',
};

/** The everyday actions shown as switches (a section without a ladder, or one cut short). */
export const ACTION_LABELS = {
    view: 'عرض',
    create: 'إضافة',
    update: 'تعديل',
    record: 'تسجيل',
    send: 'إرسال',
    manage: 'إدارة',
    browse_weeks: 'الأسابيع السابقة',
    view_all: 'عرض كل الفروع والتقارير',
    branch_performance: 'أداء الفروع',
    debt_aging: 'أعمار الديون',
    transaction_audit: 'سجل التدقيق',
    print_sheets: 'طباعة الكشوف الورقية',
    record_cash: 'الدفع نقدًا',
    record_transfer: 'الدفع بتحويل بنكي',
};

/** What an everyday switch opens, in a line under its name. */
const ACTION_HINTS = {
    browse_weeks: 'يتنقل بين أسابيع القراءات السابقة؛ بدونها يرى الأسبوع الحالي فقط',
    view_all: 'تقارير كل الفروع، للقراءة فقط',
    branch_performance: 'أرقام الفرع ومقارنته بالفروع الأخرى',
    debt_aging: 'المشتركون المدينون وكم مضى على ديونهم',
    transaction_audit: 'من عدّل أو ألغى أو حذف أي حركة، ومتى ولماذا',
    print_sheets: 'يطبع كشوف القراءة الورقية للطبلونات المخصصة للورق',
    record_cash: 'يظهر له «نقدًا» في نافذة الدفع، وتدخل الدفعة صندوقه',
    record_transfer: 'يظهر له «تحويل بنكي» في نافذة الدفع، ومعه الدفعة المقسّمة على عدة اشتراكات',
};

/** The ways to be paid, each its own switch beside «إضافة دفعة» (see withSectionLevel). */
const PAYMENT_METHOD_ACTIONS = ['record_cash', 'record_transfer'];

function sensitiveOf(sectionKey, action) {
    return SENSITIVE[`${sectionKey}.${action}`] ?? SENSITIVE[action] ?? null;
}

/**
 * A section as the editor lays it out: `ladder` (its level names and the
 * permission ids each level adds, in order — only as far as the actor may
 * grant without a gap), `switches` (everyday permissions off the ladder)
 * and `sensitive` (each with its label, hint and danger).
 */
export function sectionModel(group) {
    const available = group.actions.filter((entry) => entry.permission);
    const ladderDef = NO_LADDER.includes(group.key) ? null : (LADDERS[group.key] ?? LADDERS.crud);
    const ladderEntries = [];

    if (ladderDef) {
        for (const action of ladderDef.actions) {
            const entry = available.find((candidate) => candidate.action === action);

            if (!entry) {
                break;
            }

            ladderEntries.push(entry);
        }
    }

    const onLadder = new Set(ladderEntries.map((entry) => entry.action));
    const offLadder = available.filter((entry) => !onLadder.has(entry.action));

    return {
        key: group.key,
        label: group.label,
        permissions: available.map(({ action, permission }) => ({ ...permission, action })),
        ladder: ladderEntries.length
            ? {
                  ids: ladderEntries.map((entry) => entry.permission.id),
                  actions: ladderEntries.map((entry) => entry.action),
                  labels: ladderEntries.map((entry) => group.key === 'collections' ? ({ view: 'عرض السجل المالي', record: 'إضافة دفعة' }[entry.action] ?? entry.permission.label) : entry.permission.label),
                  levels: ladderDef.levels.slice(0, ladderEntries.length + 1),
                  long: ladderDef.long.slice(0, ladderEntries.length + 1),
              }
            : null,
        switches: offLadder
            .filter((entry) => !sensitiveOf(group.key, entry.action))
            .map((entry) => ({
                id: entry.permission.id,
                action: entry.action,
                label: (ACTION_LABELS[entry.action] ?? entry.permission.label),
                hint: (ACTION_HINTS[entry.action] ?? null),
            })),
        sensitive: offLadder
            .filter((entry) => sensitiveOf(group.key, entry.action))
            .map((entry) => {
                const details = sensitiveOf(group.key, entry.action);

                return { id: entry.permission.id, action: entry.action, ...details, danger: details.risk === 'danger' };
            }),
    };
}

/**
 * The level (1, 2…) on the section's ladder an extra option needs first,
 * or 0 when it needs none (or that level isn't one the actor may grant).
 */
export function neededLevel(section, action) {
    const need = NEEDS[`${section.key}.${action}`] ?? NEEDS[action];
    const index = need ? (section.ladder?.actions.indexOf(need) ?? -1) : -1;

    return index + 1;
}

/**
 * A section's extra options — its switches and special permissions — in
 * groups of related work: `[{ title, items: [{ id, action, label, hint, risk, needs }] }]`,
 * `risk` being null, 'sensitive' or 'danger' and `needs` the level they need first.
 */
export function sectionExtras(section) {
    const items = [
        ...section.switches.map((item) => ({ ...item, risk: null })),
        ...section.sensitive.map((item) => ({ ...item, risk: item.risk ?? (item.danger ? 'danger' : 'sensitive') })),
    ].map((item) => ({ ...item, needs: neededLevel(section, item.action) }));
    const groups = (EXTRA_GROUPS[section.key] ?? [])
        .map(([title, actions]) => ({ title, items: actions.map((action) => items.find((item) => item.action === action)).filter(Boolean) }))
        .filter((group) => group.items.length > 0);
    const placed = new Set(groups.flatMap((group) => group.items.map((item) => item.action)));
    const rest = items.filter((item) => !placed.has(item.action));

    return rest.length ? [...groups, { title: groups.length ? 'خيارات أخرى' : 'خيارات إضافية', items: rest }] : groups;
}

/**
 * What the selected permissions let someone do in a section, as one line:
 * its level's sentence, or for a section without levels the options held.
 */
export function sectionSentence(section, selectedIds) {
    if (section.ladder) {
        const level = levelOf(section.ladder, selectedIds);

        if (level !== null) {
            return section.ladder.long[level];
        }

        // Off the ladder (say, adding payments without viewing the log): name exactly what is held.
        return section.ladder.labels.filter((label, index) => selectedIds.includes(section.ladder.ids[index])).join('، ');
    }

    const held = sectionExtras(section).flatMap((group) => group.items).filter((item) => selectedIds.includes(item.id));

    return held.length ? held.map((item) => item.label).join('، ') : 'لا يملك شيئًا في هذا القسم';
}

/** Every permission id a section governs. */
export function sectionIds(section) {
    return [...(section.ladder?.ids ?? []), ...section.switches.map((item) => item.id), ...section.sensitive.map((item) => item.id)];
}

/** Exact action names for review, including custom combinations off a ladder. */
export function permissionEntries(sections) {
    return sections.flatMap((section) => [
        ...(section.ladder?.ids ?? []).map((id, index) => ({ id, action: section.ladder.actions[index], label: section.ladder.labels[index], sensitive: false })),
        ...section.switches.map((item) => ({ ...item, sensitive: false })),
        ...section.sensitive.map((item) => ({ ...item, sensitive: true, risk: item.risk ?? (item.danger ? 'danger' : 'sensitive') })),
    ].map((item) => ({ risk: null, ...item, section: section.label, sectionKey: section.key })));
}

export const PERMISSION_COLUMNS = [
    { key: 'view', label: 'عرض', actions: ['view'] },
    { key: 'create', label: 'إضافة', actions: ['create', 'record', 'send'] },
    { key: 'update', label: 'تعديل', actions: ['update', 'manage'] },
    { key: 'delete', label: 'حذف', actions: ['delete'] },
    { key: 'export', label: 'تصدير', actions: ['export'] },
];

/** Matrix cells are independent grants, even when the legacy model groups them into levels. */
export function permissionCells(section) {
    const entries = permissionEntries([section]);

    return PERMISSION_COLUMNS.map((column) => ({
        ...column,
        permission: entries.find((entry) => column.actions.includes(entry.action)) ?? null,
    }));
}

/** Copy work permissions only; financial audit access and its scope remain a separate decision. */
export function copyWorkPermissions(sections, selectedIds, sourceIds) {
    const workIds = sections.filter((section) => section.key !== 'financial_audit').flatMap(sectionIds);

    return [...new Set([
        ...selectedIds.filter((id) => !workIds.includes(id)),
        ...sourceIds.filter((id) => workIds.includes(id)),
    ])];
}


/** Each actual grant added or removed; a swap always produces both changes. */
export function individualChanges(sections, saved, current) {
    return permissionEntries(sections)
        .filter((item) => saved.includes(item.id) !== current.includes(item.id))
        .map((item) => ({ ...item, added: current.includes(item.id), text: `${item.section}: ${item.label}` }));
}

/** Group shortcuts operate on visible sections and leave all other grants alone. */
export function withGroupAccess(sections, selected, viewOnly) {
    const governed = sections.flatMap(sectionIds);
    const next = selected.filter((id) => !governed.includes(id));

    if (viewOnly) {
        for (const section of sections) {
            if (section.ladder?.actions[0] === 'view') {
                next.push(section.ladder.ids[0]);
            }
            for (const item of section.switches) {
                if (item.action === 'view' || item.action === 'browse_weeks' || (section.key === 'reports' && ['branch_performance', 'debt_aging', 'transaction_audit'].includes(item.action))) {
                    next.push(item.id);
                } else if (item.action === 'view_all' && selected.includes(item.id)) {
                    next.push(item.id);
                }
            }
        }
    }

    return next;
}

/**
 * Text for matching Arabic the way people type it: hamza forms of alef,
 * alef maqsura and taa marbuta fold together, and diacritics and tatweel
 * are dropped, so «اعتماد» finds «إعتماد» and «رد» finds «ردّ».
 */
export function normalizeArabic(text) {
    return String(text ?? '')
        .toLocaleLowerCase()
        .replace(/[\u064B-\u0652\u0670\u0640]/g, '')
        .replace(/[أإآٱ]/g, 'ا')
        .replace(/ى/g, 'ي')
        .replace(/ة/g, 'ه');
}

/** Whether `text` contains `query` once both are normalized (an empty query matches everything). */
export function matchesQuery(text, query) {
    const needle = normalizeArabic(query.trim());

    return !needle || normalizeArabic(text).includes(needle);
}

export function filterSections(sections, selected, saved, query, mode = 'all') {
    const needle = normalizeArabic(query.trim());

    return sections.filter((section) => {
        const entries = permissionEntries([section]);
        const matchesQuery = !needle || [section.label, ...entries.flatMap((item) => [item.label, item.hint])]
            .filter(Boolean).some((text) => normalizeArabic(text).includes(needle));
        const matchesMode = mode === 'all'
            || (mode === 'enabled' && sectionIds(section).some((id) => selected.includes(id)))
            || (mode === 'sensitive' && section.sensitive.length > 0)
            || (mode === 'changed' && sectionIds(section).some((id) => saved.includes(id) !== selected.includes(id)));

        return matchesQuery && matchesMode;
    });
}

/**
 * The level the selected permissions reach on a ladder: n when exactly its
 * first n permissions are held, or null when they don't sit on it (custom).
 */
export function levelOf(ladder, selectedIds) {
    const held = ladder.ids.map((id) => selectedIds.includes(id));
    const level = held.indexOf(false) === -1 ? held.length : held.indexOf(false);

    return held.slice(level).some(Boolean) ? null : level;
}

/** The selected permissions with a ladder set to `level`: its first `level` permissions held, the rest not. */
export function withLevel(ladder, selectedIds, level) {
    const others = selectedIds.filter((id) => !ladder.ids.includes(id));

    return [...others, ...ladder.ids.slice(0, level)];
}

/**
 * The selected permissions with a section's ladder set to `level`. An
 * option that needs a higher level goes; the ways to be paid go with
 * «إضافة دفعة»: reaching it turns them all on when none is, and dropping
 * below it turns them off.
 */
export function withSectionLevel(section, selectedIds, level) {
    // Options that need a level above the new one go with it.
    const tooHigh = sectionExtras(section).flatMap((group) => group.items).filter((item) => item.needs > level).map((item) => item.id);
    const next = withLevel(section.ladder, selectedIds, level).filter((id) => !tooHigh.includes(id));
    const recordIndex = section.ladder.actions.indexOf('record');
    const methods = section.switches.filter((item) => PAYMENT_METHOD_ACTIONS.includes(item.action)).map((item) => item.id);

    if (section.key !== 'collections' || recordIndex === -1 || methods.length === 0) {
        return next;
    }

    if (level <= recordIndex) {
        return next.filter((id) => !methods.includes(id));
    }

    return methods.some((id) => next.includes(id)) ? next : [...next, ...methods];
}

/** The selected permissions with one turned on or off. */
export function withToggled(selectedIds, id, on) {
    const others = selectedIds.filter((selected) => selected !== id);

    return on ? [...others, id] : others;
}

/** Whether two sets of permission ids hold the same permissions. */
export function samePermissions(first, second) {
    return first.length === second.length && first.every((id) => second.includes(id));
}

/**
 * What changed from `saved` to `current`, section by section, as short
 * lines: `{ text, added }` — a ladder's new level, or a permission turned on
 * or off.
 */
export function changesBetween(sections, saved, current) {
    const changes = [];

    for (const section of sections) {
        if (section.ladder) {
            const before = levelOf(section.ladder, saved);
            const after = levelOf(section.ladder, current);

            if (!samePermissions(section.ladder.ids.filter((id) => saved.includes(id)), section.ladder.ids.filter((id) => current.includes(id)))) {
                changes.push({
                    text: `${section.label}: ${after === null ? 'مخصّص' : section.ladder.levels[after]}`,
                    added: before === null || after === null ? current.length >= saved.length : after > before,
                });
            }
        }

        for (const item of [...section.switches, ...section.sensitive]) {
            const was = saved.includes(item.id);
            const is = current.includes(item.id);

            if (was !== is) {
                changes.push({ text: `${section.label}: ${item.label}`, added: is });
            }
        }
    }

    return changes;
}

/** The matrix's everyday columns, the same as the site's independent grants. */
export const MATRIX_COLUMNS = PERMISSION_COLUMNS;

/**
 * A section's matrix cells, one per column: the permission behind it
 * (`kind: 'permission'`, with its risk) or
 * `null` when the section has no such action. Each cell is its own grant,
 * so an employee may edit without adding.
 */
export function matrixCells(section) {
    const extras = new Map(sectionExtras(section).flatMap((group) => group.items).map((item) => [item.id, item]));

    return permissionCells(section).map(({ permission, ...column }) => (permission
        ? { column, kind: 'permission', ...permission, ...extras.get(permission.id), needs: 0 }
        : { column, kind: null }));
}

/** The section's options that have no column of their own, in their groups (shown when the row is opened). */
export function matrixExtras(section) {
    const inCells = new Set(matrixCells(section).filter((cell) => cell.kind).map((cell) => cell.id));

    return sectionExtras(section)
        .map((group) => ({ ...group, items: group.items.filter((item) => !inCells.has(item.id)) }))
        .filter((group) => group.items.length > 0);
}

/** Every permission of the given sections granted (`on`) or taken away, leaving other sections alone. */
export function withSectionsAll(sections, selectedIds, on) {
    const ids = sections.flatMap(sectionIds);

    return on ? [...new Set([...selectedIds, ...ids])] : selectedIds.filter((id) => !ids.includes(id));
}

/** How many of the given permissions are held, and how many of those are sensitive or dangerous. */
export function riskCounts(sections, selectedIds) {
    const held = permissionEntries(sections).filter((item) => selectedIds.includes(item.id));

    return {
        total: held.length,
        sensitive: held.filter((item) => item.risk === 'sensitive').length,
        danger: held.filter((item) => item.risk === 'danger').length,
    };
}

/** The job-title colours, in the order the titles are listed; an unset title is grey. */
export const ROLE_COLORS = ['#2f66d6', '#1f8750', '#c27803', '#7c3aed', '#0e8a8a', '#c2410c', '#be185d', '#4f46e5'];
export const UNSET_ROLE_COLOR = '#6b7480';

/**
 * Each job title's colour: the titles as listed get the palette in turn, so
 * the same title has the same colour across the page, and a title not
 * listed falls back to one picked from its name.
 */
export function roleColors(labels) {
    const colors = new Map();

    labels.filter((label) => label && label !== 'غير محدد').forEach((label) => {
        if (!colors.has(label)) {
            colors.set(label, ROLE_COLORS[colors.size % ROLE_COLORS.length]);
        }
    });

    return (label) => {
        if (!label || label === 'غير محدد') {
            return UNSET_ROLE_COLOR;
        }

        if (colors.has(label)) {
            return colors.get(label);
        }

        let hash = 0;
        for (const char of String(label)) {
            hash = (hash * 31 + char.codePointAt(0)) >>> 0;
        }

        return ROLE_COLORS[hash % ROLE_COLORS.length];
    };
}

const RISK_WEIGHTS = { sensitive: 3, danger: 6 };

/**
 * How much the held permissions can move money or the service, from 0 to
 * 100: each permission weighs by its risk, against all of them. Any
 * dangerous permission makes it at least «متوسطة», three or more «مرتفعة».
 */
export function riskLevel(sections, selectedIds) {
    const entries = permissionEntries(sections);
    const weight = (item) => RISK_WEIGHTS[item.risk] ?? 1;
    const max = entries.reduce((sum, item) => sum + weight(item), 0);
    const held = entries.filter((item) => selectedIds.includes(item.id));
    const score = max ? Math.round((held.reduce((sum, item) => sum + weight(item), 0) / max) * 100) : 0;
    const danger = held.filter((item) => item.risk === 'danger').length;
    const level = danger >= 3 || score >= 45 ? 'high' : danger > 0 || score >= 15 ? 'mid' : 'low';

    return { score, level, label: { low: 'منخفضة', mid: 'متوسطة', high: 'مرتفعة' }[level] };
}

/** Two employees' permissions side by side: what only the first holds, what only the second holds, and what both hold. */
export function comparePermissions(sections, mine, theirs) {
    const entries = permissionEntries(sections);

    return {
        onlyMine: entries.filter((item) => mine.includes(item.id) && !theirs.includes(item.id)),
        onlyTheirs: entries.filter((item) => theirs.includes(item.id) && !mine.includes(item.id)),
        shared: entries.filter((item) => mine.includes(item.id) && theirs.includes(item.id)),
    };
}

/** «صلاحية» or «صلاحيات», as Arabic counts them: 3 to 10 take the plural. */
export function permissionsWord(count) {
    const rest = Math.abs(count) % 100;

    return rest >= 3 && rest <= 10 ? 'صلاحيات' : 'صلاحية';
}
