import { useEffect, useId, useMemo, useState } from 'react';
import { router, useHttp } from '@inertiajs/react';
import Modal from '@/Components/Modal';
import Icon from '@/Components/Icon';
import Balance from '@/Components/Balance';
import ChoiceChips from '@/Components/ChoiceChips';
import StatusPill from '@/Components/DataTable/StatusPill';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import DangerButton from '@/Components/DangerButton';
import InputError from '@/Components/InputError';
import { formatMoney, formatDate } from '@/lib/format';
import { feeError, fillPlaceholders, itemTone, operationId, smsParts, totalFees } from '@/lib/disconnections';
import HoldToConfirm from './HoldToConfirm';
import DateInput from '@/Components/DateInput';

const ACTIONS = {
    reschedule: { title: 'تغيير موعد القطع', icon: 'calendar', tone: 'bg-blue-500/10 text-blue-600', url: 'reschedule' },
    remove: { title: 'إزالة من القائمة', icon: 'undo', tone: 'bg-amber-500/10 text-amber-700', url: 'remove' },
    warn: { title: 'إرسال إنذار', icon: 'send', tone: 'bg-sky-500/10 text-sky-600', url: 'warn' },
    confirm: { title: 'تأكيد تنفيذ القطع', icon: 'power', tone: 'bg-red-900/10 text-red-800', url: 'confirm' },
};

/** Why an item would be left out of the action, or null when it is reached. */
function exclusion(action, item) {
    if (item.status !== 'scheduled') {
        return item.status === 'disconnected' ? 'تم قطعه مسبقًا' : 'أُزيل من القائمة';
    }

    if (action === 'warn' && !item.phone) {
        return 'لا يوجد رقم هاتف';
    }

    if (action === 'confirm' && !item.serviceIsActive) {
        return `خدمته ${item.serviceStatusLabel}`;
    }

    return null;
}

/** One subscriber's value for each placeholder, as the server fills them in. */
function placeholderValues(item, list) {
    return {
        الاسم: item.name,
        رقم_الاشتراك: item.accountNumber,
        الطبلون: item.meterBoxName ?? '',
        الفرع: list.branchName,
        الرصيد: formatMoney(item.balance),
        موعد_القطع: formatDate(item.scheduledFor),
    };
}

/**
 * A bulk action on the list's chosen subscribers. It first loads exactly
 * which subscribers it reaches — the ticked ones, or every match of the
 * filters across all pages — and lists them with those it would leave out
 * and why, then asks for what the action needs and sends it once for the
 * subscribers listed.
 */
