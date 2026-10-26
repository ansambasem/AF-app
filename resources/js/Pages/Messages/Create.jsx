import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Icon from '@/Components/Icon';
import ChoiceChips from '@/Components/ChoiceChips';
import Switch from '@/Components/Switch';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import ConfirmDialog from '@/Components/ConfirmDialog';
import InputError from '@/Components/InputError';
import { CHANNEL_ICONS, KIND_HINTS, KIND_ICONS, MessageText, renderMessage, withIcons } from './MessageParts';
import { scopedOptions, withStaleCleared } from '@/lib/filterGroups';
import PageHeader, { BackLink } from '@/Components/PageHeader';

/** Arabic text goes out as Unicode SMS: 70 letters in one message, 67 in each part of a longer one. */
function smsParts(length) {
    if (length === 0) {
        return 0;
    }

    return length <= 70 ? 1 : Math.ceil(length / 67);
}

function Field({ label, htmlFor, children, className = '' }) {
    return (
        <div className={className}>
            <label htmlFor={htmlFor} className="mb-1.5 block text-sm font-semibold text-gray-700">
                {label}
            </label>
            {children}
        </div>
    );
}

/** The keyboard focus ring every custom button here uses, like the app's own buttons. */
const FOCUS_RING = 'focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-900';

/** One step of the page as a card, named by its title for screen readers. */
function Card({ icon, title, description, children, actions = null }) {
    const titleId = useId();

    return (
        <section aria-labelledby={titleId} className="rounded-card border border-gray-100 bg-surface p-5 shadow-card sm:p-6">
            <div className="mb-5 flex items-center gap-3">
                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-graphite-gradient text-white dark:ring-1 dark:ring-white/10">
                    <Icon name={icon} className="h-5 w-5" />
                </span>
                <div className="min-w-0 flex-1">
                    <h3 id={titleId} className="font-bold text-gray-900">
                        {title}
                    </h3>
                    {description && <p className="text-xs text-gray-500">{description}</p>}
                </div>
                {actions}
            </div>
            {children}
        </section>
    );
}

/**
 * Write a message to subscriptions: pick what it's about, who gets it, the
 * wording (from a saved template or typed, with placeholders for each
 * subscription's own details) and how it goes out, check each recipient's
 * own text, untick anyone it shouldn't reach, then send.
 */
