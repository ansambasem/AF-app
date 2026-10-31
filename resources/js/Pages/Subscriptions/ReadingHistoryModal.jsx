import { Fragment, useEffect, useId, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import ConfirmDialog from '@/Components/ConfirmDialog';
import LastReadingConfirmDialog from '@/Components/LastReadingConfirmDialog';
import Icon from '@/Components/Icon';
import Modal from '@/Components/Modal';
import TableExportActions from '@/Components/DataTable/TableExportActions';
import { readingHistoryExcelParts } from '@/lib/detailExcelParts';
import { formatMoney, formatNumber, formatDate } from '@/lib/format';
import { groupReadingsByMonth, readingTotals, readingsInPeriod } from '@/lib/readingHistory';
import { consumptionBetween, steppedReading, weeklyCharges } from '@/lib/readings';
import { useLastReadingEdit } from '@/hooks/useLastReadingEdit';
import ReadingPricingField from './ReadingPricingField';
import ReadingApprovalDialog from '@/Pages/MeterReadings/ReadingApprovalDialog';
import './ReadingHistoryModal.css';

const PERIODS = [['12', 'آخر 12 أسبوعًا'], ['26', '6 أشهر'], ['52', 'سنة'], ['all', 'الكل']];
const STATUSES = [['all', 'الكل'], ['approved', 'معتمدة'], ['pending', 'قيد المراجعة']];
const MONTH = new Intl.DateTimeFormat('ar-EG-u-nu-latn', { month: 'long', year: 'numeric', timeZone: 'UTC' });
const money = (value) => Number(value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const CHART_OPEN_KEY = 'reading-history-chart-open';
const shortDate = (date) => date ? `${date.slice(8, 10)}/${date.slice(5, 7)}` : '—';

function HistoryIcon({ name }) {
    const paths = {
        app: <><rect x="6" y="2" width="12" height="20" rx="3" /><path d="M11 18h2" /></>,
        web: <><rect x="3" y="4" width="18" height="13" rx="2" /><path d="M8 21h8M12 17v4" /></>,
    };

    return <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">{paths[name]}</svg>;
}

function Figure({ label, value, unit, children }) {
    return <div className="rh-figure"><small>{label}</small><b>{value}{unit && <em>{unit}</em>}</b><span>{children ?? '—'}</span></div>;
}

function ReadingHistoryStatus({ subscription, reading, disabled = false }) {
    const [confirming, setConfirming] = useState(false);
    const [approving, setApproving] = useState(false);
    const [error, setError] = useState(null);
    const canApprove = reading.canApprove && reading.status === 'pending' && !reading.requiresReentry;
    const label = reading.requiresReentry ? 'بانتظار إدخال الحالية' : reading.status === 'pending' ? 'بانتظار الاعتماد' : reading.statusLabel;

    function approve() {
        if (!canApprove || disabled || approving) return;

        setConfirming(false);
        setApproving(true);
        setError(null);
        router.post('/meter-readings/approve', {
            reading_ids: [reading.id],
            ...(reading.unusual ? { confirm_unusual: true } : {}),
        }, {
            preserveState: true,
            preserveScroll: true,
            onError: (errors) => setError(Object.values(errors)[0] ?? 'تعذّر اعتماد القراءة.'),
            onFinish: () => setApproving(false),
        });
    }

    return <>
        {canApprove ? <button type="button" className="rh-pill is-pending rh-pill-action" disabled={disabled || approving} onClick={() => setConfirming(true)} aria-label={`اعتماد قراءة ${subscription.display_name}`} title={disabled ? 'احفظ تعديل القراءة أولًا' : 'اضغط لمراجعة واعتماد هذه القراءة فقط'}><i />{approving ? 'جارٍ الاعتماد...' : label}<Icon name="check" className="h-3.5 w-3.5" /></button> : <span className={`rh-pill ${reading.status === 'approved' ? 'is-approved' : 'is-pending'}`}><i />{label}</span>}
        <ReadingApprovalDialog row={confirming ? { fullName: subscription.display_name, previousReading: reading.previous_reading, reading: { ...reading, currentReading: reading.current_reading } } : null} onConfirm={approve} onCancel={() => setConfirming(false)} />
        {error && <small className="rh-live-error" role="alert">{error}</small>}
    </>;
}

function ReadingAdjustmentHistory({ reading }) {
    const labels = { baseline_reset: 'تغيير نقطة البداية', initial_reading_adjustment: 'تعديل قراءة بداية الاشتراك', reading_correction: 'تصحيح القراءة', current_reading_cleared: 'طلب إدخال الحالية مجددًا', pricing: 'تعديل التسعير' };
    const fields = { previousReading: 'السابقة', currentReading: 'الحالية', unit_price: 'سعر الكيلو', minimum_payment: 'الحد الأدنى' };

    return <>
        {reading.requiresReentry && <span className="rh-note">بانتظار إدخال القراءة الحالية من جديد</span>}
        {!reading.requiresReentry && reading.nextPreviousReading != null && Number(reading.nextPreviousReading) !== Number(reading.current_reading) && <span className="rh-note">نقطة البداية التالية: {formatMoney(reading.nextPreviousReading)} · القراءة المفوترة: {formatMoney(reading.current_reading)}</span>}
        {reading.adjustmentHistory?.length > 0 && <details className="mt-2 text-xs font-normal"><summary className="cursor-pointer text-brand-700">سجل التعديلات ({reading.adjustmentHistory.length})</summary><div className="space-y-2 py-2">{[...reading.adjustmentHistory].reverse().map((entry, index) => <div key={index} className="rounded border border-gray-200 p-2">
            <b>{labels[entry.kind] ?? 'تعديل'}</b><p>{entry.userName} · <bdi dir="ltr">{entry.at}</bdi></p>
            {Object.entries(entry.after).filter(([field]) => fields[field]).map(([field, value]) => <p key={field}>{fields[field]}: <bdi dir="ltr">{entry.before[field] == null ? '—' : formatMoney(entry.before[field])} ← {value == null ? '—' : formatMoney(value)}</bdi></p>)}
            {entry.reason && <p>السبب: {entry.reason}</p>}
        </div>)}</div></details>}
    </>;
}

function HistoryChart({ readings, average }) {
    const hatchId = useId();
    const plotId = useId();
    const plotRef = useRef(null);
    const [width, setWidth] = useState(1000);
    const [activeId, setActiveId] = useState(null);
    const [open, setOpen] = useState(() => {
        try {
            return window.localStorage.getItem(CHART_OPEN_KEY) !== '0';
        } catch {
            return true;
        }
    });

    function toggle() {
        const next = !open;

        setOpen(next);
        setActiveId(null);

        try {
            window.localStorage.setItem(CHART_OPEN_KEY, next ? '1' : '0');
        } catch {
            // The choice is only a convenience; it just isn't remembered.
        }
    }

    useEffect(() => {
        const observer = new ResizeObserver(([entry]) => setWidth(Math.max(1, entry.contentRect.width)));
        observer.observe(plotRef.current);
        return () => observer.disconnect();
    }, []);
    const points = [...readings].reverse();
    const highest = Math.max(1, ...points.map((reading) => Number(reading.consumption)));
    const maximum = highest * 1.1;
    const gap = Math.min(points.length > 30 ? 3 : points.length > 14 ? 6 : 10, width / Math.max(1, points.length) / 3);
    const barWidth = (width - gap * (points.length - 1)) / Math.max(1, points.length);
    const labelEvery = Math.max(1, Math.ceil(points.length / Math.max(1, Math.floor(width / 58))));
    const y = (value) => 146 - Number(value) / maximum * 124;
    const active = readings.find((reading) => reading.id === activeId);

    return (
        <section className="rh-chart">
            <div className="rh-chart-heading"><h3>الاستهلاك الأسبوعي</h3><span>{formatNumber(points.length)} أسبوعًا، الأقدم على اليمين</span>{open && <span className="rh-average"><i />المتوسط</span>}<button type="button" className="rh-chart-toggle" onClick={toggle} aria-expanded={open} aria-controls={plotId}>{open ? 'طي الرسم' : 'عرض الرسم'}<Icon name="chevron-down" className={`h-4 w-4 transition ${open ? 'rotate-180' : ''}`} /></button></div>
            <div id={plotId} className="rh-plot" ref={plotRef} style={open ? undefined : { display: 'none' }} onPointerLeave={() => setActiveId(null)}>
                {points.length > 0 ? <svg viewBox={`0 0 ${width} 170`} preserveAspectRatio="none" role="group" aria-label="الاستهلاك الأسبوعي بالكيلو">
                    <defs><pattern id={hatchId} width="6" height="6" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><rect width="6" height="6" className="rh-hatch-background" /><path d="M0 0V6" className="rh-hatch-line" strokeWidth="2.2" /></pattern></defs>
                    <line x1="0" x2={width} y1="146" y2="146" className="rh-baseline" />
                    {points.map((reading, index) => {
                        const x = width - (index + 1) * barWidth - index * gap;
                        const height = Math.max(2, 146 - y(reading.consumption));
                        const latest = index === points.length - 1;
                        const description = `${formatDate(reading.weekStart)} – ${formatDate(reading.weekEnd)}: ${formatMoney(reading.consumption)} كيلو · ${money(reading.amountDue)} ₪ · ${reading.statusLabel}`;

                        return <g key={reading.id} tabIndex={0} role="img" aria-label={description} onFocus={() => setActiveId(reading.id)} onBlur={() => setActiveId(null)} onPointerEnter={() => setActiveId(reading.id)} className="rh-chart-point">
                            <title>{description}</title>
                            <rect x={x} y={146 - height} width={barWidth} height={height} rx={Math.min(4, barWidth / 3)} className={`rh-bar ${latest ? 'is-latest' : ''}`} style={reading.status === 'pending' ? { fill: `url(#${hatchId})` } : undefined} />
                            {(latest || Number(reading.consumption) === highest) && <text x={x + barWidth / 2} y={140 - height} textAnchor="middle" className="rh-chart-value">{formatMoney(reading.consumption)}</text>}
                            {(points.length - 1 - index) % labelEvery === 0 && <text x={x + barWidth / 2} y="164" textAnchor="middle" className="rh-chart-label">{shortDate(reading.weekEnd)}</text>}
                            <rect x={x - gap / 2} y="0" width={barWidth + gap} height="146" fill="transparent" />
                        </g>;
                    })}
                    <line x1="0" x2={width} y1={y(average)} y2={y(average)} className="rh-average-line" />
                </svg> : <p className="rh-chart-empty">لا توجد قراءات في هذه الفترة.</p>}
                {active && <div className="rh-tooltip" role="tooltip"><b>{formatMoney(active.consumption)} كيلو · {money(active.amountDue)} ₪</b>{shortDate(active.weekStart)} – {shortDate(active.weekEnd)} · {active.statusLabel}</div>}
            </div>
        </section>
    );
}

/**
 * The subscription's last reading — the one the latest week starts from — with a pencil that
 * opens it with − and + to correct it in place, as on the readings sheet (useLastReadingEdit).
 * Saving confirms this baseline separately; the current field is then entered by hand in the table.
 */
function LastReadingField({ subscription, lastReading }) {
    const savedValue = lastReading.isInitial ? lastReading.value : lastReading.nextPreviousReading ?? lastReading.current_reading;
    const editor = useLastReadingEdit({
        readingId: lastReading.id,
        savedValue,
        floor: lastReading.isInitial ? 0 : lastReading.previous_reading,
        previewUrl: lastReading.previewUrl,
        saveUrl: lastReading.saveUrl,
        weekStart: lastReading.isInitial ? lastReading.weekStart : undefined,
        isInitial: lastReading.isInitial ?? false,
    });

    return <>
        <LastReadingConfirmDialog editor={editor} subscriptionName={subscription.display_name} />
        <span className="rh-last-value">
            <span className="rh-last-number">{formatMoney(savedValue)}</span>
            <button type="button" className="rh-last-pencil" onClick={editor.open} aria-label="تعديل القراءة السابقة" title={lastReading.isInitial ? 'تعديل قراءة بداية الاشتراك' : `تعديل قراءة الأسبوع ${shortDate(lastReading.weekStart)} – ${shortDate(lastReading.weekEnd)}`}><Icon name="pencil" /></button>
        </span>
    </>;
}

/**
 * The latest week's line in the table, entered or corrected right where it is read, as on the
 * readings sheet: the last reading beside the new one, and what it comes to as it is typed.
 * Enter or leaving the field saves it, Escape puts the saved number back; correcting an
 * approved reading asks first. `reading` is null while the week's reading is still to be entered.
 * `editableLastReading`, when the actor may edit it, makes the last reading editable beside it.
 */
function LiveRow({ subscription, week, reading, maximum, average, editableLastReading = null }) {
    const savedValue = reading && !reading.requiresReentry ? String(reading.current_reading) : '';
    const [value, setValue] = useState(savedValue);
    const [error, setError] = useState(null);
    const [saving, setSaving] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const inputRef = useRef(null);

    useEffect(() => {
        setValue(savedValue);
        setError(null);
    }, [savedValue, reading?.previous_reading, !reading ? subscription.lastReading : null]);

    useEffect(() => {
        if (!reading) {
            inputRef.current?.focus();
        }
    }, [reading?.id]);

    const previous = reading ? reading.previous_reading : subscription.lastReading;
    const isDraft = value !== savedValue;
    const typed = value !== '' && !Number.isNaN(Number(value));
    const consumption = typed ? consumptionBetween(previous, value) : null;
    const negative = consumption !== null && consumption < 0;
    const unitPrice = reading?.unitPrice ?? subscription.kilowattPrice;
    const minimumPayment = reading?.minimumPayment ?? subscription.weeklyMinimumPayment;
    const charges = reading && !reading.requiresReentry && !isDraft
        ? { consumption: Number(reading.consumption), discountAmount: Number(reading.discountAmount), amountDue: Number(reading.amountDue), minimumApplies: reading.minimumApplied }
        : typed && !negative ? { consumption, ...weeklyCharges(consumption, unitPrice, minimumPayment, reading ? reading.discount : subscription.standingDiscount) } : null;
    const difference = charges && average > 0 ? Math.round((charges.consumption - average) / average * 100) : 0;

    // + and − (the buttons or the keys) move the reading by one kilo; from an empty field they start at the last reading.
    function step(direction) {
        setValue(steppedReading(value, previous, direction));
        setError(null);
        inputRef.current?.focus();
    }

    function save(confirmed = false) {
        if (!typed || !isDraft || saving || negative) {
            return;
        }

        if (reading?.status === 'approved' && !confirmed) {
            setConfirming(true);
            return;
        }

        const options = {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
            onSuccess: () => setError(null),
            onError: (errors) => setError(Object.values(errors)[0] ?? 'تعذّر حفظ القراءة.'),
        };

        if (reading) {
            router.put(`/meter-readings/${reading.id}`, { current_reading: value }, options);
        } else {
            router.post('/meter-readings', { subscription_id: subscription.id, week_start: week.value, current_reading: value }, options);
        }
    }

    return <tr className={`rh-live ${reading ? '' : 'is-new'}`}>
        <td className="rh-week"><b dir="ltr" title={week.label}>{shortDate(week.value)} ← {shortDate(week.end)}</b><span className="rh-note">{reading ? 'يمكن تعديل القراءة هنا' : 'قراءة جديدة — أدخلها هنا'}</span>{reading && <ReadingAdjustmentHistory reading={reading} />}</td>
        <td className={`rh-previous rh-number ${editableLastReading ? 'rh-last-cell' : ''}`}>{editableLastReading ? <LastReadingField subscription={subscription} lastReading={editableLastReading} nextReading={reading} /> : formatMoney(previous)}</td>
        <td className="rh-current rh-live-current">
            <div className="rh-stepper" dir="ltr">
                <button type="button" onMouseDown={(event) => event.preventDefault()} onClick={() => step(-1)} disabled={saving || !typed && value !== '' || (typed && Number(value) <= Number(previous))} aria-label="إنقاص القراءة">−</button>
                <input ref={inputRef} type="number" inputMode="decimal" step="0.01" min={previous} dir="ltr" value={value} placeholder="أدخل القراءة" disabled={saving}
                    aria-label="القراءة الجديدة" aria-invalid={Boolean(error) || negative} title={error ?? (negative ? `لا يمكن أن تقل عن القراءة السابقة (${formatMoney(previous)})` : undefined)}
                    onChange={(event) => { setValue(event.target.value); setError(null); }}
                    onBlur={() => save()}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter') { event.preventDefault(); save(); }
                        if (event.key === 'Escape' && isDraft) { event.stopPropagation(); setValue(savedValue); }
                        // The + and − keys step the reading like the buttons, instead of typing a sign. The arrows are for
                        // moving around, so they never change the number (the field's own 0.01 stepping is switched off).
                        if (!event.ctrlKey && !event.metaKey && !event.altKey) {
                            if (event.key === '+') { event.preventDefault(); step(1); }
                            if (event.key === '-') { event.preventDefault(); step(-1); }
                        }
                        if (event.key === 'ArrowUp' || event.key === 'ArrowDown') { event.preventDefault(); }
                    }} />
                <button type="button" onMouseDown={(event) => event.preventDefault()} onClick={() => step(1)} disabled={saving} aria-label="زيادة القراءة">+</button>
            </div>
            {(error || negative) && <small className="rh-live-error" role="alert">{error ?? `أقل من السابقة (${formatMoney(previous)})`}</small>}
        </td>
        <td className="rh-consumption"><div><b>{charges ? formatMoney(charges.consumption) : '—'}</b>{charges && <><span className="rh-meter"><i style={{ width: `${Math.min(100, Math.max(0, charges.consumption) / Math.max(maximum, charges.consumption, 1) * 100)}%` }} /></span><span className={`rh-delta ${Math.abs(difference) < 10 ? '' : difference > 0 ? 'rh-up' : 'rh-down'}`} dir="ltr">{Math.abs(difference) < 10 ? '≈' : `${difference > 0 ? '+' : ''}${difference}٪`}</span></>}</div></td>
        <td className="rh-price rh-number">{reading ? <ReadingPricingField reading={reading} field="unit_price" /> : <span dir="ltr">{money(unitPrice)}</span>}</td>
        <td className="rh-minimum rh-number">{reading ? <ReadingPricingField reading={reading} field="minimum_payment" /> : <span dir="ltr">{money(minimumPayment)}</span>}</td>
        <td className="rh-discount">{charges && charges.discountAmount > 0 ? <span className="rh-discount-value" dir="ltr">−{money(charges.discountAmount)}</span> : <span className="rh-muted">—</span>}</td>
        <td className="rh-due">{charges ? <b dir="ltr">{money(charges.amountDue)} <em>₪</em></b> : <b>—</b>}{charges?.minimumApplies && <small>الحد الأدنى</small>}</td>
        <td className="rh-status">
            {saving ? <span className="rh-saving">جارٍ الحفظ...</span> : reading ? <ReadingHistoryStatus subscription={subscription} reading={reading} disabled={isDraft || Boolean(error) || negative} /> : <span className="rh-pill is-missing"><i />لم تُدخل</span>}
            <ConfirmDialog show={confirming} onConfirm={() => { setConfirming(false); save(true); }} onCancel={() => { setConfirming(false); setValue(savedValue); }} title="تعديل قراءة معتمدة؟" message={`قراءة ${subscription.display_name} معتمدة. تعديلها يعيدها إلى قيد المراجعة ويزيل مبلغها من المعاملات المالية للمشترك حتى يُعاد اعتمادها.`} confirmLabel="نعم، عدّل" cancelLabel="تراجع عن التعديل" icon="alert" />
        </td>
        <td className="rh-recorder">{reading ? <>{reading.recordedByName ?? '—'}<small><HistoryIcon name={reading.recordedSource === 'app' ? 'app' : 'web'} /><span>{reading.recordedSource === 'app' ? 'التطبيق' : 'الموقع'}</span> · <span dir="ltr">{reading.recordedAt}</span></small></> : '—'}</td>
    </tr>;
}