export default function ActionModal({ action, list, selection, count, reasonOptions, channelOptions, smsDeliversMessages, warningTemplates, defaultWarning, placeholders, today, usualFee, canChangeFee = false, onClose, onDone }) {
    const id = useId();
    const http = useHttp({});
    const [preview, setPreview] = useState(null);
    const [failed, setFailed] = useState(false);
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState({});
    const [date, setDate] = useState(today);
    const [reason, setReason] = useState('');
    const [channel, setChannel] = useState('sms');
    const [body, setBody] = useState(defaultWarning);
    const [confirmed, setConfirmed] = useState(false);
    const [fees, setFees] = useState({});
    const [feeForAll, setFeeForAll] = useState(String(Number(usualFee)));
    const [sendId] = useState(operationId);
    const meta = ACTIONS[action];

    useEffect(() => {
        let current = true;
        http.transform(() => selection);
        http.post(`/disconnection-lists/${list.id}/items/preview`)
            .then((loaded) => {
                if (!current) {
                    return;
                }

                setPreview(loaded);
                setFees(Object.fromEntries(loaded.items.map((item) => [item.id, item.fee == null ? '' : String(Number(item.fee))])));
            })
            .catch(() => current && setFailed(true));

        return () => {
            current = false;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const reached = useMemo(() => (preview?.items ?? []).filter((item) => !exclusion(action, item)), [preview, action]);
    const excluded = useMemo(() => (preview?.items ?? []).filter((item) => exclusion(action, item)), [preview, action]);
    const chosenFees = useMemo(() => reached.map((item) => ({ fee: fees[item.id] })), [reached, fees]);
    const feesAreValid = chosenFees.every(({ fee }) => fee !== '' && Number(fee) >= 0);

    function submit() {
        if (reached.length === 0) {
            return;
        }

        const ids = reached.map((item) => item.id);
        const payload = {
            reschedule: { ids, scheduled_for: date, reason },
            remove: { ids, reason },
            warn: { ids, channel, body, operation_id: sendId },
            confirm: { ids, fees: Object.fromEntries(reached.map((item) => [item.id, fees[item.id]])), confirmed },
        }[action];

        setSaving(true);
        router.post(`/disconnection-lists/${list.id}/items/${meta.url}`, payload, {
            preserveScroll: true,
            onSuccess: onDone,
            onError: (found) => {
                setErrors(found);

                if (found.ids) {
                    onDone();
                }
            },
            onFinish: () => setSaving(false),
        });
    }

    const firstText = reached[0] ? fillPlaceholders(body, placeholderValues(reached[0], list)) : '';
    const parts = smsParts(firstText);

    return (
        <Modal show onClose={saving ? undefined : onClose} maxWidth="3xl">
            <form
                onSubmit={(event) => {
                    event.preventDefault();

                    // A disconnection is confirmed only by holding its button (or typing the count), never by Enter.
                    if (action !== 'confirm') {
                        submit();
                    }
                }}
                role="dialog" aria-modal="true" aria-labelledby={`${id}-title`} className="flex max-h-[92vh] flex-col">
                <div className="flex items-start gap-4 border-b border-gray-100 px-7 py-5">
                    <span className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-xl ${meta.tone}`}>
                        <Icon name={meta.icon} strokeWidth={2} />
                    </span>
                    <div className="min-w-0">
                        <h3 id={`${id}-title`} className="text-lg font-bold text-gray-900">{meta.title}</h3>
                        <p className="mt-1 text-sm text-gray-600">
                            {preview ? (
                                <>
                                    يُطبَّق على <b className="font-display text-gray-900">{reached.length.toLocaleString('en')}</b> مشترك
                                    {excluded.length > 0 && <> · يُستثنى <b className="font-display">{excluded.length.toLocaleString('en')}</b></>}
                                    {selection.all ? ' (من كل النتائج المطابقة للفلاتر في كل الصفحات)' : ' (من المحددين)'}
                                </>
                            ) : (
                                `جارٍ تحميل ${count.toLocaleString('en')} مشترك…`
                            )}
                        </p>
                    </div>
                </div>

                <div className="flex-1 space-y-5 overflow-y-auto px-7 py-5">
                    {failed && <p className="text-sm text-brand-600">تعذّر تحميل المشتركين؛ أغلق النافذة وحاول مرة أخرى.</p>}
                    {preview?.tooMany && (
                        <p className="rounded-control bg-amber-500/10 px-3 py-2 text-sm text-amber-800">
                            النتائج أكثر من {preview.max.toLocaleString('en')} مشترك؛ ضيّق الفلاتر. لن يُطبَّق الإجراء إلا على المعروضين هنا.
                        </p>
                    )}

                    {preview && (
                        <div className="overflow-hidden rounded-control border border-gray-100">
                            <div className="max-h-64 overflow-y-auto">
                                <table className="w-full text-sm">
                                    <thead className="sticky top-0 bg-gray-50 text-xs text-gray-500">
                                        <tr>
                                            <th className="px-3 py-2 text-start">المشترك</th>
                                            <th className="px-3 py-2 text-start">الحالة</th>
                                            {action === 'warn' && <th className="px-3 py-2 text-start">الجوال</th>}
                                            {action === 'confirm' && <th className="px-3 py-2 text-start">الخدمة</th>}
                                            <th className="px-3 py-2 text-start">الرصيد</th>
                                            {action === 'confirm' && <th className="px-3 py-2 text-start">رسوم القطع</th>}
                                            {action !== 'confirm' && <th className="px-3 py-2 text-start">الموعد</th>}
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-100">
                                        {preview.items.map((item) => {
                                            const why = exclusion(action, item);

                                            return (
                                                <tr key={item.id} className={why ? 'bg-gray-50 text-gray-400' : undefined}>
                                                    <td className="px-3 py-2">
                                                        <b className={why ? 'line-through' : 'text-gray-900'}>{item.name}</b>
                                                        <span dir="ltr" className="block text-end font-display text-xs text-gray-500 rtl:text-start">{item.accountNumber}</span>
                                                        {why && <span className="block text-xs text-amber-700">يُستثنى: {why}</span>}
                                                    </td>
                                                    <td className="px-3 py-2"><StatusPill tone={itemTone(item)} label={item.isOverdue ? 'متأخر' : item.statusLabel} /></td>
                                                    {action === 'warn' && <td className="px-3 py-2 font-display text-xs" dir="ltr">{item.phone ?? '—'}</td>}
                                                    {action === 'confirm' && <td className="px-3 py-2 text-xs">{item.serviceStatusLabel}</td>}
                                                    <td className="whitespace-nowrap px-3 py-2"><Balance value={item.balance} /></td>
                                                    {action === 'confirm' && (
                                                        <td className="whitespace-nowrap px-3 py-2 font-semibold">
                                                            {canChangeFee && !why ? (
                                                                <span className="flex items-center gap-1.5">
                                                                    <input
                                                                        type="number"
                                                                        min={0}
                                                                        step="0.01"
                                                                        value={fees[item.id] ?? ''}
                                                                        onChange={(event) => setFees((current) => ({ ...current, [item.id]: event.target.value }))}
                                                                        aria-label={`رسوم قطع ${item.name}`}
                                                                        className="w-24 py-1 text-sm"
                                                                    />
                                                                    ₪
                                                                </span>
                                                            ) : (
                                                                <>{formatMoney(fees[item.id] ?? item.fee)} ₪</>
                                                            )}
                                                        </td>
                                                    )}
                                                    {action !== 'confirm' && <td className="whitespace-nowrap px-3 py-2 text-xs" dir="ltr">{formatDate(item.scheduledFor)}</td>}
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}

                    {preview && action === 'reschedule' && (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label htmlFor={`${id}-date`} className="mb-1.5 block text-sm font-semibold text-gray-700">موعد القطع الجديد</label>
                                <DateInput id={`${id}-date`} name="scheduled_for" min={today} value={date} onChange={setDate} required />
                                <InputError message={errors.scheduled_for} className="mt-1" />
                            </div>
                            <div>
                                <label htmlFor={`${id}-reason`} className="mb-1.5 block text-sm font-semibold text-gray-700">سبب التمديد (اختياري)</label>
                                <input id={`${id}-reason`} type="text" value={reason} onChange={(event) => setReason(event.target.value)} className="block w-full text-sm" maxLength={1000} />
                            </div>
                            <p className="flex items-start gap-1.5 text-xs text-gray-500 sm:col-span-2">
                                <Icon name="info" className="mt-0.5 h-4 w-4 shrink-0" />
                                يُسجَّل كل تمديد بالموعد القديم والجديد. لا تُرسل أي رسالة للمشتركين.
                            </p>
                        </div>
                    )}

                    {preview && action === 'remove' && (
                        <div>
                            <label htmlFor={`${id}-reason`} className="mb-1.5 block text-sm font-semibold text-gray-700">سبب الإزالة (اختياري)</label>
                            <input id={`${id}-reason`} type="text" value={reason} onChange={(event) => setReason(event.target.value)} className="block w-full text-sm" maxLength={1000} />
                            <p className="mt-2 text-xs text-gray-500">يبقى سجلهم في القائمة، ويمكن إدراجهم من جديد لاحقًا.</p>
                        </div>
                    )}

                    {preview && action === 'warn' && (
                        <div className="space-y-4">
                            <ChoiceChips options={channelOptions} value={channel} onChange={setChannel} label="طريقة الإرسال" />
                            {channel === 'sms' && !smsDeliversMessages && (
                                <p className="rounded-control bg-amber-500/10 px-3 py-2 text-xs text-amber-800">بوابة الرسائل غير مفعّلة؛ تُسجَّل الرسائل في السجل فقط ولا تصل للمشتركين.</p>
                            )}
                            {warningTemplates.length > 0 && (
                                <div className="flex flex-wrap gap-2">
                                    {warningTemplates.map((template) => (
                                        <button key={template.id} type="button" onClick={() => setBody(template.body)} className="rounded-full border border-gray-200 px-3 py-1 text-xs font-semibold text-gray-700 hover:bg-gray-50">
                                            {template.name}
                                        </button>
                                    ))}
                                </div>
                            )}
                            <div>
                                <label htmlFor={`${id}-body`} className="mb-1.5 block text-sm font-semibold text-gray-700">نص الإنذار</label>
                                <textarea id={`${id}-body`} value={body} onChange={(event) => setBody(event.target.value)} rows={4} className="block w-full text-sm" maxLength={1000} required />
                                <p className="mt-1 flex flex-wrap gap-1.5 text-xs text-gray-500">
                                    الحقول:
                                    {placeholders.map((name) => (
                                        <button key={name} type="button" onClick={() => setBody((current) => `${current} {${name}}`)} className="rounded bg-gray-100 px-1.5 font-display">{`{${name}}`}</button>
                                    ))}
                                </p>
                                <InputError message={errors.body} className="mt-1" />
                            </div>
                            {firstText && (
                                <div className="rounded-control border border-gray-100 bg-gray-50 p-3 text-sm">
                                    <p className="mb-1 text-xs font-semibold text-gray-500">معاينة رسالة {reached[0].name}</p>
                                    <p className="whitespace-pre-line text-gray-800">{firstText}</p>
                                </div>
                            )}
                            <p className="text-xs text-gray-600">
                                المستلمون: <b className="font-display">{reached.length.toLocaleString('en')}</b>
                                {channel === 'sms' && <> · كل رسالة نحو <b className="font-display">{parts}</b> جزء SMS · التكلفة التقريبية <b className="font-display">{(parts * reached.length).toLocaleString('en')}</b> رسالة</>}
                                . الإرسال يدوي في كل مرة؛ لا تُرسل إنذارات تلقائيًا.
                            </p>
                        </div>
                    )}

                    {preview && action === 'confirm' && (
                        <div className="space-y-4">
                            {canChangeFee && reached.length > 0 && (
                                <div className="flex flex-wrap items-end gap-3 rounded-control border border-gray-200 p-3">
                                    <div>
                                        <label htmlFor={`${id}-fee`} className="mb-1.5 block text-sm font-semibold text-gray-700">رسوم قطع الخدمة</label>
                                        <span className="flex items-center gap-1.5">
                                            <input id={`${id}-fee`} type="number" min={0} step="0.01" value={feeForAll} onChange={(event) => setFeeForAll(event.target.value)} className="w-28 text-sm" />
                                            ₪
                                        </span>
                                    </div>
                                    <SecondaryButton onClick={() => setFees((current) => ({ ...current, ...Object.fromEntries(reached.map((item) => [item.id, feeForAll])) }))} disabled={feeForAll === '' || Number(feeForAll) < 0}>
                                        تطبيق على الجميع ({reached.length.toLocaleString('en')})
                                    </SecondaryButton>
                                    <p className="basis-full text-xs text-gray-500">الرسوم المعتادة {formatMoney(usualFee)} ₪. يمكنك تغييرها لكل مشترك في الجدول، و0 يعفيه منها.</p>
                                </div>
                            )}
                            <div className="flex flex-wrap items-center justify-between gap-3 rounded-control border border-red-500/20 bg-red-500/5 px-4 py-3">
                                <p className="text-sm text-gray-700">
                                    <b className="font-display text-gray-900">{reached.length.toLocaleString('en')}</b> مشترك · إجمالي رسوم القطع المقدّرة
                                </p>
                                <p className="font-display text-2xl font-bold text-gray-900">{formatMoney(totalFees(chosenFees))} ₪</p>
                            </div>
                            <p className="text-xs leading-5 text-gray-600">
                                عند التأكيد تتحول خدمة كل مشترك إلى «مفصول» وتُحمَّل عليه رسوم قطع الخدمة المبيّنة مرة واحدة. إن تغيّرت حالة الخدمة بعد هذه المعاينة يُستثنى المشترك ويظهر في التقرير.
                            </p>
                            <label className="flex items-start gap-3 rounded-control border border-gray-200 p-3 text-sm font-semibold text-gray-800">
                                <input type="checkbox" checked={confirmed} onChange={(event) => setConfirmed(event.target.checked)} className="mt-0.5 rounded border-gray-300 text-red-700" />
                                أؤكد أن قطع الخدمة قد نُفّذ فعليًا للمشتركين المحددين.
                            </label>
                            <InputError message={errors.confirmed} />
                            <InputError message={feeError(errors)} />
                            {!feesAreValid && <p className="text-sm text-brand-600">أدخل رسوم القطع لكل مشترك (0 للإعفاء).</p>}
                            <HoldToConfirm count={reached.length} disabled={!confirmed || reached.length === 0 || !feesAreValid} busy={saving} onConfirm={() => submit()} label={`اضغط مع الاستمرار لتأكيد قطع ${reached.length.toLocaleString('en')} مشترك`} />
                        </div>
                    )}

                    <InputError message={errors.ids} />
                </div>

                <div className="flex flex-wrap items-center justify-end gap-3 border-t border-gray-100 bg-gray-50 px-7 py-4">
                    <SecondaryButton onClick={onClose} disabled={saving}>إلغاء</SecondaryButton>
                    {action === 'remove' && (
                        <DangerButton type="submit" disabled={saving || !preview || reached.length === 0}>
                            {saving ? 'جارٍ التنفيذ…' : `إزالة ${reached.length.toLocaleString('en')} مشترك`}
                        </DangerButton>
                    )}
                    {(action === 'reschedule' || action === 'warn') && (
                        <PrimaryButton type="submit" disabled={saving || !preview || reached.length === 0 || (action === 'warn' && !body.trim())}>
                            <Icon name={meta.icon} className="h-4 w-4" />
                            {saving ? 'جارٍ التنفيذ…' : action === 'warn' ? `إرسال إلى ${reached.length.toLocaleString('en')} مشترك` : `تطبيق على ${reached.length.toLocaleString('en')} مشترك`}
                        </PrimaryButton>
                    )}
                </div>
            </form>
        </Modal>
    );
}
