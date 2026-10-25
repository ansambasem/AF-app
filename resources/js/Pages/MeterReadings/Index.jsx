import { useEffect, useState, useRef } from 'react';
import { Head, router } from '@inertiajs/react';
import { notify } from '@/lib/notify';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ConfirmDialog from '@/Components/ConfirmDialog';
import LastReadingConfirmDialog from '@/Components/LastReadingConfirmDialog';
import Icon from '@/Components/Icon';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import DataTableToolbar from '@/Components/DataTable/DataTableToolbar';
import DataTableFilterMenu from '@/Components/DataTable/DataTableFilterMenu';
import SortableTh from '@/Components/DataTable/SortableTh';
import Pagination from '@/Components/DataTable/Pagination';
import SearchableSelect from '@/Components/SearchableSelect';
import { useDataTable } from '@/hooks/useDataTable';
import { useLastReadingEdit } from '@/hooks/useLastReadingEdit';
import { printFieldsProps, printRowProps } from '@/lib/print';
import { formatCurrency } from '@/lib/currency';
import { formatMonthName } from '@/lib/format';
import { consumptionBetween, steppedReading, weeklyCharges } from '@/lib/readings';
import { activeReadingStatus, readingStatusFilters, readingWeekParams } from '@/lib/readingSheet';
import ReadingContextBar from './ReadingContextBar';
import ReadingApprovalDialog from './ReadingApprovalDialog';
import PrintButton from '@/Components/PrintButton';
import './ReadingEntry.css';
import { WEEK_DAYS, formatWeekDay } from '@/lib/weekDays';
import InfoTip from '@/Components/InfoTip';

/**
 * What each sort is by, and how its two directions read: names go أ – ي or
 * ي – أ, kilowatts and amounts from the least or the most, numbers from the
 * smallest or the largest.
 */
const DIRECTION_LABELS = {
    text: { asc: 'أ – ي', desc: 'ي – أ' },
    amount: { asc: 'الأقل أولًا', desc: 'الأكثر أولًا' },
    number: { asc: 'الأصغر أولًا', desc: 'الأكبر أولًا' },
};
const SORT_OPTIONS = [
    { value: 'full_name', label: 'الاسم', type: 'text' },
    { value: 'meter_box', label: 'الطبلون (الاسم ثم الرقم)', type: 'text' },
    { value: 'meter_box_number', label: 'رقم الطبلون', type: 'number' },
    { value: 'last_reading', label: 'القراءة السابقة', type: 'amount' },
    { value: 'current_reading', label: 'القراءة الحالية', type: 'amount' },
    { value: 'consumption', label: 'الفرق (كيلو)', type: 'amount' },
    { value: 'amount_due', label: 'المطلوب دفعه', type: 'amount' },
    { value: 'account_number', label: 'رقم الاشتراك', type: 'number' },
];

/** The details printing can show as columns of their own (see printFieldsProps). */
const PRINT_FIELDS = [
    { key: 'meterBoxName', label: 'اسم الطبلون' },
    { key: 'meterBoxNumber', label: 'رقم الطبلون' },
    { key: 'fullName', label: 'اسم المشترك' },
    { key: 'phone', label: 'رقم الجوال' },
    { key: 'accountNumber', label: 'رقم الاشتراك' },
    { key: 'subAreaName', label: 'منطقة 2' },
];

const STATUS_TABS = [
    { value: 'all', label: 'الكل', count: 'total' },
    { value: 'missing', label: 'لم تُدخل', count: 'missing' },
    { value: 'pending', label: 'بانتظار الاعتماد', count: 'pending' },
    { value: 'approved', label: 'معتمدة', count: 'approved' },
];
const shortDate = (date) => date.slice(5).split('-').reverse().join('/');
const fullDate = (date) => date.split('-').reverse().join('/');

/**
 * The week's cost for a typed reading: consumption × kilowatt price, less
 * the row's standing discount, but never less than the minimum payment.
 */
function calculateCharges(currentReading, row) {
    if (currentReading === '' || Number.isNaN(Number(currentReading))) {
        return null;
    }

    const consumption = consumptionBetween(row.previousReading, currentReading);

    return { consumption, ...weeklyCharges(consumption, row.unitPrice, row.minimumPayment, row.discount) };
}

/**
 * Moves from a reading field to the one `step` rows away (1 down, -1 up),
 * skipping the ones that can't be edited. Down past the last field leaves
 * the field; up past the first stays where it is.
 */
function focusReadingInput(currentInput, step = 1) {
    const inputs = [...document.querySelectorAll('[data-reading-input]:not([disabled])')];
    const target = inputs[inputs.indexOf(currentInput) + step];

    if (target) {
        target.focus();
    } else if (step > 0) {
        currentInput.blur();
    }
}

/**
 * The reading the week starts from. When the actor may edit it (the last
 * week's reading, while no later week is entered), its pencil opens it in
 * place with − and + like the week's own reading (useLastReadingEdit). The
 * week's own reading is entered separately after confirming the baseline.
 */
