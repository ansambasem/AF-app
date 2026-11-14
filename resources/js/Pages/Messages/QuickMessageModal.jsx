import { useEffect, useId, useRef, useState } from 'react';
import { Link, router, useHttp } from '@inertiajs/react';
import Modal from '@/Components/Modal';
import Icon from '@/Components/Icon';
import ChoiceChips from '@/Components/ChoiceChips';
import Switch from '@/Components/Switch';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import ConfirmDialog from '@/Components/ConfirmDialog';
import InputError from '@/Components/InputError';
import { smsParts } from '@/lib/disconnections';
import { CHANNEL_ICONS, KIND_HINTS, KIND_ICONS, MessageText, renderMessage, withIcons } from './MessageParts';

const SEARCH_DELAY = 250;

/** The keyboard focus ring every custom button here uses, like the app's own buttons. */
const FOCUS_RING = 'focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-900';

/** The recipients' address for the given subscriptions: every status, as they were picked by hand. */
function recipientsUrl(params, ids = []) {
    const query = new URLSearchParams({ status: '', ...params });
    ids.forEach((id) => query.append('subscription_ids[]', id));

    return `/messages/recipients?${query}`;
}

/** Rows by subscription id. */
function byId(rows) {
    return Object.fromEntries(rows.map((row) => [row.id, row]));
}

/** Keep large selections in the request body, rather than in the URL. */
function loadRecipients(http, data) {
    http.transform(() => data);
    return http.post('/messages/recipients');
}

function Section({ title, hint, children }) {
    const titleId = useId();

    return (
        <section aria-labelledby={titleId} className="space-y-3">
            <div>
                <h4 id={titleId} className="text-sm font-bold text-gray-900">
                    {title}
                </h4>
                {hint && <p className="mt-0.5 text-xs text-gray-500">{hint}</p>}
            </div>
            {children}
        </section>
    );
}

/**
 * Write a message from any page without leaving it: pick what it's about
 * (a weekly reading, a balance reminder, any news), the subscriptions it
 * goes to — one or several, found by name, phone or account, or already
 * picked on the page — the wording and how it goes out, then send. Each
 * picked subscription the kind doesn't reach (no reading that week, no
 * phone...) is shown with why. SMS go out and the page stays; WhatsApp
 * messages open on their send's page, to be sent one by one.
 */
