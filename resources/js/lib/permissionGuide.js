import { sectionExtras } from './permissions.js';
export const CATEGORIES = [
    { key:'all', label:'كل الأقسام', icon:'grid', sections:[] },
    { key:'basic', label:'البيانات الأساسية', icon:'users', sections:['subscriptions','meter_boxes','circuit_breakers','tariffs'] },
    { key:'readings', label:'القراءات والتوزيع', icon:'gauge', sections:['meter_readings','reading_assignments'] },
    { key:'finance', label:'المال والتقارير', icon:'wallet', sections:['collections','reports','disconnections'] },
    { key:'audit', label:'التدقيق المالي', icon:'shield', sections:['financial_audit'] },
    { key:'communication', label:'التواصل والطباعة', icon:'messages', sections:['messages','print_templates'] },
    { key:'administration', label:'الإدارة والمواقع', icon:'shield', sections:['users','user_types','branches','governorates','areas','sub_areas'] },
];
export const ICONS = { financial_audit:'shield', subscriptions:'users',meter_boxes:'table',circuit_breakers:'bolt',tariffs:'dollar',meter_readings:'gauge',reading_assignments:'layers',collections:'wallet',reports:'trend',disconnections:'power',messages:'messages',print_templates:'printer',users:'user',user_types:'badge',branches:'building',governorates:'map',areas:'map',sub_areas:'pin' };
const NAMES = { 'collections.view':'عرض السجل المالي', 'collections.record_cash':'تسجيل دفعات نقدية','collections.record_transfer':'تسجيل حوالات ودفعات مقسّمة','collections.charge':'إضافة تحميل','collections.discount':'إضافة خصم','collections.clearing':'إضافة مقاصة','reports.view':'عرض التقارير المالية','reports.view_all':'عرض تقارير جميع الفروع','reports.export':'تنزيل التقارير المالية' };
const EXPLANATIONS = {
    'subscriptions.update_minimum_charge':['يعدّل الحد الأدنى للمشترك، والحد الأدنى للقراءة الأخيرة القابلة للتعديل.','تعديل قراءة سابقة أخرى غير متاح؛ يبقى تعديل سعر الكيلو بصلاحية مستقلة.'],
    'subscriptions.update_kilowatt_price':['يعدّل سعر الكيلو الخاص بالمشترك، وسعر القراءة الأخيرة القابلة للتعديل.','يؤثر في احتساب الفاتورة؛ لا يمنح تغيير القراءات أو اعتمادها تلقائيًا.'],
    'subscriptions.bulk_update':['ينفّذ إجراءً جماعيًا على المشتركين المحددين، مثل تغيير الحد الأدنى أو الحالة أو نقل الطبلون.','يحتاج أيضًا صلاحية الإجراء أو الحقل نفسه، ويظل مقيدًا بنطاق فرع العمل.'],
    'subscriptions.archive':['يؤرشف الاشتراك المفصول بعد إنهاء الخدمة واستكمال القراءات، مع تسجيل السبب والتاريخ والموظف.','لا يحذف بيانات المشترك ولا يغيّر الأرصدة أو الحركات، ولا يؤرشف اشتراكاته الأخرى.'],
    'subscriptions.view_archived':['يعرض الاشتراكات المؤرشفة وملفاتها وسجلها المالي ضمن نطاق الفرع المسموح.','لا يمنح الأرشفة أو الاستعادة أو تعديل البيانات تلقائيًا.'],
    'subscriptions.restore_archived':['يعيد الاشتراك المؤرشف إلى قائمة المشتركين مع تسجيل سبب الاستعادة.','يتطلب عرض الأرشيف؛ يبقى الاشتراك مفصولًا حتى إعادة تفعيل الخدمة بإجراء مستقل.'],
    'subscriptions.delete':['يحذف فقط الاشتراك المسجل بالخطأ إذا لم تمنع قراءاته أو حركاته وارتباطاته الحذف.','لا يتيح حذف الاشتراكات المؤرشفة أو إزالة السجل المالي.'],
    'meter_readings.view':['يعرض صفحة القراءات الأسبوعية وبيانات القراءة للمشتركين ضمن النطاق المسموح.','العرض لا يمنح الإدخال أو التصحيح أو الاعتماد؛ الأسابيع الأقدم تحتاج صلاحية تصفحها.'],
    'meter_readings.record':['يدخل القراءة الحالية في الأسبوع ومواعيد الإدخال المتاحة له.','تسجيل القراءة لا يعني اعتمادها؛ تظهر بانتظار الاعتماد وفق إعدادات العمل.'],
    'meter_readings.browse_weeks':['يتنقل إلى أسابيع القراءات السابقة لمراجعتها.','التصفح لا يفتح تعديل الأسابيع القديمة؛ شروط التصحيح والاعتماد تبقى سارية.'],
    'meter_readings.correct':['يصحّح قراءة أسبوعية قابلة للتعديل ضمن فرعه.','تصحيح القراءة المعتمدة يعيدها للاعتماد؛ تتحدد الأسابيع المتاحة بحسب قواعد النظام.'],
    'meter_readings.correct_last':['يصحّح «القراءة السابقة» المتاحة مع تحديد سبب التغيير.','تصحيح نقطة البداية لا يغيّر الفواتير السابقة المحفوظة؛ قد يتطلب إعادة إدخال القراءة التالية. الاعتماد العام بصلاحية مستقلة.'],
    'meter_readings.approve':['يعتمد القراءات المعلقة ويثبت أثرها المالي على حساب المشترك.','لا يعتمد قراءة تتطلب إعادة إدخال، ولا يمنح تعديل القراءة أو سعرها من تلقاء نفسه.'],
    'reading_assignments.view':['يرى توزيع الطبلونات ومهام إدخال القراءات.','إدارة التوزيع واعتماده ومراجعة الإرسالات صلاحيات مستقلة.'],
    'reading_assignments.manage':['يخصص الطبلونات للقراء أو للكشوف الورقية ويعدّل توزيع العمل.','لا يمنح اعتماد التوزيع أو اعتماد الفواتير تلقائيًا.'],
    'reading_assignments.print_sheets':['يطبع كشوف القراءة الورقية للطبلونات المخصصة للورق.','متاح ضمن التوزيع والفروع التي يسمح له بالوصول إليها.'],
    'reading_assignments.approve':['يعتمد توزيع القراءات لتصبح المهام متاحة للقراء.','يختلف عن اعتماد قراءة المشترك وتحميل فاتورتها.'],
    'reading_assignments.review':['يقبل أو يرفض قراءات ميدانية تحتاج مراجعة، مثل التعارض أو الإرسال خارج الموعد.','مراجعة الإرسال لا تمنح صلاحيات التدقيق المالي.'],
    'collections.view':['يعرض السجل المالي والدفعات والحركات المسموح له برؤيتها.','عرض الحركة لا يمنح تعديلها أو إلغاءها أو ردها.'],
    'collections.record':['يتيح إنشاء دفعة للمشترك.','يجب تحديد طريقة دفع واحدة على الأقل: نقدًا أو تحويل بنكي.'],
    'collections.record_cash':['يسجّل دفعة نقدية تدخل في الصندوق المالي المرتبط بها.','يتطلب «إضافة دفعة»؛ لا يفتح الحوالات البنكية أو الدفعات المقسّمة وحده.'],
    'collections.record_transfer':['يسجّل حوالة بنكية، بما فيها دفعة مقسّمة على عدة اشتراكات.','يتطلب «إضافة دفعة»؛ لا يمنح تعديل الحوالة أو ردّها لاحقًا تلقائيًا.'],
    'collections.close_boxes':['يراجع ويتسلم ويؤكد إغلاق صناديق محصّلي فرعه وفق إعدادات التسليم.','توجد قواعد للصندوق ومالكه والموظفين المعيّنين للتسليم؛ لا تعني تدقيقًا ماليًا عبر الفروع.'],
    'collections.manage_box_periods':['يعدّل يوم بداية فترات الصناديق في فرعه.','السوبر أدمن هو من يفوّضها؛ لا تعطي إدارة روابط الفروع أو اعتماد التدقيق.'],
    'collections.manage_branch_closings':['يدير تسليم واعتماد صناديق الفرع وإعدادات الموظفين المسؤولين عنها.','يمنحها السوبر أدمن، ويظل نطاقها فرع العمل.'],
    'closings.view_all':['يعرض الصناديق المالية المغلقة عبر الفروع المتاحة.','صلاحية عرض؛ لا تمنح تعديل مبالغ البنك أو اعتماد التدقيق أو عرض كل التقارير المالية.'],
    'closings.audit':['يتيح عمل التدقيق المالي حسب فروع التدقيق والإجراءات التي يحددها السوبر أدمن.','يجب تحديد نطاق التدقيق وصلاحياته التفصيلية السبع؛ لا تعني هذه الصلاحية إتاحة كل إجراءات التدقيق تلقائيًا.'],
    'collections.charge':['يضيف تحميلًا مثل رسوم أو غرامة على حساب المشترك.','يزيد المبلغ المستحق على المشترك؛ يختلف عن الدفعة والخصم.'],
    'collections.discount':['يضيف خصمًا لمرة واحدة أو خصمًا دائمًا على القراءات ويوقف الخصم الدائم.','يؤثر في الرصيد أو الفواتير التالية؛ لا يمنح تعديل مبلغ أي حركة أخرى.'],
    'collections.clearing':['يسجل مقاصة لتسوية حساب المشترك.','إجراء مالي مستقل عن تسجيل الدفعة أو إضافة الخصم.'],
    'collections.correct':['يصحح مبلغ حركة مالية قابلة للتعديل مع حفظ سبب التغيير وأثره.','الحركات المرتبطة بإغلاق يمنع التعديل تبقى مقفلة؛ لا يمنح تعديل بيانات الحوالة تلقائيًا.'],
    'collections.amend':['يعدل بيانات الدفعة: اسم المرسل والبنك المرسل والبنك المستلم والرقم المرجعي والملاحظات.','لا يغيّر مبلغ الدفعة. تعديل بيانات الحوالة المقسّمة يحدّث أجزاءها المرتبطة؛ تمنع حالة الإغلاق التعديل.'],
    'collections.refund':['يرد الدفعة بقيد مرتبط يحفظ أثر الرد في كشف الحساب.','رد الدفعة المقسّمة يشمل الأجزاء المرتبطة؛ الدفعة المقفلة لا تُرد بهذه العملية.'],
    'collections.delete':['يلغي الحركة المالية القابلة للإلغاء مع الاحتفاظ بأثر الإلغاء.','رد الدفعات يحتاج صلاحية الردّ؛ الحذف النهائي يحتاج صلاحيته المستقلة.'],
    'collections.force_delete':['يحذف الحركة المسموح بمحوها من كشف الحساب مع بقاء سجل تدقيق.','تخضع لشروط آخر حركة وعدم احتسابها في إغلاق؛ لا تمنح رد الدفعة أو الإلغاء تلقائيًا.'],
    'reports.view':['يعرض التقارير المالية لفرع العمل المتاح له.','عرض التقارير لا يمنح تنزيلها؛ التقارير المتخصصة وإجمالي جميع الفروع لها خيارات مستقلة.'],
    'reports.view_all':['يعرض التقارير المالية لجميع الفروع من النطاق الذي يسمح به الموقع.','يمنحها السوبر أدمن؛ يبقى دومين الفرع محددًا للبيانات التي تظهر داخله.'],
    'reports.branch_performance':['يفتح تقارير أداء الفروع ومؤشرات التحصيل ضمن النطاق المسموح.','هذه صلاحية تقرير محدد، ولا تمنح كل التقارير أو تعديل الحركات.'],
    'reports.debt_aging':['يعرض المشتركين المدينين وتصنيف المبالغ بحسب عمر الدين.','لا يغيّر الرصيد أو ينفذ إجراء قطع الخدمة تلقائيًا.'],
    'reports.transaction_audit':['يعرض من عدّل أو ألغى أو حذف حركة، ومتى وما السبب المسجل.','قراءة سجل التدقيق لا تمنح تنفيذ التعديلات المسجلة فيه.'],
    'reports.export':['ينزل التقارير المالية المسموح له بعرضها بصيغة التصدير المتاحة.','يحتاج حق عرض التقارير؛ لا يمنح الوصول إلى فروع أو بيانات إضافية.'],
    'disconnections.view':['يعرض قوائم القطع والمدرجين فيها وحالاتهم.','لا يمنح التنفيذ أو إرسال الإنذارات أو تغيير الرسوم.'],
    'disconnections.manage':['يدير قوائم القطع ويضيف المشتركين إليها أو يزيلهم ويضبط المدد.','تنفيذ القطع وإرسال الإنذار وتغيير الرسم تحتاج خياراتها الخاصة.'],
    'disconnections.warn':['يرسل إنذارات القطع للمشتركين في القوائم المسموحة.','إرسال فعلي للرسائل ويُظهر بيانات الاتصال اللازمة؛ لا يؤكد تنفيذ القطع.'],
    'disconnections.execute':['يؤكد تنفيذ قطع الخدمة ويحمّل رسوم القطع وفق الإجراء.','يتطلب صلاحيات القائمة اللازمة؛ تعديل قيمة الرسم أو الإعفاء يحتاج الخيار الخاص به.'],
    'disconnections.change_fee':['يغيّر رسوم القطع عند التأكيد أو يعفي المشترك منها.','مرتبط بإجراء تأكيد القطع؛ لا يغيّر الرسوم العامة للشركة.'],
    'messages.view':['يعرض سجل الرسائل وحالات الإرسال وأنواعها ضمن نطاقه.','العرض لا يمنح إرسال رسالة جديدة.'],
    'messages.send':['يرسل رسالة للمشتركين المسموح له بالتواصل معهم.','تُطبّق إعدادات الرسائل والقوالب المتاحة، وقد يترتب على الإرسال استهلاك رصيد المزود.'],
    'print_templates.manage':['يخصص قوالب الطباعة المشتركة ونماذج الشركة ويستورد ملفاتها.','السوبر أدمن يفوّضها لأنها تغيّر قوالب مشتركة؛ إصدار وأرشفة نماذج الشركة له خيار مستقل.'],
    'company_forms.print':['يصدر نموذج شركة فارغًا أو معبأً، ويطبع النسخ ويحفظ الطلبات الورقية في الأرشيف.','الأرشيف مقيد بالفرع. لا يمنح تخصيص القوالب أو تعديل حساب المشترك.'],
    'users.create':['ينشئ حساب موظف جديد في فرع العمل.','الحساب يبدأ بلا صلاحيات. توزيع صلاحياته يحتاج «إدارة صلاحيات موظفي الفرع».'],
    'users.view':['يعرض حسابات الموظفين وبيانات عملهم في النطاق المسموح.','لا يمنح إنشاء حساب أو تعديل كلمة مرور أو منح صلاحيات.'],
    'users.update':['يعدل بيانات حساب الموظف وحالته في فرعه ضمن الحسابات المسموح بإدارتها.','الصلاحيات وكلمات المرور لهما خيارات مستقلة؛ الحسابات الإدارية المحمية يديرها السوبر أدمن.'],
    'users.delete':['يحذف حسابًا مسموحًا بإدارته إذا لم تمنع ارتباطاته الحذف.','لا يحذف حسابه أو السوبر أدمن، ولا حسابات الإدارة المحمية في الفرع.'],
    'users.manage_permissions':['يمنح ويسحب صلاحيات موظفي فرعه ضمن الصلاحيات التي يملكها والمسموح بتفويضها.','السوبر أدمن فقط يفوّض هذه المسؤولية. لا يعدل صلاحيات نفسه أو مدير محمي أو موظف فرع آخر.'],
    'users.manage_passwords':['يغيّر كلمات مرور موظفي فرعه المسموح بإدارتهم.','يحتاج أيضًا تعديل المستخدمين؛ لا يغيّر كلمة مرور نفسه بهذه الإدارة أو مدير صلاحيات آخر.'],
    'users.manage_branch_schedules':['يضبط مواعيد فتح وإغلاق إدخال القراءات لفرعه.','السوبر أدمن يفوّضها؛ لا تمنح اعتماد القراءات أو إدارة المواعيد لفروع أخرى.'],
    'branches.manage_domains':['يربط رابطًا أو دومينًا بفرع، ويُفعّل الرابط أو يوقفه من اللوحة المركزية.','يحتاج إعداد DNS وHTTPS في الاستضافة؛ لا تمنح إدارة صلاحيات الموظفين.'],
};
const ENTITIES = {subscriptions:'بيانات الاشتراكات',meter_boxes:'الطبلونات ومواقعها',circuit_breakers:'القواطع وأمبيراتها',tariffs:'التعرفات وأسعارها',user_types:'المسميات الوظيفية',branches:'فروع الشركة',governorates:'المحافظات',areas:'المناطق',sub_areas:'المناطق الفرعية'};