export default function Create({
    kinds,
    channels,
    smsDeliversMessages,
    templates,
    placeholders,
    weekOptions,
    weekBranchId,
    statusOptions,
    branchOptions,
    meterBoxGroups,
    circuitBreakerOptions,
    maxRecipients,
    criteria: initialCriteria,
    recipients: loadedRecipients,
}) {
    const { errors } = usePage().props;
    const bodyRef = useRef(null);
    const [kind, setKind] = useState(initialCriteria.kind);
    const [channel, setChannel] = useState(smsDeliversMessages ? 'sms' : 'whatsapp');
    const [criteria, setCriteria] = useState({
        week_start: initialCriteria.week_start,
        approved_only: initialCriteria.approved_only,
        min_balance: initialCriteria.min_balance > 0 ? String(initialCriteria.min_balance) : '',
        status: initialCriteria.status ?? '',
        branch_id: initialCriteria.branch_id ?? '',
        meter_box_name: initialCriteria.meter_box_name ?? '',
        meter_box_id: initialCriteria.meter_box_id ?? '',
        circuit_breaker_id: initialCriteria.circuit_breaker_id ?? '',
        search: initialCriteria.search ?? '',
        subscription_ids: initialCriteria.subscription_ids ?? [],
    });
    const kindTemplates = templates.filter((template) => template.kind === kind);
    const [templateId, setTemplateId] = useState(() => kindTemplates[0]?.id ?? '');
    const [body, setBody] = useState(() => kindTemplates[0]?.body ?? '');
    const [recipients, setRecipients] = useState(null);
    const [loadedKey, setLoadedKey] = useState(null);
    const [loading, setLoading] = useState(false);
    const [selected, setSelected] = useState(new Set());
    const [previewId, setPreviewId] = useState(null);
    const [confirming, setConfirming] = useState(false);
    const [sending, setSending] = useState(false);
    const [templateName, setTemplateName] = useState(null);
    const [confirmingTemplateDelete, setConfirmingTemplateDelete] = useState(false);

    // What the recipient list is asked for with; the list is out of date once this changes.
    const requestData = useMemo(() => {
        const data = { kind, status: criteria.status, search: criteria.search };

        for (const key of ['branch_id', 'meter_box_name', 'meter_box_id', 'circuit_breaker_id']) {
            if (criteria[key] !== '') {
                data[key] = criteria[key];
            }
        }

        if (criteria.subscription_ids.length > 0) {
            data.subscription_ids = criteria.subscription_ids;
        }

        if (kind === 'weekly_reading') {
            data.week_start = criteria.week_start;
            data.approved_only = criteria.approved_only ? 1 : 0;
        }

        if (kind === 'balance_reminder') {
            data.min_balance = criteria.min_balance === '' ? 0 : criteria.min_balance;
        }

        return data;
    }, [kind, criteria]);
    const requestKey = JSON.stringify(requestData);
    // Each branch reads on its own weeks: those of the branch messaged, or else of the branch the page starts on.
    const weekBranchKey = String(criteria.branch_id !== '' ? criteria.branch_id : weekBranchId);
    const branchWeekOptions = weekOptions[weekBranchKey] ?? [];
    const previousWeekBranchKey = useRef(weekBranchKey);
    const isStale = recipients !== null && loadedKey !== requestKey;

    // Keep the list loaded here: a template save reloads the page without it.
    useEffect(() => {
        if (loadedRecipients === undefined) {
            return;
        }

        setRecipients(loadedRecipients);
        setSelected(new Set(loadedRecipients.filter((recipient) => recipient.phone).map((recipient) => recipient.id)));
        setPreviewId(loadedRecipients.find((recipient) => recipient.phone)?.id ?? null);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [loadedRecipients]);

    // A subscription or weekly-reading shortcut opens its recipients straight away.
    useEffect(() => {
        if (criteria.subscription_ids.length > 0 || initialCriteria.kind === 'weekly_reading') {
            loadRecipients();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Another branch has other weeks: move to its latest one when the chosen week isn't one of them.
    useEffect(() => {
        if (previousWeekBranchKey.current === weekBranchKey) {
            return;
        }
        previousWeekBranchKey.current = weekBranchKey;
        if (branchWeekOptions.length > 0 && !branchWeekOptions.some((option) => option.value === criteria.week_start)) {
            setCriterion('week_start', branchWeekOptions[0].value);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [weekBranchKey]);

    function loadRecipients() {
        setLoading(true);
        router.reload({
            only: ['recipients'],
            data: requestData,
            preserveScroll: true,
            onSuccess: () => setLoadedKey(requestKey),
            onFinish: () => setLoading(false),
        });
    }

    function setCriterion(key, value) {
        setCriteria((current) => ({ ...current, [key]: value }));
    }

    function changeKind(nextKind) {
        setKind(nextKind);
        const first = templates.find((template) => template.kind === nextKind);
        setTemplateId(first?.id ?? '');
        setBody(first?.body ?? '');
    }

    function pickTemplate(id) {
        setTemplateId(id);
        const template = templates.find((item) => String(item.id) === String(id));

        if (template) {
            setBody(template.body);
        }
    }

    function insertPlaceholder(name) {
        const token = `{${name}}`;
        const textarea = bodyRef.current;
        const start = textarea?.selectionStart ?? body.length;
        const end = textarea?.selectionEnd ?? body.length;
        setBody(body.slice(0, start) + token + body.slice(end));

        requestAnimationFrame(() => {
            textarea?.focus();
            textarea?.setSelectionRange(start + token.length, start + token.length);
        });
    }

    function saveTemplate() {
        router.post(
            '/message-templates',
            { name: templateName, kind, body },
            { preserveScroll: true, preserveState: true, onSuccess: () => setTemplateName(null) },
        );
    }

    function updateTemplate() {
        const template = templates.find((item) => String(item.id) === String(templateId));
        router.put(`/message-templates/${templateId}`, { name: template.name, kind, body }, { preserveScroll: true, preserveState: true });
    }

    function deleteTemplate() {
        setConfirmingTemplateDelete(false);
        router.delete(`/message-templates/${templateId}`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => setTemplateId(''),
        });
    }

    function toggle(id) {
        const next = new Set(selected);
        next.has(id) ? next.delete(id) : next.add(id);
        setSelected(next);
    }

    function send() {
        setConfirming(false);
        setSending(true);
        router.post(
            '/messages',
            { ...requestData, approved_only: Boolean(requestData.approved_only), channel, body, subscription_ids: [...selected] },
            { preserveScroll: true, onFinish: () => setSending(false) },
        );
    }

    const withPhone = recipients?.filter((recipient) => recipient.phone) ?? [];
    const allSelected = withPhone.length > 0 && withPhone.every((recipient) => selected.has(recipient.id));
    const previewRecipient = recipients?.find((recipient) => recipient.id === previewId) ?? null;
    const previewText = previewRecipient ? renderMessage(body, previewRecipient.variables) : body;
    const selectedTemplate = templates.find((template) => String(template.id) === String(templateId));
    // Only the chosen branch's boxes.
    const meterBoxNames = meterBoxGroups[0] ? scopedOptions(meterBoxGroups[0], criteria) : [];
    const meterBoxNumbers = meterBoxGroups[1]
        ? scopedOptions(meterBoxGroups[1], criteria).filter((option) => option.parent === criteria.meter_box_name)
        : [];
    const canSend = selected.size > 0 && body.trim() !== '' && !isStale && !sending;
    // Why the send button can't be used yet, said under it.
    const sendBlocker =
        recipients === null
            ? 'اضغط «عرض المستلمين» أولًا لتختار من تصله الرسالة.'
            : isStale
              ? 'حدّث المستلمين بعد تغيير الشروط.'
              : body.trim() === ''
                ? 'اكتب نص الرسالة.'
                : selected.size === 0
                  ? 'حدّد مشتركًا واحدًا على الأقل.'
                  : null;

    return (
        <AuthenticatedLayout
            header={
                <PageHeader eyebrow={<BackLink href="/messages">الرسائل</BackLink>} title="رسالة جديدة" />
            }
        >
            <Head title="رسالة جديدة" />

            <div className="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                <div className="space-y-6">
                    <Card icon="tag" title="نوع الرسالة" description={KIND_HINTS[kind]}>
                        <ChoiceChips options={withIcons(kinds, KIND_ICONS)} value={kind} onChange={changeKind} label="نوع الرسالة" />
                    </Card>

                    <Card icon="filter" title="المستلمون" description="من تصله الرسالة من مشتركي فرعك.">
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            {kind === 'weekly_reading' && (
                                <>
                                    <Field label="أسبوع القراءة" htmlFor="week_start">
                                        <select
                                            id="week_start"
                                            value={criteria.week_start}
                                            onChange={(e) => setCriterion('week_start', e.target.value)}
                                            className="block w-full text-sm"
                                        >
                                            {!branchWeekOptions.some((option) => option.value === criteria.week_start) && (
                                                <option value={criteria.week_start}>الأسبوع ابتداءً من {criteria.week_start}</option>
                                            )}
                                            {branchWeekOptions.map((option) => (
                                                <option key={option.value} value={option.value}>
                                                    {option.label}
                                                </option>
                                            ))}
                                        </select>
                                    </Field>
                                    <div className="flex items-end pb-2">
                                        <Switch
                                            checked={criteria.approved_only}
                                            onChange={(value) => setCriterion('approved_only', value)}
                                            label="القراءات المعتمدة فقط"
                                        />
                                    </div>
                                </>
                            )}

                            {kind === 'balance_reminder' && (
                                <Field label="من عليه رصيد أكبر من (₪)" htmlFor="min_balance">
                                    <input
                                        id="min_balance"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        inputMode="decimal"
                                        value={criteria.min_balance}
                                        onChange={(e) => setCriterion('min_balance', e.target.value)}
                                        placeholder="0"
                                        className="block w-full text-sm"
                                    />
                                </Field>
                            )}

                            <Field label="حالة المشترك" htmlFor="status">
                                <select
                                    id="status"
                                    value={criteria.status}
                                    onChange={(e) => setCriterion('status', e.target.value)}
                                    className="block w-full text-sm"
                                >
                                    <option value="">كل الحالات</option>
                                    {statusOptions.map((option) => (
                                        <option key={option.value} value={option.value}>
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                            </Field>

                            {branchOptions.length > 0 && (
                                <Field label="الفرع" htmlFor="branch_id">
                                    <select
                                        id="branch_id"
                                        value={criteria.branch_id}
                                        onChange={(e) =>
                                            setCriteria((current) => ({ ...current, ...withStaleCleared(meterBoxGroups, current, { branch_id: e.target.value }) }))
                                        }
                                        className="block w-full text-sm"
                                    >
                                        <option value="">كل الفروع</option>
                                        {branchOptions.map((option) => (
                                            <option key={option.value} value={option.value}>
                                                {option.label}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                            )}

                            <Field label="الطبلون" htmlFor="meter_box_name">
                                <select
                                    id="meter_box_name"
                                    value={criteria.meter_box_name}
                                    onChange={(e) => setCriteria((current) => ({ ...current, meter_box_name: e.target.value, meter_box_id: '' }))}
                                    className="block w-full text-sm"
                                >
                                    <option value="">كل الطبلونات</option>
                                    {meterBoxNames.map((option) => (
                                        <option key={option.value} value={option.value}>
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                            </Field>

                            {criteria.meter_box_name !== '' && (
                                <Field label="رقم الطبلون" htmlFor="meter_box_id">
                                    <select
                                        id="meter_box_id"
                                        value={criteria.meter_box_id}
                                        onChange={(e) => setCriterion('meter_box_id', e.target.value)}
                                        className="block w-full text-sm"
                                    >
                                        <option value="">كل الأرقام</option>
                                        {meterBoxNumbers.map((option) => (
                                            <option key={option.value} value={option.value}>
                                                {option.label}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                            )}

                            <Field label="القاطع" htmlFor="circuit_breaker_id">
                                <select
                                    id="circuit_breaker_id"
                                    value={criteria.circuit_breaker_id}
                                    onChange={(e) => setCriterion('circuit_breaker_id', e.target.value)}
                                    className="block w-full text-sm"
                                >
                                    <option value="">كل القواطع</option>
                                    {circuitBreakerOptions.map((option) => (
                                        <option key={option.value} value={option.value}>
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                            </Field>

                            <Field label="بحث" htmlFor="search">
                                <input
                                    id="search"
                                    type="text"
                                    value={criteria.search}
                                    onChange={(e) => setCriterion('search', e.target.value)}
                                    placeholder="الاسم أو الهاتف أو رقم الاشتراك"
                                    className="block w-full text-sm"
                                />
                            </Field>
                        </div>

                        {criteria.subscription_ids.length > 0 && (
                            <div className="mt-4 flex items-center justify-between gap-3 rounded-control bg-gray-50 px-3 py-2 text-sm text-gray-600">
                                <span>الرسالة لمشترك محدد من قائمة المشتركين.</span>
                                <button
                                    type="button"
                                    onClick={() => setCriterion('subscription_ids', [])}
                                    className={`rounded font-semibold text-brand-600 hover:underline ${FOCUS_RING}`}
                                >
                                    إلغاء التحديد
                                </button>
                            </div>
                        )}

                        <div className="mt-5">
                            <SecondaryButton onClick={loadRecipients} disabled={loading} aria-busy={loading}>
                                <Icon name="users" className="h-4 w-4" />
                                {loading ? 'جارٍ التحميل...' : recipients === null ? 'عرض المستلمين' : 'تحديث المستلمين'}
                            </SecondaryButton>
                        </div>
                    </Card>

                    <Card
                        icon="note"
                        title="نص الرسالة"
                        description="اضغط على أي معلومة لإضافتها، وتُكتب لكل مشترك بقيمته."
                    >
                        <div className="mb-4 flex flex-wrap items-end gap-3">
                            <Field label="القالب" htmlFor="template" className="min-w-[200px] flex-1">
                                <select id="template" value={templateId} onChange={(e) => pickTemplate(e.target.value)} className="block w-full text-sm">
                                    <option value="">— بدون قالب —</option>
                                    {kindTemplates.map((template) => (
                                        <option key={template.id} value={template.id}>
                                            {template.name}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            {selectedTemplate && selectedTemplate.body !== body && (
                                <SecondaryButton onClick={updateTemplate}>
                                    <Icon name="check" className="h-4 w-4" />
                                    حفظ التعديل على القالب
                                </SecondaryButton>
                            )}
                            {selectedTemplate && (
                                <SecondaryButton
                                    onClick={() => setConfirmingTemplateDelete(true)}
                                    aria-label={`حذف القالب «${selectedTemplate.name}»`}
                                    title="حذف القالب"
                                    className="text-brand-600 hover:!border-brand-500/40"
                                >
                                    <Icon name="trash" className="h-4 w-4" />
                                    <span className="hidden sm:inline">حذف</span>
                                </SecondaryButton>
                            )}
                            {templateName === null && (
                                <SecondaryButton onClick={() => setTemplateName('')} disabled={body.trim() === ''}>
                                    <Icon name="plus" className="h-4 w-4" />
                                    حفظ كقالب جديد
                                </SecondaryButton>
                            )}
                        </div>

                        {templateName !== null && (
                            <div className="mb-4 flex flex-wrap items-end gap-3 rounded-control bg-gray-50 p-3">
                                <Field label="اسم القالب" htmlFor="template_name" className="min-w-[200px] flex-1">
                                    <input
                                        id="template_name"
                                        type="text"
                                        value={templateName}
                                        onChange={(e) => setTemplateName(e.target.value)}
                                        className="block w-full text-sm"
                                        autoFocus
                                    />
                                    <InputError message={errors.name} className="mt-1" />
                                </Field>
                                <PrimaryButton type="button" onClick={saveTemplate} disabled={templateName.trim() === ''}>
                                    <Icon name="check" className="h-4 w-4" />
                                    حفظ القالب
                                </PrimaryButton>
                                <SecondaryButton onClick={() => setTemplateName(null)}>إلغاء</SecondaryButton>
                            </div>
                        )}

                        <p id="placeholders-hint" className="mb-1.5 text-xs font-semibold text-gray-600">
                            أضف معلومة من بيانات المشترك إلى النص:
                        </p>
                        <div role="group" aria-labelledby="placeholders-hint" className="mb-3 flex flex-wrap gap-1.5">
                            {placeholders[kind].map((name) => (
                                <button
                                    key={name}
                                    type="button"
                                    onClick={() => insertPlaceholder(name)}
                                    aria-label={`أضف «${name.replaceAll('_', ' ')}» إلى نص الرسالة`}
                                    title={`أضف ${name.replaceAll('_', ' ')} مكان المؤشر`}
                                    className={`inline-flex items-center gap-1 rounded-full border border-gray-200 bg-gray-50 px-2.5 py-1 text-xs font-semibold text-gray-700 transition hover:border-brand-500/40 hover:text-brand-600 ${FOCUS_RING}`}
                                >
                                    <Icon name="plus" className="h-3.5 w-3.5" strokeWidth={2} />
                                    {name.replaceAll('_', ' ')}
                                </button>
                            ))}
                        </div>

                        <label htmlFor="body" className="sr-only">
                            نص الرسالة
                        </label>
                        <textarea
                            ref={bodyRef}
                            id="body"
                            rows={6}
                            value={body}
                            onChange={(e) => setBody(e.target.value)}
                            maxLength={1000}
                            className="block w-full text-sm leading-7"
                            placeholder="اكتب الرسالة هنا..."
                            aria-describedby="body-length"
                            aria-invalid={errors.body ? true : undefined}
                        />
                        <InputError message={errors.body} className="mt-1" />
                        <div id="body-length" className="mt-1 text-xs text-gray-500">
                            {previewText.length} حرفًا
                            {channel === 'sms' && <> — نحو {smsParts(previewText.length)} رسالة SMS لكل مشترك</>}
                        </div>
                    </Card>

                    <Card icon="send" title="طريقة الإرسال">
                        <ChoiceChips options={withIcons(channels, CHANNEL_ICONS)} value={channel} onChange={setChannel} label="طريقة الإرسال" />
                        {channel === 'sms' && !smsDeliversMessages && (
                            <p role="note" className="mt-3 flex items-start gap-2 rounded-control bg-amber-500/10 px-3 py-2 text-sm text-amber-800 dark:text-amber-300">
                                <Icon name="warning" className="mt-0.5 h-4 w-4 shrink-0" />
                                لم تُضبط بوابة الرسائل النصية بعد، فتُسجَّل الرسائل في سجل النظام فقط ولا تصل للمشتركين. اطلب ضبط مزوّد SMS، أو أرسل عبر واتساب.
                            </p>
                        )}
                        {channel === 'whatsapp' && (
                            <p className="mt-3 text-sm text-gray-500">
                                تُجهَّز الرسائل، ثم ترسلها من صفحة الإرسال: زر لكل مشترك يفتح واتساب والرسالة مكتوبة.
                            </p>
                        )}
                    </Card>
                </div>

                <div className="space-y-6">
                    <Card icon="eye" title="معاينة الرسالة" description={previewRecipient ? `كما تصل إلى ${previewRecipient.name}` : 'اعرض المستلمين لترى رسالة كل مشترك.'}>
                        <div className="whitespace-pre-line rounded-2xl rounded-tr-sm bg-emerald-500/10 px-4 py-3 leading-7 text-gray-900">
                            {previewText ? <MessageText text={previewText} /> : <span className="text-gray-500">نص الرسالة</span>}
                        </div>
                    </Card>

                    <Card
                        icon="users"
                        title="قائمة المستلمين"
                        description={
                            recipients === null
                                ? 'لم تُعرض بعد.'
                                : `${selected.size.toLocaleString('en')} محدد من ${recipients.length.toLocaleString('en')}`
                        }
                        actions={
                            withPhone.length > 0 && (
                                <button
                                    type="button"
                                    onClick={() => setSelected(new Set(allSelected ? [] : withPhone.map((recipient) => recipient.id)))}
                                    className={`rounded text-xs font-semibold text-brand-600 hover:underline ${FOCUS_RING}`}
                                >
                                    {allSelected ? 'إلغاء الكل' : 'تحديد الكل'}
                                </button>
                            )
                        }
                    >
                        <p className="sr-only" aria-live="polite">
                            {recipients === null ? '' : `${selected.size} مشترك محدد من ${recipients.length}`}
                        </p>
                        {isStale && (
                            <p role="status" className="mb-3 flex items-start gap-2 rounded-control bg-amber-500/10 px-3 py-2 text-sm text-amber-800 dark:text-amber-300">
                                <Icon name="warning" className="mt-0.5 h-4 w-4 shrink-0" />
                                تغيّرت الشروط — اضغط «تحديث المستلمين» قبل الإرسال.
                            </p>
                        )}
                        {recipients !== null && recipients.length >= maxRecipients && (
                            <p className="mb-3 text-sm text-amber-700">تُعرض أول {maxRecipients.toLocaleString('en')} مشترك فقط؛ ضيّق الشروط.</p>
                        )}
                        <InputError message={errors.subscription_ids} className="mb-3" />

                        {recipients !== null && recipients.length === 0 && (
                            <p className="py-6 text-center text-sm text-gray-500">لا يوجد مشتركون يطابقون الشروط.</p>
                        )}

                        {recipients !== null && recipients.length > 0 && (
                            <ul className="max-h-[560px] divide-y divide-gray-100 overflow-y-auto rounded-control border border-gray-100">
                                {recipients.map((recipient) => (
                                    <li
                                        key={recipient.id}
                                        className={`flex items-center gap-3 px-3 py-2.5 text-sm ${previewId === recipient.id ? 'bg-brand-500/5' : ''}`}
                                    >
                                        <input
                                            type="checkbox"
                                            checked={selected.has(recipient.id)}
                                            disabled={!recipient.phone}
                                            onChange={() => toggle(recipient.id)}
                                            aria-label={`إرسال إلى ${recipient.name}`}
                                            className="rounded border-gray-300 text-brand-600 disabled:opacity-40"
                                        />
                                        <button
                                            type="button"
                                            onClick={() => setPreviewId(recipient.id)}
                                            aria-pressed={previewId === recipient.id}
                                            aria-label={`معاينة رسالة ${recipient.name}`}
                                            title="معاينة رسالته"
                                            className={`group min-w-0 flex-1 rounded-lg text-start ${FOCUS_RING}`}
                                        >
                                            <span className="block truncate font-semibold text-gray-900">{recipient.name}</span>
                                            <span className="block truncate text-xs text-gray-500">
                                                {recipient.accountNumber}
                                                {recipient.branchName && branchOptions.length > 0 && <> — {recipient.branchName}</>}
                                            </span>
                                        </button>
                                        {recipient.phone ? (
                                            <span dir="ltr" className="shrink-0 text-xs text-gray-600">
                                                {recipient.phone}
                                            </span>
                                        ) : (
                                            <span className="inline-flex shrink-0 items-center gap-1 text-xs text-brand-600">
                                                <Icon name="warning" className="h-3.5 w-3.5" />
                                                لا يوجد رقم
                                            </span>
                                        )}
                                        <Icon
                                            name="eye"
                                            className={`h-4 w-4 shrink-0 ${previewId === recipient.id ? 'text-brand-600' : 'text-gray-300'}`}
                                        />
                                    </li>
                                ))}
                            </ul>
                        )}

                        <div className="mt-5 flex flex-wrap items-center justify-end gap-3">
                            {sendBlocker && (
                                <p id="send-blocker" className="flex items-center gap-1.5 text-sm text-gray-500">
                                    <Icon name="info" className="h-4 w-4 shrink-0" />
                                    {sendBlocker}
                                </p>
                            )}
                            <PrimaryButton
                                type="button"
                                onClick={() => setConfirming(true)}
                                disabled={!canSend}
                                aria-busy={sending}
                                aria-describedby={sendBlocker ? 'send-blocker' : undefined}
                            >
                                <Icon name="send" className="h-4 w-4" />
                                {sending ? 'جارٍ الإرسال...' : `إرسال إلى ${selected.size.toLocaleString('en')} مشترك`}
                            </PrimaryButton>
                        </div>
                    </Card>
                </div>
            </div>

            <ConfirmDialog
                show={confirmingTemplateDelete}
                onConfirm={deleteTemplate}
                onCancel={() => setConfirmingTemplateDelete(false)}
                title="حذف القالب؟"
                message={`سيُحذف القالب «${selectedTemplate?.name ?? ''}» نهائيًا. الرسائل التي أُرسلت به تبقى كما هي.`}
                confirmLabel="نعم، احذف القالب"
                icon="trash"
                tone="danger"
            />

            <ConfirmDialog
                show={confirming}
                onConfirm={send}
                onCancel={() => setConfirming(false)}
                title={`إرسال ${selected.size.toLocaleString('en')} رسالة؟`}
                message={
                    channel === 'sms'
                        ? 'تُرسل الرسالة لكل مشترك محدد برسالة نصية، وتُكتب معلوماته فيها. لا يمكن التراجع بعد الإرسال.'
                        : 'تُجهَّز رسالة واتساب لكل مشترك محدد، ثم ترسلها من صفحة الإرسال.'
                }
                confirmLabel="نعم، أرسل"
                icon="send"
            />
        </AuthenticatedLayout>
    );
}