export default function QuickMessageModal({ subscriptionIds = [], selection = null, onClose }) {
    const id = useId();
    const setupHttp = useHttp();
    const detailsHttp = useHttp();
    const kindHttp = useHttp();
    const searchHttp = useHttp();
    const bodyRef = useRef(null);
    const searchRef = useRef(null);
    const [setup, setSetup] = useState(null);
    const [setupFailed, setSetupFailed] = useState(false);
    const [kind, setKind] = useState('custom');
    const [weekStart, setWeekStart] = useState('');
    const [approvedOnly, setApprovedOnly] = useState(true);
    const [minBalance, setMinBalance] = useState('');
    const [pickedIds, setPickedIds] = useState(subscriptionIds);
    // Each picked subscription's details, as any message reaches them.
    const [people, setPeople] = useState({});
    const [detailsLoaded, setDetailsLoaded] = useState(subscriptionIds.length === 0 && !selection?.all);
    const [selectionError, setSelectionError] = useState('');
    // The picked subscriptions the chosen kind reaches, with its details, and what they were asked for with.
    const [kindRows, setKindRows] = useState({});
    const [kindLoadedKey, setKindLoadedKey] = useState(null);
    const [search, setSearch] = useState('');
    const [results, setResults] = useState([]);
    const [templateId, setTemplateId] = useState('');
    const [body, setBody] = useState('');
    const [channel, setChannel] = useState('sms');
    const [previewId, setPreviewId] = useState(null);
    const [errors, setErrors] = useState({});
    const [confirming, setConfirming] = useState(false);
    const [sending, setSending] = useState(false);

    useEffect(() => {
        setupHttp
            .get('/messages/quick')
            .then((loaded) => {
                setSetup(loaded);
                setWeekStart(loaded.weekStart);
                setChannel(loaded.smsDeliversMessages ? 'sms' : 'whatsapp');
                const first = loaded.templates.find((template) => template.kind === 'custom');
                setTemplateId(first?.id ?? '');
                setBody(first?.body ?? '');
            })
            .catch(() => setSetupFailed(true));

        if (subscriptionIds.length > 0 || selection?.all) {
            loadRecipients(detailsHttp, { kind: 'custom', status: '', subscription_ids: subscriptionIds, selection })
                .then((loaded) => {
                    setPeople((current) => ({ ...byId(loaded.recipients), ...current }));
                    if (selection?.all) {
                        setPickedIds(loaded.recipients.map((person) => person.id));
                    }
                })
                .catch(() => setSelectionError('تعذّر تحميل المستلمين. تأكد أن النتائج لا تتجاوز 5,000 اشتراك، ثم أغلق النافذة وحدّدهم مجددًا.'))
                .finally(() => setDetailsLoaded(true));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // What the chosen kind is asked for with: who it reaches out of the picked ones depends on all of it.
    const kindParams = { kind };

    if (kind === 'weekly_reading') {
        Object.assign(kindParams, { week_start: weekStart, approved_only: approvedOnly ? 1 : 0 });
    } else if (kind === 'balance_reminder') {
        kindParams.min_balance = minBalance === '' ? 0 : minBalance;
    }

    const kindKey = JSON.stringify([kindParams, pickedIds]);

    // Which of the picked subscriptions the kind reaches, asked again a moment after anything it depends on changes.
    useEffect(() => {
        if (kind === 'custom' || pickedIds.length === 0 || (kind === 'weekly_reading' && !weekStart)) {
            return undefined;
        }

        let current = true;
        const timer = setTimeout(() => {
            loadRecipients(kindHttp, { ...kindParams, status: '', subscription_ids: pickedIds })
                .then((loaded) => {
                    if (current) {
                        setKindRows(byId(loaded.recipients));
                        setKindLoadedKey(kindKey);
                    }
                })
                .catch(() => {});
        }, SEARCH_DELAY);

        return () => {
            current = false;
            clearTimeout(timer);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [kindKey]);

    // Subscriptions whose name, phone or account matches, once two letters are typed.
    useEffect(() => {
        const term = search.trim();

        if (term.length < 2) {
            setResults([]);

            return undefined;
        }

        let current = true;
        const timer = setTimeout(() => {
            searchHttp
                .get(recipientsUrl({ kind: 'custom', search: term }))
                .then((loaded) => current && setResults(loaded.recipients))
                .catch(() => current && setResults([]));
        }, SEARCH_DELAY);

        return () => {
            current = false;
            clearTimeout(timer);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    // Each branch reads on its own weeks: those of the first picked subscription's branch, or else the user's.
    const weekBranchKey = String(people[pickedIds[0]]?.branchId ?? setup?.weekBranchId ?? '');
    const weekOptions = setup?.weekOptions[weekBranchKey] ?? [];

    useEffect(() => {
        if (weekOptions.length > 0 && !weekOptions.some((option) => option.value === weekStart)) {
            setWeekStart(weekOptions[0].value);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [weekBranchKey, setup]);

    const maxRecipients = setup?.maxRecipients ?? 400;
    const kindTemplates = (setup?.templates ?? []).filter((template) => template.kind === kind);
    const selectedTemplate = kindTemplates.find((template) => String(template.id) === String(templateId));
    const reachedRows = kind === 'custom' ? people : kindRows;
    const kindReady = detailsLoaded && (kind === 'custom' || kindLoadedKey === kindKey);

    /** Why a picked subscription won't get the message, or null when it will (or it isn't known yet). */
    function exclusion(subscriptionId) {
        const person = people[subscriptionId];

        if (!person) {
            return detailsLoaded ? 'غير متاح لك' : null;
        }

        if (!person.phone) {
            return 'لا يوجد رقم هاتف';
        }

        if (kindReady && !reachedRows[subscriptionId]) {
            return kind === 'weekly_reading'
                ? approvedOnly
                    ? 'لا توجد له قراءة معتمدة في هذا الأسبوع'
                    : 'لا توجد له قراءة في هذا الأسبوع'
                : 'رصيده لا يتجاوز الحد';
        }

        return null;
    }

    const reachedIds = kindReady ? pickedIds.filter((subscriptionId) => people[subscriptionId]?.phone && reachedRows[subscriptionId]) : [];
    const previewRow = reachedRows[previewId] && reachedIds.includes(previewId) ? reachedRows[previewId] : reachedRows[reachedIds[0]];
    const previewText = previewRow ? renderMessage(body, previewRow.variables) : body;
    const canSend = setup !== null && !selectionError && reachedIds.length > 0 && body.trim() !== '' && !sending;
    const sendBlocker =
        pickedIds.length === 0
            ? 'ابحث عن مشترك وأضفه.'
            : !kindReady
              ? 'جارٍ التحقق من المستلمين...'
              : reachedIds.length === 0
                ? 'لا يصل نوع الرسالة هذا لأحد من المختارين.'
                : body.trim() === ''
                  ? 'اكتب نص الرسالة.'
                  : null;

    function changeKind(nextKind) {
        // Typed text stays; a template's text gives way to the new kind's first template.
        if (body.trim() === '' || selectedTemplate?.body === body) {
            const first = setup.templates.find((template) => template.kind === nextKind);
            setTemplateId(first?.id ?? '');
            setBody(first?.body ?? '');
        } else {
            setTemplateId('');
        }

        setKind(nextKind);
    }

    function pickTemplate(value) {
        setTemplateId(value);
        const template = kindTemplates.find((item) => String(item.id) === String(value));

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

    function pick(row) {
        if (!pickedIds.includes(row.id) && pickedIds.length < maxRecipients) {
            setPeople((current) => ({ ...current, [row.id]: row }));
            setPickedIds((current) => [...current, row.id]);
        }

        setSearch('');
        setResults([]);
        searchRef.current?.focus();
    }

    function unpick(subscriptionId) {
        setPickedIds((current) => current.filter((item) => item !== subscriptionId));
    }

    function send() {
        setConfirming(false);
        setSending(true);
        router.post(
            '/messages',
            { ...kindParams, approved_only: approvedOnly, status: '', channel, body, subscription_ids: reachedIds, stay_on_page: true },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: onClose,
                onError: setErrors,
                onFinish: () => setSending(false),
            },
        );
    }

    const fullPageUrl = `/messages/create?${new URLSearchParams([['kind', kind], ['status', ''], ...pickedIds.map((subscriptionId) => ['subscription_ids[]', subscriptionId])])}`;

    return (
        <>
            <Modal show onClose={onClose} maxWidth="3xl">
                <div role="dialog" aria-modal="true" aria-labelledby={`${id}-title`} className="flex max-h-[90vh] flex-col">
                    <div className="flex items-center justify-between border-b border-gray-100 px-7 py-5">
                        <div className="flex items-center gap-3">
                            <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-sky-500/10 text-sky-600">
                                <Icon name="send" />
                            </span>
                            <h3 id={`${id}-title`} className="text-lg font-bold text-gray-900">
                                رسالة سريعة
                            </h3>
                        </div>
                        <button
                            type="button"
                            onClick={onClose}
                            aria-label="إغلاق"
                            className="rounded-xl p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-900"
                        >
                            <Icon name="close" />
                        </button>
                    </div>

                    {setupFailed ? (
                        <div role="alert" className="px-7 py-10 text-center text-sm text-gray-600">
                            تعذّر تحميل نافذة الرسائل. أغلقها وحاول مرة أخرى.
                        </div>
                    ) : setup === null ? (
                        <div role="status" className="space-y-3 px-7 py-6">
                            <p className="text-sm text-gray-500">جارٍ التحميل…</p>
                            <div className="grid gap-3 motion-safe:animate-pulse" aria-hidden="true">
                                {[1, 2, 3].map((row) => (
                                    <div key={row} className="h-12 rounded-lg bg-gray-100" />
                                ))}
                            </div>
                        </div>
                    ) : (
                        <div className="flex-1 space-y-6 overflow-y-auto px-7 py-6">
                            <Section title="نوع الرسالة" hint={KIND_HINTS[kind]}>
                                <ChoiceChips options={withIcons(setup.kinds, KIND_ICONS)} value={kind} onChange={changeKind} label="نوع الرسالة" />
                                {kind === 'weekly_reading' && (
                                    <div className="flex flex-wrap items-end gap-4">
                                        <div className="min-w-[220px] flex-1">
                                            <label htmlFor={`${id}-week`} className="mb-1.5 block text-sm font-semibold text-gray-700">
                                                أسبوع القراءة
                                            </label>
                                            <select
                                                id={`${id}-week`}
                                                value={weekStart}
                                                onChange={(e) => setWeekStart(e.target.value)}
                                                className="block w-full text-sm"
                                            >
                                                {weekOptions.map((option) => (
                                                    <option key={option.value} value={option.value}>
                                                        {option.label}
                                                    </option>
                                                ))}
                                            </select>
                                            <InputError message={errors.week_start} className="mt-1" />
                                        </div>
                                        <div className="pb-2">
                                            <Switch checked={approvedOnly} onChange={setApprovedOnly} label="القراءات المعتمدة فقط" />
                                        </div>
                                    </div>
                                )}
                                {kind === 'balance_reminder' && (
                                    <div className="max-w-xs">
                                        <label htmlFor={`${id}-min-balance`} className="mb-1.5 block text-sm font-semibold text-gray-700">
                                            لمن عليه رصيد أكبر من (₪)
                                        </label>
                                        <input
                                            id={`${id}-min-balance`}
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            inputMode="decimal"
                                            value={minBalance}
                                            onChange={(e) => setMinBalance(e.target.value)}
                                            placeholder="0"
                                            className="block w-full text-sm"
                                        />
                                    </div>
                                )}
                            </Section>

                            <Section
                                title={`المستلمون${pickedIds.length > 0 ? ` (${pickedIds.length.toLocaleString('en')})` : ''}`}
                                hint={selection?.all ? 'الاشتراكات المطابقة لفلاتر صفحة المشتركين عند فتح النافذة. راجع المستلمين ومعاينة الرسالة قبل الإرسال.' : 'مشترك واحد أو أكثر: ابحث بالاسم أو الهاتف أو رقم الاشتراك وأضفه.'}
                            >
                                {selectionError && <p role="alert" className="text-sm text-red-600">{selectionError}</p>}
                                <div>
                                    <label htmlFor={`${id}-search`} className="sr-only">
                                        بحث عن مشترك
                                    </label>
                                    <div className="relative">
                                        <Icon
                                            name="search"
                                            className="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400"
                                        />
                                        <input
                                            ref={searchRef}
                                            id={`${id}-search`}
                                            type="search"
                                            value={search}
                                            onChange={(e) => setSearch(e.target.value)}
                                            onKeyDown={(e) => {
                                                if (e.key === 'Enter' && results[0]) {
                                                    e.preventDefault();
                                                    pick(results[0]);
                                                }
                                            }}
                                            placeholder="الاسم أو الهاتف أو رقم الاشتراك"
                                            autoComplete="off"
                                            autoFocus={subscriptionIds.length === 0}
                                            disabled={pickedIds.length >= maxRecipients}
                                            className="block w-full ps-9 text-sm"
                                            aria-describedby={`${id}-search-results`}
                                        />
                                    </div>
                                    {search.trim().length >= 2 && (
                                        <ul
                                            id={`${id}-search-results`}
                                            aria-label="نتائج البحث"
                                            aria-busy={searchHttp.processing}
                                            className="mt-1.5 divide-y divide-gray-100 overflow-hidden rounded-control border border-gray-200 bg-surface shadow-card"
                                        >
                                            {results.length === 0 ? (
                                                <li className="px-3 py-2.5 text-sm text-gray-500">
                                                    {searchHttp.processing ? 'جارٍ البحث...' : 'لا يوجد مشترك مطابق.'}
                                                </li>
                                            ) : (
                                                results.map((row) => {
                                                    const alreadyPicked = pickedIds.includes(row.id);

                                                    return (
                                                        <li key={row.id}>
                                                            <button
                                                                type="button"
                                                                onClick={() => pick(row)}
                                                                disabled={alreadyPicked}
                                                                className={`flex w-full items-center gap-3 px-3 py-2.5 text-start text-sm transition hover:bg-gray-50 disabled:cursor-default disabled:opacity-60 ${FOCUS_RING}`}
                                                            >
                                                                <Icon
                                                                    name={alreadyPicked ? 'check' : 'plus'}
                                                                    className="h-4 w-4 shrink-0 text-gray-400"
                                                                />
                                                                <span className="min-w-0 flex-1">
                                                                    <span className="block truncate font-semibold text-gray-900">{row.name}</span>
                                                                    <span className="block truncate text-xs text-gray-500">{row.accountNumber}</span>
                                                                </span>
                                                                <span dir="ltr" className="shrink-0 text-xs text-gray-600">
                                                                    {row.phone ?? 'لا يوجد رقم'}
                                                                </span>
                                                            </button>
                                                        </li>
                                                    );
                                                })
                                            )}
                                        </ul>
                                    )}
                                </div>

                                {pickedIds.length >= maxRecipients && (
                                    <p className="text-sm text-amber-700">
                                        الرسالة السريعة تصل لـ {maxRecipients.toLocaleString('en')} مشترك على الأكثر؛ للأكثر استخدم صفحة الرسائل.
                                    </p>
                                )}

                                {pickedIds.length > 0 && (
                                    <ul
                                        aria-label="المستلمون المختارون"
                                        className="max-h-56 divide-y divide-gray-100 overflow-y-auto rounded-control border border-gray-100"
                                    >
                                        {pickedIds.map((subscriptionId) => {
                                            const person = people[subscriptionId];
                                            const reason = exclusion(subscriptionId);

                                            return (
                                                <li
                                                    key={subscriptionId}
                                                    className={`flex items-center gap-3 px-3 py-2 text-sm ${previewRow?.id === subscriptionId ? 'bg-brand-500/5' : ''}`}
                                                >
                                                    <button
                                                        type="button"
                                                        onClick={() => setPreviewId(subscriptionId)}
                                                        disabled={Boolean(reason) || !person}
                                                        title="معاينة رسالته"
                                                        className={`min-w-0 flex-1 rounded-lg text-start disabled:cursor-default ${FOCUS_RING}`}
                                                    >
                                                        <span
                                                            className={`block truncate font-semibold ${reason ? 'text-gray-400 line-through' : 'text-gray-900'}`}
                                                        >
                                                            {person?.name ?? (detailsLoaded ? `مشترك رقم ${subscriptionId}` : '…')}
                                                        </span>
                                                        <span className="block truncate text-xs text-gray-500">
                                                            {person?.accountNumber}
                                                            {person?.phone && (
                                                                <>
                                                                    {' — '}
                                                                    <bdi dir="ltr">{person.phone}</bdi>
                                                                </>
                                                            )}
                                                        </span>
                                                    </button>
                                                    {reason && (
                                                        <span className="inline-flex shrink-0 items-center gap-1 text-xs text-amber-700 dark:text-amber-400">
                                                            <Icon name="warning" className="h-3.5 w-3.5" />
                                                            {reason}
                                                        </span>
                                                    )}
                                                    <button
                                                        type="button"
                                                        onClick={() => unpick(subscriptionId)}
                                                        aria-label={`إزالة ${person?.name ?? 'المشترك'}`}
                                                        title="إزالة"
                                                        className={`shrink-0 rounded-lg p-1 text-gray-400 hover:bg-gray-100 hover:text-brand-600 ${FOCUS_RING}`}
                                                    >
                                                        <Icon name="close" className="h-4 w-4" />
                                                    </button>
                                                </li>
                                            );
                                        })}
                                    </ul>
                                )}
                                <InputError message={errors.subscription_ids} />
                            </Section>

                            <Section title="نص الرسالة" hint="اضغط على أي معلومة لإضافتها، وتُكتب لكل مشترك بقيمته.">
                                {kindTemplates.length > 0 && (
                                    <div>
                                        <label htmlFor={`${id}-template`} className="mb-1.5 block text-sm font-semibold text-gray-700">
                                            القالب
                                        </label>
                                        <select
                                            id={`${id}-template`}
                                            value={templateId}
                                            onChange={(e) => pickTemplate(e.target.value)}
                                            className="block w-full text-sm"
                                        >
                                            <option value="">— بدون قالب —</option>
                                            {kindTemplates.map((template) => (
                                                <option key={template.id} value={template.id}>
                                                    {template.name}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                )}
                                <div role="group" aria-label="أضف معلومة من بيانات المشترك إلى النص" className="flex flex-wrap gap-1.5">
                                    {setup.placeholders[kind].map((name) => (
                                        <button
                                            key={name}
                                            type="button"
                                            onClick={() => insertPlaceholder(name)}
                                            aria-label={`أضف «${name.replaceAll('_', ' ')}» إلى نص الرسالة`}
                                            className={`inline-flex items-center gap-1 rounded-full border border-gray-200 bg-gray-50 px-2.5 py-1 text-xs font-semibold text-gray-700 transition hover:border-brand-500/40 hover:text-brand-600 ${FOCUS_RING}`}
                                        >
                                            <Icon name="plus" className="h-3.5 w-3.5" strokeWidth={2} />
                                            {name.replaceAll('_', ' ')}
                                        </button>
                                    ))}
                                </div>
                                <label htmlFor={`${id}-body`} className="sr-only">
                                    نص الرسالة
                                </label>
                                <textarea
                                    ref={bodyRef}
                                    id={`${id}-body`}
                                    rows={4}
                                    value={body}
                                    onChange={(e) => setBody(e.target.value)}
                                    maxLength={1000}
                                    className="block w-full text-sm leading-7"
                                    placeholder="اكتب الرسالة هنا..."
                                    aria-describedby={`${id}-body-length`}
                                    aria-invalid={errors.body ? true : undefined}
                                />
                                <InputError message={errors.body} />
                                {previewRow && body.trim() !== '' && (
                                    <div>
                                        <p className="mb-1.5 text-xs font-semibold text-gray-600">كما تصل إلى {previewRow.name}:</p>
                                        <div className="whitespace-pre-line rounded-2xl rounded-tr-sm bg-emerald-500/10 px-4 py-3 text-sm leading-7 text-gray-900">
                                            <MessageText text={previewText} />
                                        </div>
                                    </div>
                                )}
                                <p id={`${id}-body-length`} className="text-xs text-gray-500">
                                    {previewText.length} حرفًا
                                    {channel === 'sms' && <> — نحو {smsParts(previewText)} رسالة SMS لكل مشترك</>}
                                </p>
                            </Section>

                            <Section title="طريقة الإرسال">
                                <ChoiceChips
                                    options={withIcons(setup.channels, CHANNEL_ICONS)}
                                    value={channel}
                                    onChange={setChannel}
                                    label="طريقة الإرسال"
                                />
                                {channel === 'sms' && !setup.smsDeliversMessages && (
                                    <p
                                        role="note"
                                        className="flex items-start gap-2 rounded-control bg-amber-500/10 px-3 py-2 text-sm text-amber-800 dark:text-amber-300"
                                    >
                                        <Icon name="warning" className="mt-0.5 h-4 w-4 shrink-0" />
                                        لم تُضبط بوابة الرسائل النصية بعد، فتُسجَّل الرسائل في سجل النظام فقط ولا تصل للمشتركين. أرسل عبر واتساب أو
                                        اطلب ضبط مزوّد SMS.
                                    </p>
                                )}
                                {channel === 'whatsapp' && (
                                    <p className="text-sm text-gray-500">
                                        تُجهَّز الرسائل وتنتقل إلى صفحة الإرسال: زر لكل مشترك يفتح واتساب والرسالة مكتوبة.
                                    </p>
                                )}
                            </Section>
                        </div>
                    )}

                    <div className="flex flex-wrap items-center justify-end gap-3 border-t border-gray-100 bg-gray-50 px-7 py-4">
                        {setup !== null && pickedIds.length <= 400 && (
                            <Link
                                href={fullPageUrl}
                                className={`me-auto inline-flex items-center gap-1 rounded text-sm font-semibold text-gray-600 hover:text-gray-900 ${FOCUS_RING}`}
                            >
                                <Icon name="external" className="h-4 w-4" />
                                الصفحة الكاملة
                            </Link>
                        )}
                        {setup !== null && sendBlocker && (
                            <p id={`${id}-send-blocker`} className="flex items-center gap-1.5 text-sm text-gray-500">
                                <Icon name="info" className="h-4 w-4 shrink-0" />
                                {sendBlocker}
                            </p>
                        )}
                        <SecondaryButton onClick={onClose}>إلغاء</SecondaryButton>
                        <PrimaryButton
                            type="button"
                            onClick={() => setConfirming(true)}
                            disabled={!canSend}
                            aria-busy={sending}
                            aria-describedby={sendBlocker ? `${id}-send-blocker` : undefined}
                        >
                            <Icon name="send" className="h-4 w-4" />
                            {sending ? 'جارٍ الإرسال...' : `إرسال إلى ${reachedIds.length.toLocaleString('en')} مشترك`}
                        </PrimaryButton>
                    </div>
                </div>
            </Modal>

            <ConfirmDialog
                show={confirming}
                onConfirm={send}
                onCancel={() => setConfirming(false)}
                title={`إرسال ${reachedIds.length.toLocaleString('en')} رسالة؟`}
                message={
                    channel === 'sms'
                        ? 'تُرسل الرسالة لكل مشترك برسالة نصية، وتُكتب معلوماته فيها. لا يمكن التراجع بعد الإرسال.'
                        : 'تُجهَّز رسالة واتساب لكل مشترك، ثم ترسلها من صفحة الإرسال.'
                }
                confirmLabel="نعم، أرسل"
                icon="send"
            />
        </>
    );
}