/** The selected subscription's recorded history, presented using the supplied design. */
export default function ReadingHistoryModal({ subscription, onClose, readingWeekOptions = [] }) {
    const titleId = useId();
    const [period, setPeriod] = useState('12');
    const [status, setStatus] = useState('all');
    const allReadings = readingsInPeriod(subscription?.meterReadings ?? [], 'all');
    const periodReadings = readingsInPeriod(allReadings, period);
    const visibleReadings = periodReadings.filter((reading) => status === 'all' || reading.status === status);
    const totals = readingTotals(visibleReadings);
    const average = periodReadings.length ? readingTotals(periodReadings).consumption / periodReadings.length : 0;
    const busiest = periodReadings.reduce((highest, reading) => !highest || Number(reading.consumption) > Number(highest.consumption) ? reading : highest, null);
    const latest = allReadings[0];
    const previousWeeks = allReadings.slice(1, 5);
    const previousAverage = previousWeeks.length ? readingTotals(previousWeeks).consumption / previousWeeks.length : 0;
    const change = previousAverage > 0 ? Math.round((Number(latest.consumption) - previousAverage) / previousAverage * 100) : null;
    const maximum = Math.max(1, ...visibleReadings.map((reading) => Number(reading.consumption)));

    // The latest ended week, which readings are entered for: this week's reading if it is recorded already.
    const entryWeek = readingWeekOptions[0] ?? null;
    const entryReading = entryWeek && latest?.weekStart === entryWeek.value ? latest : null;
    // The new week's line is entered in the table (the line itself, once it is recorded and may still be corrected).
    const canEnter = Boolean(subscription?.canRecordReading && entryWeek);
    const newLine = canEnter && !entryReading && status === 'all';
    const editableId = entryReading?.canUpdate ? entryReading.id : null;
    // The last reading the actor may edit: beside the latest week's line while that line is entered here,
    // otherwise on its own line, in place of its reading.
    const lastReadingIndex = allReadings.findIndex((reading) => reading.id === subscription?.editableLastReadingId);
    const editableLastReading = lastReadingIndex >= 0 ? allReadings[lastReadingIndex] : null;
    const initialReading = subscription?.initialReading?.canEdit ? subscription.initialReading : null;
    const readingAfterLast = lastReadingIndex > 0 ? allReadings[lastReadingIndex - 1] : null;
    const liveRowShown = newLine || (editableId !== null && visibleReadings.some((reading) => reading.id === editableId));

    return (
        <Modal show={Boolean(subscription)} onClose={onClose} maxWidth="full" panelClassName="!my-0">
            {subscription && <div data-excel-source="custom" className="reading-history" role="dialog" aria-modal="true" aria-labelledby={titleId} dir="rtl">
                <header className="rh-header">
                    <img className="rh-print-logo" src="/images/logo-af.webp" alt="AF PowerCollect" />
                    <span className="rh-gauge"><Icon name="gauge" className="h-[23px] w-[23px]" /></span>
                    <div className="rh-identity">
                        <h2 id={titleId}>سجل قراءات {subscription.display_name}</h2>
                        <div className="rh-meta">
                            <span>حساب <b dir="ltr">{subscription.account_number}</b></span>
                            {subscription.meterBoxNumber && <><span>·</span><span>الطبلون <b>{subscription.meterBoxNumber}</b>{subscription.subAreaName && ` · ${subscription.subAreaName}`}</span></>}
                            <span>·</span><span>{subscription.branchName}</span><span>·</span><span>{subscription.tariffCategoryLabel} · {money(subscription.kilowattPrice)} ₪ للكيلو</span>
                            <span className={`rh-pill ${subscription.status === 'active' ? 'is-approved' : 'is-pending'}`}><i />{subscription.statusLabel}</span>
                        </div>
                    </div>
                    <div className="rh-actions">
                        <TableExportActions title={`سجل قراءات ${subscription.display_name}`} onDark disabled={!visibleReadings.length} onPrint={() => window.print()} excelProps={{ storageKey: 'reading-history', templateKind: 'reading-history', loadParts: async () => readingHistoryExcelParts(visibleReadings), meta: { lines: [`رقم الحساب: ${subscription.account_number}`, subscription.branchName].filter(Boolean), criteria: [['الفترة', PERIODS.find(([value]) => value === period)[1]], ['الحالة', STATUSES.find(([value]) => value === status)[1]]] } }} />
                        <button type="button" className="rh-close" onClick={onClose} aria-label="إغلاق"><Icon name="close" /></button>
                    </div>
                </header>
                <div className="rh-body">
                    <div className="rh-figures">
                        <Figure label="عدد القراءات" value={formatNumber(periodReadings.length)}>{periodReadings.length ? `من أسبوع ${shortDate(periodReadings.at(-1).weekStart)}` : 'لا توجد قراءات بعد'}</Figure>
                        <Figure label="متوسط الاستهلاك الأسبوعي" value={formatMoney(average)} unit="كيلو">{periodReadings.length ? `≈ ${money(average * Number(subscription.kilowattPrice))} ₪ في الأسبوع` : '—'}</Figure>
                        <Figure label="أعلى استهلاك" value={formatMoney(busiest?.consumption ?? 0)} unit="كيلو">{busiest && `أسبوع ${shortDate(busiest.weekStart)} – ${shortDate(busiest.weekEnd)}`}</Figure>
                        <Figure label="القراءة السابقة" value={formatMoney(subscription.lastReading)}>{latest ? <>أسبوع {shortDate(latest.weekEnd)}{change !== null && <> · <span className={change > 0 ? 'rh-up' : 'rh-down'}>{change > 0 ? '▲' : change < 0 ? '▼' : '≈'} {Math.abs(change)}٪</span> عن متوسط {formatNumber(previousWeeks.length)} أسابيع</>}</> : 'قراءة العداد عند الاشتراك'}</Figure>
                    </div>
                    <HistoryChart readings={periodReadings} average={average} />
                    <div className="rh-toolbar">
                        <div className="rh-segments" role="group" aria-label="الفترة">{PERIODS.map(([value, label]) => <button type="button" key={value} aria-pressed={period === value} onClick={() => setPeriod(value)}>{label}</button>)}</div>
                        <div className="rh-segments" role="group" aria-label="الحالة">{STATUSES.map(([value, label]) => <button type="button" key={value} aria-pressed={status === value} onClick={() => setStatus(value)}>{label}<span>{formatNumber(value === 'all' ? periodReadings.length : periodReadings.filter((reading) => reading.status === value).length)}</span></button>)}</div>
                        <span className="rh-count" aria-live="polite">{formatNumber(visibleReadings.length)} قراءة معروضة</span>
                    </div>
                    <p className="rh-print-context">
                        الفترة: {PERIODS.find(([value]) => value === period)[1]} · الحالة: {STATUSES.find(([value]) => value === status)[1]}
                        {visibleReadings.length > 0 && <> · {visibleReadings.at(-1).weekStart} – {visibleReadings[0].weekEnd}</>}
                    </p>
                    {initialReading && !liveRowShown && !entryReading && <div className="mb-4 flex flex-wrap items-center gap-3 rounded-xl border border-gray-200 bg-gray-50 p-3 text-sm"><span>القراءة السابقة لآخر أسبوع:</span><span className="px-8"><LastReadingField subscription={subscription} lastReading={initialReading} /></span></div>}
                    <div className="rh-table-wrap">
                        <table className="rh-table">
                            <thead><tr><th scope="col">الأسبوع</th><th scope="col" className="rh-previous">السابقة</th><th scope="col" className="rh-current">الحالية</th><th scope="col">الاستهلاك</th><th scope="col" className="rh-price">سعر الكيلو</th><th scope="col" className="rh-minimum">الحد الأدنى</th><th scope="col" className="rh-discount">الخصم</th><th scope="col">المستحق</th><th scope="col">الحالة</th><th scope="col" className="rh-recorder">سجّلها</th></tr></thead>
                            <tbody>{newLine && <LiveRow subscription={subscription} week={entryWeek} reading={null} maximum={maximum} average={average} editableLastReading={editableLastReading ?? initialReading} />}{groupReadingsByMonth(visibleReadings).map((group) => <Fragment key={group.month}>
                                <tr className="rh-month"><th scope="rowgroup" colSpan={10}><b>{MONTH.format(new Date(`${group.month}-01T00:00:00Z`))}</b>{formatNumber(group.readings.length)} قراءة · <span>{formatMoney(group.totals.consumption)}</span> كيلو · <span>{money(group.totals.due)}</span> ₪</th></tr>
                                {group.readings.map((reading) => {
                                    if (reading.id === editableId) {
                                        return <LiveRow key={reading.id} subscription={subscription} week={entryWeek} reading={reading} maximum={maximum} average={average} editableLastReading={readingAfterLast?.id === reading.id ? editableLastReading : initialReading} />;
                                    }

                                    const difference = average > 0 ? Math.round((Number(reading.consumption) - average) / average * 100) : 0;
                                    return <tr key={reading.id}>
                                        <td className="rh-week"><b dir="ltr" title={`${formatDate(reading.weekStart)} – ${formatDate(reading.weekEnd)}`}>{shortDate(reading.weekStart)} ← {shortDate(reading.weekEnd)}</b>{reading.notes && <span className="rh-note">{reading.notes}</span>}<ReadingAdjustmentHistory reading={reading} /></td>
                                        <td className={`rh-previous rh-number ${reading.id === entryReading?.id && initialReading ? 'rh-last-cell' : ''}`}>{reading.id === entryReading?.id && initialReading ? <LastReadingField subscription={subscription} lastReading={initialReading} /> : formatMoney(reading.previous_reading)}</td><td className={`rh-current rh-number ${!liveRowShown && reading.id === editableLastReading?.id ? 'rh-last-cell' : ''}`}>{!liveRowShown && reading.id === editableLastReading?.id ? <LastReadingField subscription={subscription} lastReading={reading} nextReading={readingAfterLast} /> : reading.requiresReentry ? '—' : formatMoney(reading.current_reading)}</td>
                                        <td className="rh-consumption"><div><b>{formatMoney(reading.consumption)}</b><span className="rh-meter"><i style={{ width: `${Number(reading.consumption) / maximum * 100}%` }} /></span><span className={`rh-delta ${Math.abs(difference) < 10 ? '' : difference > 0 ? 'rh-up' : 'rh-down'}`} dir="ltr" title="الفرق عن متوسط الفترة">{Math.abs(difference) < 10 ? '≈' : `${difference > 0 ? '+' : ''}${difference}٪`}</span></div></td>
                                        <td className="rh-price rh-number"><ReadingPricingField reading={reading} field="unit_price" /></td>
                                        <td className="rh-minimum rh-number"><ReadingPricingField reading={reading} field="minimum_payment" /></td>
                                        <td className="rh-discount">{Number(reading.discountAmount) > 0 ? <span className="rh-discount-value" dir="ltr">−{money(reading.discountAmount)}</span> : <span className="rh-muted">—</span>}</td>
                                        <td className="rh-due"><b dir="ltr">{money(reading.amountDue)} <em>₪</em></b>{reading.minimumApplied && <small>الحد الأدنى</small>}</td>
                                        <td className="rh-status"><ReadingHistoryStatus subscription={subscription} reading={reading} /></td>
                                        <td className="rh-recorder">{reading.recordedByName ?? '—'}<small><HistoryIcon name={reading.recordedSource === 'app' ? 'app' : 'web'} /><span>{reading.recordedSource === 'app' ? 'التطبيق' : 'الموقع'}</span> · <span dir="ltr">{reading.recordedAt}</span></small></td>
                                    </tr>;
                                })}
                            </Fragment>)}</tbody>
                            {visibleReadings.length > 0 && <tfoot><tr><td>مجموع الفترة</td><td className="rh-previous rh-hide-mobile" /><td className="rh-current rh-hide-mobile" /><td><b className="rh-number">{formatMoney(totals.consumption)}</b> كيلو</td><td className="rh-price rh-hide-mobile" /><td className="rh-minimum rh-hide-mobile" /><td className="rh-discount"><span className="rh-discount-value" dir="ltr">{totals.discount > 0 ? `−${money(totals.discount)}` : '—'}</span></td><td className="rh-due"><b dir="ltr">{money(totals.due)} <em>₪</em></b></td><td className="rh-hide-mobile" /><td className="rh-recorder rh-hide-mobile" /></tr></tfoot>}
                        </table>
                        {!visibleReadings.length && !newLine && <div className="rh-empty"><b>لا توجد قراءات</b>{allReadings.length ? 'لا توجد قراءات بهذه الحالة في الفترة المختارة.' : 'لا توجد قراءات لهذا المشترك بعد.'}</div>}
                    </div>
                </div>
            </div>}
        </Modal>
    );
}