export function permissionGuideEntries(sections) {
    return sections.flatMap(section => (section.permissions ?? []).map(permission => {
        const key = permission.key ?? section.key + '.' + permission.action;
        const extra = sectionExtras(section).flatMap(group => group.items).find(item => item.id === permission.id);
        const entity = ENTITIES[section.key] ?? section.label;
        const generic = { view: ['يعرض ' + entity + ' ضمن النطاق المسموح.', 'العرض لا يمنح الإضافة أو التعديل أو الحذف.'], create: ['يضيف سجلات جديدة في ' + entity + '.', 'لا يمنح التعديل أو الحذف تلقائيًا.'], update: ['يعدل السجلات المتاحة في ' + entity + '.', 'تبقى قيود الارتباطات ونطاق الفرع مطبقة.'], delete: ['يحذف السجلات المسموح بحذفها في ' + entity + '.', 'قد تمنع ارتباطات السجل حذفه.'] };
        const explanation = EXPLANATIONS[key] ?? generic[permission.action] ?? [extra?.hint || permission.label, 'تطبق قواعد الإجراء ونطاق فرع العمل.'];
        return { ...permission, key, section: section.key, sectionLabel: section.label, label: NAMES[key] ?? permission.label, description: explanation[0], limit: explanation[1] + (section.key === 'user_types' ? ' المسمى الوظيفي لا يمنح صلاحيات تلقائيًا.' : ''), risk: extra?.risk ?? null, delegation: permission.centralOnly ? 'يفوضها السوبر أدمن فقط.' : 'يمكن لمدير الفرع تفويضها إذا كان يملكها ومفوّضًا بإدارة الصلاحيات.' };
    }));
}