function LastReadingCell({ row }) {
    const lastReading = row.lastReading;
    const editor = useLastReadingEdit({
        readingId: lastReading?.id,
        savedValue: row.previousReading,
        floor: lastReading?.previousReading ?? 0,
    });

    return (
        <td className="re-last re-number">
            <LastReadingConfirmDialog editor={editor} subscriptionName={row.fullName} />
                <span className="re-last-value">
                    <span className="re-last-number">{row.previousReading}</span>
                    {lastReading?.canEdit && (
                        <button
                            type="button"
                            className="re-last-pencil"
                            onClick={editor.open}
                            aria-label={`تعديل القراءة السابقة لـ ${row.fullName}`}
                            title={`تعديل قراءة الأسبوع ${shortDate(lastReading.weekStart)} – ${lastReading.weekEnd ? shortDate(lastReading.weekEnd) : ''}`}
                        >
                            <Icon name="pencil" />
                        </button>
                    )}
                </span>
        </td>
    );
}

/**
 * One subscription's line for the week. `approvable` (the actor may approve
 * readings) adds a tick box, enabled when this row's reading can be approved.
 */
export function SheetRow({ row, week, approvable, showRecorder, selected, onToggleSelected, onApprove, approving = false }) {
    const savedValue = row.reading && !row.reading.requiresReentry ? String(row.reading.currentReading) : '';
    const [value, setValue] = useState(savedValue);
    const [error, setError] = useState(null);
    const [saving, setSaving] = useState(false);
    const inputRef = useRef(null);
    // Set when the arrows or Enter move on from the field: skipping a subscription that way is on purpose.
    const skippedByKey = useRef(false);
    // Set when the field was clicked into and left empty; shown in orange until it is entered or clicked again.
    const [missed, setMissed] = useState(false);
    // Changing an approved reading sends it back for approval, so it waits on "are you sure?".
    const [confirmingApprovedEdit, setConfirmingApprovedEdit] = useState(false);

    // Pick up the saved value whenever the server sends a fresh row.
    useEffect(() => {
        setValue(savedValue);
    }, [savedValue, row.previousReading]);

    // A reading the server refused is entered again: the field gets the focus back, its number selected,
    // unless the user has already moved on to another field.
    useEffect(() => {
        if (error && !saving && (!document.activeElement || document.activeElement === document.body)) {
            inputRef.current?.focus();
            inputRef.current?.select();
        }
    }, [error, saving]);

    const isDraft = value !== savedValue;
    const charges = !isDraft && row.reading && !row.reading.requiresReentry ? { ...row.reading, minimumApplies: Number(row.reading.readingFee) < Number(row.minimumPayment) && !row.discount } : calculateCharges(value, row);
    const belowMinimum = charges?.minimumApplies;
    const isSaved = Boolean(row.reading) && !row.reading.requiresReentry && !isDraft && !error;
    // Approved, but its bill was cancelled from the subscription's statement: it is not charged to them.
    const billCancelled = row.reading?.status === 'approved' && row.reading.billed === false;
    // What the amount due notes, on one line under it so the row keeps its height.
    const dueNotes = [
        billCancelled && !isDraft && { text: 'لم تُحمَّل على المشترك' },
        charges?.discountAmount > 0 && charges.consumption >= 0 && { text: `بعد خصم ${formatCurrency(charges.discountAmount)}`, className: 'text-emerald-700 dark:text-emerald-400' },
        belowMinimum && charges.consumption >= 0 && { text: 'الحد الأدنى' },
    ].filter(Boolean);
    // What hovering the field says: why it was refused, that it was left empty, or what was saved.
    const hoverText = error
        ?? (missed ? 'لم تُدخل قراءة هذا المشترك' : null)
        ?? (isSaved
            ? `تم حفظ القراءة (${row.reading.status === 'pending' ? 'بانتظار الاعتماد' : (row.reading.statusLabel ?? row.reading.status)}) · الاستهلاك ${charges?.consumption ?? '—'} · المستحق ${charges ? formatCurrency(charges.amountDue) : '—'}`
            : null)
        ?? (row.hasLaterWeek ? 'توجد قراءة لأسبوع لاحق، فلا تُعدَّل هذه القراءة' : null);

    // + and − (the buttons or the keys) move the reading by one kilo; from an empty field they start at the last reading.
    function step(direction) {
        setValue(steppedReading(value, row.previousReading, direction));
        setMissed(false);
        inputRef.current?.focus();
    }

    function save({ confirmed = false } = {}) {
        if (value === '' || value === savedValue || saving) {
            return;
        }

        if (row.reading?.status === 'approved' && !confirmed) {
            setConfirmingApprovedEdit(true);
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

        if (row.reading) {
            router.put(`/meter-readings/${row.reading.id}`, { current_reading: value }, options);
        } else {
            router.post('/meter-readings', { subscription_id: row.id, week_start: week, current_reading: value }, options);
        }
    }

    return (
        <tr
            className={`re-row ${selected ? 'is-selected' : ''} ${error ? 'has-error' : ''} ${isDraft ? 'is-draft' : ''} ${row.reading?.unusual ? 'is-unusual' : ''}`}
            {...printRowProps(Object.fromEntries(PRINT_FIELDS.map((field) => [field.key, row[field.key]])))}
        >
            <td className="re-sub">
                <div className="flex items-center gap-3">
                    {approvable && (
                        <input
                            type="checkbox"
                            checked={selected}
                            onChange={onToggleSelected}
                            disabled={!row.canApprove}
                            aria-label={`تحديد قراءة ${row.fullName} للاعتماد`}
                            className={row.canApprove ? '' : 'invisible'}
                        />
                    )}
                    <div className="min-w-0">
                        <div className="re-sub-name">
                            <b>{row.fullName}</b>
                            {row.discount && (
                                <span className="re-pill discount" title={`خصم القراءات الأسبوعية: ${row.discount.terms}${row.discount.segment ? ` · ${row.discount.segment}` : ''}`}>
                                    خصم
                                </span>
                            )}
                        </div>
                        <p className="re-line text-xs text-gray-500">
                            {row.accountNumber ? `حساب ${row.accountNumber}` : '—'}
                        </p>
                    </div>
                </div>
            </td>
            <LastReadingCell row={row} />
            <td className="re-input-cell">
                <div className="re-input" dir="ltr"><button type="button" tabIndex={-1} className="re-step" aria-label={`إنقاص قراءة ${row.fullName}`} onMouseDown={(e) => e.preventDefault()} onClick={() => step(-1)} disabled={!row.canEdit || saving || (value !== '' && Number(value) <= Number(row.previousReading))}>−</button><input
                    type="number"
                    inputMode="decimal"
                    step="0.01"
                    min={row.previousReading}
                    dir="ltr"
                    data-reading-input
                    ref={inputRef}
                    aria-label={`القراءة الحالية لـ ${row.fullName}`}
                    aria-invalid={Boolean(error)}
                    // The reason shows as a flash message when the save fails, and on hover here: the row never grows.
                    title={hoverText ?? undefined}
                    disabled={!row.canEdit || saving}
                    value={value}
                    placeholder={row.canEdit ? 'أدخل القراءة' : row.hasLaterWeek ? 'لها أسبوع لاحق' : '—'}
                    onInput={(e) => {
                        setValue(e.target.value);
                        setMissed(false);
                    }}
                    onFocus={(e) => {
                        skippedByKey.current = false;
                        setMissed(false);
                        e.target.select();
                    }}
                    onBlur={() => {
                        save();

                        // Clicked into and left with nothing entered: a flash message, so the table's rows stay as they are.
                        if (value === '' && savedValue === '' && !skippedByKey.current) {
                            setMissed(true);
                            notify(`لم تُدخل قراءة ${row.fullName}، عُد إلى حقلها وأدخلها.`);
                        }

                        skippedByKey.current = false;
                    }}
                    onKeyDown={(e) => {
                        // The + and − keys step the reading like the buttons, instead of typing a sign.
                        if ((e.key === '+' || e.key === '-') && !e.ctrlKey && !e.metaKey && !e.altKey) {
                            e.preventDefault();
                            step(e.key === '+' ? 1 : -1);
                        }

                        // Enter and the down arrow go to the next subscription, the up arrow to the previous one.
                        if (e.key === 'Enter' || e.key === 'ArrowDown') {
                            e.preventDefault();
                            skippedByKey.current = true;
                            focusReadingInput(e.currentTarget, 1);
                        } else if (e.key === 'ArrowUp') {
                            e.preventDefault();
                            skippedByKey.current = true;
                            focusReadingInput(e.currentTarget, -1);
                        }
                    }}
                    className={error ? 'has-error' : missed ? 'is-missed' : isSaved ? 'is-saved' : ''}
                /><button type="button" tabIndex={-1} className="re-step" aria-label={`زيادة قراءة ${row.fullName}`} onMouseDown={(e) => e.preventDefault()} onClick={() => step(1)} disabled={!row.canEdit || saving}>+</button></div>
            </td>
            <td className={`re-diff re-number ${charges && charges.consumption < 0 ? 'text-red-600' : 'text-brand-700'}`}>
                {charges ? charges.consumption : '—'}
            </td>
            <td className="re-rate re-number">{formatCurrency(row.unitPrice)}</td>
            <td className={`re-fee re-number ${belowMinimum ? 'text-gray-400 line-through' : 'text-gray-900'}`}>
                {charges ? formatCurrency(charges.readingFee) : '—'}
            </td>
            <td className={`re-min re-number ${belowMinimum ? 'font-semibold text-gray-900' : 'text-gray-600'}`}>
                {formatCurrency(row.minimumPayment)}
            </td>
            <td className={`re-due re-number ${billCancelled && !isDraft ? 'text-gray-400' : ''}`}>
                {charges && charges.consumption >= 0 ? <span className={billCancelled && !isDraft ? 'line-through' : ''}>{formatCurrency(charges.amountDue)}</span> : '—'}
                {dueNotes.length > 0 && (
                    <p className="re-line text-xs font-normal text-gray-500" title={dueNotes.map((note) => note.text).join(' · ')}>
                        {dueNotes.map((note, index) => (
                            <span key={note.text} className={note.className}>
                                {index > 0 && ' · '}
                                {note.text}
                            </span>
                        ))}
                    </p>
                )}
            </td>
            <td className="re-status">
                <ConfirmDialog
                    show={confirmingApprovedEdit}
                    onConfirm={() => {
                        setConfirmingApprovedEdit(false);
                        save({ confirmed: true });
                    }}
                    onCancel={() => {
                        setConfirmingApprovedEdit(false);
                        setValue(savedValue);
                    }}
                    title="تعديل قراءة معتمدة؟"
                    message={`قراءة ${row.fullName} معتمدة. تعديلها يعيدها إلى قيد المراجعة ويزيل مبلغها من المعاملات المالية للمشترك حتى يُعاد اعتمادها.`}
                    confirmLabel="نعم، عدّل"
                    cancelLabel="تراجع عن التعديل"
                    icon="alert"
                />
                {saving ? (
                    <span className="text-xs text-gray-500">جارٍ الحفظ...</span>
                ) : row.reading ? (
                    <div className="re-pills">
                        {approvable && row.canApprove && row.reading.status === 'pending' && !row.reading.requiresReentry ? <button type="button" className="re-pill pending re-pill-action" disabled={!isSaved || approving} onClick={() => onApprove(row.reading.id)} title={isSaved ? 'اضغط لمراجعة واعتماد هذه القراءة فقط' : 'احفظ تعديل القراءة أولًا'} aria-label={`اعتماد قراءة ${row.fullName}`}><i />بانتظار الاعتماد<Icon name="check" className="h-3.5 w-3.5" /></button> : <span className={`re-pill ${row.reading.status}`}><i />{row.reading.requiresReentry ? 'بانتظار إدخال الحالية' : row.reading.status === 'pending' ? 'بانتظار الاعتماد' : row.reading.statusLabel}</span>}
                        {billCancelled && !isDraft && (
                            <span className="re-pill unbilled" title="أُلغيت فاتورة هذه القراءة من كشف حساب المشترك، فلا تُحمَّل عليه.">
                                <i />الفاتورة ملغاة
                            </span>
                        )}
                        {row.reading.unusual && (
                            <span
                                className="re-pill unusual"
                                title={`استهلاك ${row.reading.consumption} كيلو، والمعتاد لهذا المشترك ${row.reading.unusual.usual} كيلو في الأسبوع. تأكد من القراءة قبل اعتمادها.`}
                            >
                                <i />غير معتادة
                            </span>
                        )}
                    </div>
                ) : (
                    <span className="re-pill missing"><i />لم تُدخل</span>
                )}
            </td>
            {showRecorder && <td className="re-by">
                {row.reading ? <>
                    <span className="re-line">
                        <b>{row.reading.recordedByName || '—'}</b>
                        {row.reading.approvedByName && (
                            <span className="re-approved-by" title={`اعتمدها ${row.reading.approvedByName} · ${row.reading.approvedAt}`}>
                                · اعتمدها {row.reading.approvedByName}
                            </span>
                        )}
                    </span>
                    <small><Icon name="clock" />{row.reading.recordedAt} · {row.reading.recordedSource === 'app' ? 'التطبيق' : 'الموقع'}</small>
                </> : <span>—</span>}
            </td>}
        </tr>
    );
}

function EntryWindowNotice({ entryWindow, canRecord, canApprove, weekIsViewOnly, onShowLatestWeek }) {
    if (entryWindow.appliesToActor && !entryWindow.isOpen) {
        const days = WEEK_DAYS.filter((day) => entryWindow.openDays.includes(day.value)).map((day) => day.label);
        const openingDay = entryWindow.nextOpensOn ? formatWeekDay(entryWindow.nextOpensOn) : days.join(' و');

        return (
            <div role="status" className="mb-4 rounded-xl border border-amber-500/25 bg-amber-500/10 p-4 text-sm text-amber-800 dark:text-amber-300">
                <p className="font-semibold">إدخال القراءات الجديدة مغلق حاليًا.</p>
                <p className="mt-1">
                    {openingDay ? `سيُفتح الإدخال يوم ${openingDay} من ${entryWindow.opensAt} إلى ${entryWindow.closesAt} بتوقيت الشركة.` : 'سيُفتح عندما يفتحه المدير.'} حتى ذلك الحين يمكنك تعديل القراءات المُدخلة
                    للأسبوع الأخير فقط.
                </p>
            </div>
        );
    }

    if (!canRecord) {
        // Approvers have their own bar below; for everyone else the sheet is read-only.
        return canApprove ? null : <p className="mb-4 text-sm text-gray-500">يمكنك عرض القراءات فقط.</p>;
    }

    if (weekIsViewOnly) {
        return (
            <div
                role="status"
                className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm"
            >
                <div>
                    <p className="font-semibold text-gray-900">هذا أسبوع سابق — قراءاته للعرض فقط.</p>
                    <p className="mt-1 text-gray-500">يمكن إدخال القراءات وتعديلها للأسبوع الأخير فقط.</p>
                </div>
                <button type="button" onClick={onShowLatestWeek} className="text-sm font-semibold text-brand-600 hover:underline">
                    الانتقال إلى الأسبوع الأخير
                </button>
            </div>
        );
    }

    if (entryWindow.appliesToActor) {
        return <p className="mb-4 text-sm font-medium text-emerald-700 dark:text-emerald-400">إدخال القراءات مفتوح الآن.</p>;
    }

    return null;
}

export default function Index({
    rows,
    week,
    weekEnd,
    weekOptions,
    summary,
    statusSummary,
    canRecord,
    canApprove,
    seesWhoRecorded,
    pendingApproval,
    weekIsViewOnly,
    entryWindow,
    filters,
    filterOptions,
}) {
    const weekParams = readingWeekParams(week, weekOptions[0]?.value);
    const { search, setSearch, sort, sortBy, setPerPage, filterValues, setFilter, setFilters, clearFilters } = useDataTable('/meter-readings', filters, weekParams, {
        only: ['rows', 'filters', 'filterOptions', 'week', 'weekEnd', 'weekOptions', 'summary', 'statusSummary', 'pendingApproval', 'can', 'canRecord', 'canApprove', 'seesWhoRecorded', 'weekIsViewOnly', 'entryWindow'],
    });
    // The readings ticked for approval, and which approval is waiting on "are you sure?" ('selected' or 'all').
    const [selectedIds, setSelectedIds] = useState(() => new Set());
    const [confirming, setConfirming] = useState(null);
    const [approving, setApproving] = useState(false);
    const [singleApprovalId, setSingleApprovalId] = useState(null);
    const [showHint, setShowHint] = useState(true);
    // The end of the filters and the sheet: the pinned bar shows what the filters are set to while they are scrolled away.
    const filtersRef = useRef(null);
    const sheetRef = useRef(null);
    const activeStatus = activeReadingStatus(filters.filter ?? {});
    const weekIndex = weekOptions.findIndex((option) => option.value === week);
    const entered = statusSummary.pending + statusSummary.approved;
    const percentage = statusSummary.total ? Math.round(entered / statusSummary.total * 100) : 0;

    function changeStatus(status) {
        setSelectedIds(new Set());
        setFilters(readingStatusFilters(status));
    }

    // Ticks only apply to rows on screen; drop any that left (approved, another page or week).
    useEffect(() => {
        const approvableIds = new Set(rows.data.filter((row) => row.canApprove).map((row) => row.reading.id));
        setSelectedIds((current) => new Set([...current].filter((id) => approvableIds.has(id))));
    }, [rows.data]);

    const approvableRows = rows.data.filter((row) => row.canApprove);
    const selectedRows = approvableRows.filter((row) => selectedIds.has(row.reading.id));
    const singleApprovalRow = approvableRows.find((row) => row.reading.id === singleApprovalId);
    const selectedTotal = selectedRows.reduce((total, row) => total + Number(row.reading.amountDue), 0);
    // Readings far above the subscription's usual are approved only when ticked and confirmed, never with "approve all".
    const selectedUnusualCount = selectedRows.filter((row) => row.reading.unusual).length;
    const unusualCount = pendingApproval?.unusualCount ?? 0;
    const approvableAllCount = (pendingApproval?.count ?? 0) - unusualCount;
    const allOnPageSelected = approvableRows.length > 0 && selectedRows.length === approvableRows.length;
    const isFiltered = Boolean(search) || Object.values(filterValues).some(Boolean);

    function toggleSelected(readingId) {
        setSelectedIds((current) => {
            const next = new Set(current);
            next.has(readingId) ? next.delete(readingId) : next.add(readingId);
            return next;
        });
    }

    function toggleAllOnPage() {
        setSelectedIds(allOnPageSelected ? new Set() : new Set(approvableRows.map((row) => row.reading.id)));
    }

    function approve(singleRow = null) {
        if (approving) return;
        const payload = singleRow
            ? { reading_ids: [singleRow.reading.id], ...(singleRow.reading.unusual ? { confirm_unusual: true } : {}) }
            : confirming === 'all'
            ? { all: true, week, search, filter: filterValues }
            : { reading_ids: [...selectedIds], ...(selectedUnusualCount > 0 ? { confirm_unusual: true } : {}) };

        setConfirming(null);
        setSingleApprovalId(null);
        setApproving(true);
        router.post('/meter-readings/approve', payload, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => setSelectedIds((current) => singleRow ? new Set([...current].filter((id) => id !== singleRow.reading.id)) : new Set()),
            onFinish: () => setApproving(false),
        });
    }

    // The year and the month the week ends in, then that month's weeks: pick the year, the month, then the week.
    const currentYear = weekEnd.slice(0, 4);
    const currentMonth = weekEnd.slice(0, 7);
    const periodOptions = weekOptions.some((option) => option.value === week) ? weekOptions : [...weekOptions, { value: week, end: weekEnd }];
    const yearOptions = [...new Set(periodOptions.map((option) => option.end.slice(0, 4)))];
    const monthOptions = [...new Set(periodOptions.filter((option) => option.end.startsWith(currentYear)).map((option) => option.end.slice(0, 7)))];
    const weeksOfMonth = periodOptions.filter((option) => option.end.startsWith(currentMonth));

    /** Another year or month opens on its latest week. */
    function changePeriod(prefix) {
        const latest = periodOptions.find((option) => option.end.startsWith(prefix));

        if (latest) {
            changeWeek(latest.value);
        }
    }

    function changeWeek(nextWeek) {
        router.get(
            '/meter-readings',
            { ...readingWeekParams(nextWeek, weekOptions[0]?.value), search, sort: filters.sort, direction: filters.direction, per_page: filters.per_page, filter: filterValues },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    return (
        <AuthenticatedLayout>
            <Head title="القراءات الأسبوعية" />
            <div className="reading-entry" dir="rtl">
                <div className="re-heading">
                    <div className="flex items-center gap-1"><h1>القراءات الأسبوعية</h1><InfoTip label="طريقة الإدخال">أدخل القراءة الحالية بجانب القراءة السابقة، واضغط Enter أو السهم لأسفل للحفظ والانتقال للمشترك التالي، والسهم لأعلى للرجوع.</InfoTip></div>
                    <div className="re-actions">
                        <PrintButton title="القراءات الأسبوعية" selectedIds={[...selectedIds]} selectionPage="/meter-readings" />
                        <span className={`re-open ${entryWindow.isOpen ? 'is-open' : ''}`}><i />الإدخال {entryWindow.isOpen ? 'مفتوح' : 'مغلق'}</span>
                        <div className="re-week">
                            <button type="button" aria-label="الأسبوع السابق" disabled={weekIndex < 0 || weekIndex === weekOptions.length - 1} onClick={() => changeWeek(weekOptions[weekIndex + 1].value)}><Icon name="chevron-right" /></button>
                            <div className="re-week-field re-week-year"><label htmlFor="reading-week-year" className="sr-only">السنة</label><SearchableSelect id="reading-week-year" value={currentYear} onChange={changePeriod} options={yearOptions.map((year) => ({ value: year, label: year }))} clearable={false} searchable={false} className="[&>div.absolute]:min-w-0" /></div>
                            <div className="re-week-field re-week-month"><label htmlFor="reading-week-month" className="sr-only">الشهر</label><SearchableSelect id="reading-week-month" value={currentMonth} onChange={changePeriod} options={monthOptions.map((month) => ({ value: month, label: formatMonthName(`${month}-01`) }))} clearable={false} searchable={false} className="[&>div.absolute]:min-w-0" /></div>
                            <div className="re-week-field re-week-range"><label htmlFor="reading-week-range" className="sr-only">أسبوع القراءة</label><SearchableSelect id="reading-week-range" value={week} onChange={changeWeek} options={weeksOfMonth.map((option) => ({ value: option.value, label: `\u202A${fullDate(option.value)} – ${fullDate(option.end)}\u202C` }))} clearable={false} searchable={false} className="[&>div.absolute]:min-w-0" /></div>
                            <button type="button" aria-label="الأسبوع التالي" disabled={weekIndex <= 0} onClick={() => changeWeek(weekOptions[weekIndex - 1].value)}><Icon name="chevron-left" /></button>
                        </div>
                    </div>
                </div>
                {showHint && <div className="re-hint"><Icon name="info" /><p><b>كل قراءات الأسبوع في مكان واحد.</b> بعد حفظ القراءة تظهر في «بانتظار الاعتماد» حتى يراجعها المسؤول ويعتمدها. ويمكنك عرض القراءات المعتمدة بشكل منفصل.</p><button type="button" aria-label="إخفاء التوضيح" onClick={() => setShowHint(false)}>×</button></div>}
                <div className="re-top">
                    <div className="re-tabs" role="group" aria-label="حالة القراءات">
                        {STATUS_TABS.map((tab) => <button type="button" key={tab.value} aria-pressed={activeStatus === tab.value} onClick={() => changeStatus(tab.value)}>{tab.value !== 'all' && <i className={tab.value} />}{tab.label}<span>{statusSummary[tab.count].toLocaleString('en')}</span></button>)}
                    </div>
                    <div className="re-progress">
                        <div><b>{entered.toLocaleString('en')}</b><span>من {statusSummary.total.toLocaleString('en')} قراءة</span><strong>{percentage}%</strong></div>
                        <div className="re-progress-track" role="progressbar" aria-label="القراءات المدخلة" aria-valuenow={percentage} aria-valuemin={0} aria-valuemax={100}><i className="approved" style={{ width: `${statusSummary.total ? statusSummary.approved / statusSummary.total * 100 : 0}%` }} /><i className="pending" style={{ width: `${statusSummary.total ? statusSummary.pending / statusSummary.total * 100 : 0}%` }} /></div>
                        <div className="re-legend"><span><i className="approved" />معتمدة {statusSummary.approved}</span><span><i className="pending" />بانتظار الاعتماد {statusSummary.pending}</span><span><i className="missing" />لم تُدخل {statusSummary.missing}</span></div>
                    </div>
                </div>
            <EntryWindowNotice
                entryWindow={entryWindow}
                canRecord={canRecord}
                canApprove={canApprove}
                weekIsViewOnly={weekIsViewOnly}
                onShowLatestWeek={() => changeWeek(weekOptions[0].value)}
            />

            {canApprove && pendingApproval.count === 0 && activeStatus === 'pending' && (
                <div className="mb-4 flex items-center gap-3 rounded-xl border border-emerald-500/25 bg-emerald-500/10 p-4 text-sm">
                    <Icon name="check" className="h-5 w-5 shrink-0 text-emerald-600" strokeWidth={2} />
                    <p className="font-medium text-emerald-800 dark:text-emerald-300">
                        {isFiltered ? 'لا توجد قراءات بانتظار الاعتماد ضمن البحث والتصفية.' : 'لا توجد قراءات بانتظار الاعتماد لهذا الأسبوع.'}
                    </p>
                </div>
            )}

            {canApprove && pendingApproval.count > 0 && (
                <div className="re-approval">
                    <div className="flex items-center gap-3">
                        <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-amber-500/10 text-amber-600">
                            <Icon name="check" strokeWidth={2} />
                        </span>
                        <div>
                            <p className="font-semibold text-gray-900">
                                بانتظار الاعتماد: <span className="tabular-nums">{pendingApproval.count.toLocaleString('en')}</span> قراءة
                                {isFiltered && <span className="font-normal text-gray-500"> (حسب البحث والتصفية)</span>}
                            </p>
                            <p className="text-sm text-gray-500">
                                مجموعها <span className="tabular-nums">{formatCurrency(pendingApproval.amountDue)}</span> — تظهر في المعاملات المالية
                                للمشترك بعد اعتمادها.
                            </p>
                            {unusualCount > 0 && (
                                <p className="re-unusual-note">
                                    منها {unusualCount.toLocaleString('en')} قراءة غير معتادة (استهلاكها أعلى بكثير من المعتاد) لا تدخل في «اعتماد الكل»؛ راجعها
                                    وحدّدها لاعتمادها بعد التأكيد.
                                </p>
                            )}
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-4">
                        <label className="flex items-center gap-2 text-sm text-gray-600">
                            <input
                                type="checkbox"
                                checked={allOnPageSelected}
                                onChange={toggleAllOnPage}
                                disabled={approvableRows.length === 0 || approving}
                            />
                            تحديد قراءات هذه الصفحة
                        </label>
                        <PrimaryButton type="button" onClick={() => setConfirming('all')} disabled={approving || approvableAllCount === 0}>
                            <Icon name="check" className="h-4 w-4" strokeWidth={2} />
                            {isFiltered ? 'اعتماد كل النتائج' : 'اعتماد كل قراءات الأسبوع'} ({approvableAllCount.toLocaleString('en')})
                        </PrimaryButton>
                    </div>
                </div>
            )}

            <section className="re-card" ref={sheetRef}>
            <ReadingContextBar anchorRef={filtersRef} sectionRef={sheetRef} week={week} weekEnd={weekEnd} groups={filterOptions} values={filterValues} search={search} total={rows.total} onChangeFilters={setFilters} />
            <div className="re-sort">
                <div className="flex w-full min-w-0 flex-col gap-1.5 sm:w-52">
                    <span className="text-xs font-semibold text-gray-500">ترتيب حسب</span>
                    <SearchableSelect
                        id="sheet-sort"
                        value={filters.sort}
                        onChange={(value) => sortBy(value, filters.direction)}
                        options={SORT_OPTIONS}
                        searchPlaceholder="بحث في الترتيب..."
                        clearable={false}
                    />
                </div>
                <div className="flex w-full min-w-0 flex-col gap-1.5 sm:w-48">
                    <span className="text-xs font-semibold text-gray-500">الاتجاه</span>
                    <SearchableSelect
                        id="sheet-direction"
                        value={filters.direction}
                        onChange={(value) => sortBy(filters.sort, value)}
                        options={['asc', 'desc'].map((direction) => ({
                            value: direction,
                            label: DIRECTION_LABELS[(SORT_OPTIONS.find((option) => option.value === filters.sort) ?? SORT_OPTIONS[0]).type][direction],
                        }))}
                        clearable={false}
                        searchable={false}
                    />
                </div>
            </div>

            <DataTableToolbar
                printable={false}
                search={search}
                onSearchChange={setSearch}
                placeholder="بحث بالاسم أو رقم الاشتراك أو الهاتف..."
                perPage={filters.per_page}
                onPerPageChange={setPerPage}
                total={rows.total}
                filterMenu={
                    <DataTableFilterMenu
                        tableKey="meter-readings"
                        groups={filterOptions}
                        values={filterValues}
                        onChange={setFilter}
                        onChangeMany={setFilters}
                        onClear={clearFilters}
                    />
                }
            />
            <div ref={filtersRef} aria-hidden="true" />

            <div className="re-table-wrap">
                {/* While a last reading can be edited, its column keeps the editor's width, so opening it moves nothing. */}
                <table className={`re-table w-full text-sm text-start ${rows.data.some((row) => row.lastReading?.canEdit) ? 'has-last-edit' : ''}`} {...printFieldsProps(PRINT_FIELDS)}>
                    <thead>
                        <tr>
                            <SortableTh column="full_name" label="المشترك" sortState={filters} onSort={sort} className="re-sub" />
                            <SortableTh column="last_reading" label="القراءة السابقة" sortState={filters} onSort={sort} className="re-last" />
                            <SortableTh column="current_reading" label="القراءة الحالية" sortState={filters} onSort={sort} className="re-input-cell" />
                            <SortableTh column="consumption" label="الفرق (كيلو)" sortState={filters} onSort={sort} className="re-diff" />
                            <th className="re-rate">سعر الكيلو</th>
                            <th className="re-fee">قيمة القراءة</th>
                            <th className="re-min">الحد الأدنى</th>
                            <SortableTh column="amount_due" label="المطلوب دفعه" sortState={filters} onSort={sort} className="re-due" />
                            <th className="re-status">الحالة</th>
                            {seesWhoRecorded && <th className="re-by">سجّلها</th>}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.data.length === 0 ? (
                            <tr>
                                <td className="text-gray-500" colSpan={seesWhoRecorded ? 10 : 9}>
                                    لا يوجد مشتركون مطابقون.
                                </td>
                            </tr>
                        ) : (
                            rows.data.map((row) => (
                                <SheetRow
                                    key={`${week}-${row.id}`}
                                    row={row}
                                    week={week}
                                    approvable={canApprove}
                                    showRecorder={seesWhoRecorded}
                                    selected={row.canApprove && selectedIds.has(row.reading.id)}
                                    onToggleSelected={() => toggleSelected(row.reading.id)}
                                    onApprove={setSingleApprovalId}
                                    approving={approving}
                                />
                            ))
                        )}
                    </tbody>
                </table>
            </div>

            <div className="re-footer"><span>مجموع المستحق لهذا الأسبوع <b>{formatCurrency(summary.amountDue)}</b></span><span><Icon name="enter" /> Enter أو ↓ للحفظ والانتقال · ↑ للرجوع · Tab للتنقل</span></div>
            <Pagination meta={rows} filters={filters} baseUrl="/meter-readings" extraParams={weekParams} />
            </section>

            {selectedRows.length > 0 && (
                <div className="re-bulk">
                    <p className="text-sm text-gray-600">
                        <b className="text-gray-900">{selectedRows.length.toLocaleString('en')}</b> قراءة محددة · المجموع{' '}
                        <b className="tabular-nums text-gray-900">{formatCurrency(selectedTotal)}</b>
                    </p>
                    <div className="flex items-center gap-3">
                        <SecondaryButton onClick={() => setSelectedIds(new Set())} disabled={approving}>
                            إلغاء التحديد
                        </SecondaryButton>
                        <PrimaryButton type="button" onClick={() => setConfirming('selected')} disabled={approving}>
                            <Icon name="check" className="h-4 w-4" strokeWidth={2} />
                            اعتماد المحدد
                        </PrimaryButton>
                    </div>
                </div>
            )}

            <ConfirmDialog
                show={confirming !== null}
                onConfirm={() => approve()}
                onCancel={() => setConfirming(null)}
                title={confirming === 'all' ? 'اعتماد كل القراءات؟' : 'اعتماد القراءات المحددة؟'}
                message={
                    confirming === 'all'
                        ? `سيتم اعتماد ${approvableAllCount.toLocaleString('en')} قراءة لهذا الأسبوع${isFiltered ? ' مطابقة للبحث والتصفية الحالية' : ''}، وتُضاف إلى المعاملات المالية للمشتركين.${unusualCount > 0 ? ` وتبقى ${unusualCount.toLocaleString('en')} قراءة غير معتادة بانتظار مراجعتها واعتمادها واحدة واحدة.` : ''} لا يمكن تعديل القراءة بعد اعتمادها.`
                        : `سيتم اعتماد ${selectedRows.length.toLocaleString('en')} قراءة بمجموع ${formatCurrency(selectedTotal)}، وتُضاف إلى المعاملات المالية للمشتركين.${selectedUnusualCount > 0 ? ` منها ${selectedUnusualCount.toLocaleString('en')} قراءة غير معتادة استهلاكها أعلى بكثير من المعتاد، وباعتمادها تؤكد أنك راجعتها وأنها صحيحة.` : ''} لا يمكن تعديل القراءة بعد اعتمادها.`
                }
                confirmLabel="نعم، اعتمد"
                cancelLabel="مراجعة القراءات"
            />
            <ReadingApprovalDialog row={singleApprovalRow} onConfirm={() => approve(singleApprovalRow)} onCancel={() => setSingleApprovalId(null)} />
            </div>
        </AuthenticatedLayout>
    );
}