const escapeHtml = value => String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;');

export function permissionGuideHtml({ title, entries, auditActions = [], scopeNote = '', lockedPermissions = [] }) {
    const groups = [...new Set(entries.map(entry => entry.section))];
    const items = groups.map(key => { const group = entries.filter(entry => entry.section === key); return '<section><h2>' + escapeHtml(group[0].sectionLabel) + '</h2>' + group.map(entry => '<article><h3>' + escapeHtml(entry.label) + '</h3><p>' + escapeHtml(entry.description) + '</p><p class="muted"><b>حدودها:</b> ' + escapeHtml(entry.limit) + '</p><p class="delegation">' + escapeHtml(entry.delegation) + '</p></article>').join('') + '</section>'; }).join('');
    const audit = auditActions.length ? '<section><h2>إجراءات التدقيق المالي</h2><p>يحدد السوبر أدمن فروع التدقيق والإجراءات المسموحة. اعتماد الفترة يتطلب اعتماد الصناديق.</p>' + auditActions.map(action => '<article><h3>' + escapeHtml(action.label) + '</h3><p>' + escapeHtml(action.hint) + '</p></article>').join('') + '</section>' : '';
    const locked = lockedPermissions.length ? '<section><h2>صلاحيات محفوظة خارج نطاق إدارة محرّر هذا الدليل</h2><p>' + lockedPermissions.map(escapeHtml).join('، ') + '</p></section>' : '';
    return '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' + escapeHtml(title) + '</title><style>body{max-width:960px;margin:24px auto;padding:24px;font-family:Tahoma,sans-serif;color:#17202a;background:white;line-height:1.9}header{border-bottom:2px solid #a51d26;padding-bottom:18px}h1{font-size:25px}h2{font-size:20px;padding:10px 14px;background:#eff1f4;break-after:avoid;margin-top:28px}h3{font-size:16px;margin:0}article{padding:15px 0;border-bottom:1px solid #dfe2e6;break-inside:avoid}p{margin:7px 0;font-size:15px}.muted,.delegation{color:#596373;font-size:13px}.note{background:#f6f7f9;padding:12px 15px}.no-print{font-size:13px;color:#626c7b}@page{size:A4;margin:16mm 14mm}@media print{body{margin:0;padding:0;max-width:none}.no-print{display:none}h1{font-size:20pt}h2{font-size:14pt}h3{font-size:12pt}p{font-size:10.5pt}.muted,.delegation{font-size:10pt}*{-webkit-print-color-adjust:exact;print-color-adjust:exact}}</style></head><body><p class="no-print">استخدم أمر الطباعة في المتصفح للطباعة أو حفظ PDF. هذا الدليل لا يغيّر صلاحيات أي حساب.</p><header><h1>' + escapeHtml(title) + '</h1><p>' + escapeHtml(scopeNote) + '</p></header><p class="note">منح صلاحية لا يلغي شروط الفرع أو حالة الحركة أو موعد العمل. المسمى الوظيفي لا يمنح صلاحيات. مدير الفرع يفوض موظفي فرعه ضمن الصلاحيات التي يملكها والمسموح بتفويضها.</p>' + items + audit + locked + '</body></html>';
}
