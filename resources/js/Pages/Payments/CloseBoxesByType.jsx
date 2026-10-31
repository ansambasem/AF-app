import { useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import ConfirmDialog from '@/Components/ConfirmDialog';
import Icon from '@/Components/Icon';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { formatMoney } from '@/lib/format';
import { boxTypes, boxTypeStyles, BoxTypeIcon } from './CollectorCashBox';
import { BoxPersonAvatar, financialAmountsLine } from './FinancialBoxComponents';
import { financialBoxesTotals } from '@/lib/financialBoxes';
import BoxBreakdown from './BoxBreakdown';

/**
 * Closes the open boxes of one type the closer ticks, together, for those
 * who may confirm the branch's boxes closed: the type, then its open boxes
 * (one person's when the entries are narrowed to them) to tick, then the
 * ticked boxes' entries summed up by bank, service or currency paid, and,
 * for cash, the confirmation that the cash was counted. A row of steps
 * under the title shows where the closer is and what is left to do.
 */
export default function CloseBoxesByType({ boxes, branchId, userId, initialType, actionsUrl, onClose }) {
    const { errors } = usePage().props;
    const types = Object.keys(boxTypes);
    const [type, setType] = useState(initialType && types.includes(initialType) ? initialType : (types.find((value) => boxes.some((box) => box.type === value)) ?? types[0]));
    const [cashCounted, setCashCounted] = useState(false);
    const [selectedIds, setSelectedIds] = useState([]);
    const [processing, setProcessing] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const shown = boxes.filter((box) => box.type === type);
    const selected = shown.filter((box) => selectedIds.includes(box.id));
    const allSelected = shown.length > 0 && selected.length === shown.length;
    const cash = type === 'cash';
    const totals = financialBoxesTotals(selected);
    const ready = selected.length > 0 && (!cash || cashCounted);
    const sections = { type: useRef(null), boxes: useRef(null), review: useRef(null) };
    const steps = [
        { key: 'type', label: 'نوع الصندوق', detail: boxTypeStyles[type].label, done: true },
        { key: 'boxes', label: 'اختيار الصناديق', detail: selected.length ? (selected.length === 1 ? 'صندوق واحد مختار' : `${selected.length} صناديق مختارة`) : 'لم تختر بعد', done: selected.length > 0 },
        { key: 'review', label: 'المراجعة والتأكيد', detail: !selected.length ? 'بعد اختيار الصناديق' : ready ? 'جاهز للإغلاق' : 'أكّد عدّ النقد', done: ready },
    ];
    const current = selected.length ? 'review' : 'boxes';

    function pickType(value) {
        setType(value);
        setSelectedIds([]);
        setCashCounted(false);
    }

    function toggle(id) {
        setSelectedIds((ids) => ids.includes(id) ? ids.filter((value) => value !== id) : [...ids, id]);
        setCashCounted(false);
    }

    function toggleAll() {
        setSelectedIds(allSelected ? [] : shown.map((box) => box.id));
        setCashCounted(false);
    }

    function submit() {
        router.post(`${actionsUrl}/receive-type`, { branch_id: branchId, type, box_ids: selected.map((box) => box.id), cash_counted: cash && cashCounted }, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => onClose(),
        });
    }

    return <Modal show onClose={onClose} maxWidth="3xl" centered panelClassName="flex max-h-[calc(100dvh-4rem)] flex-col">
        <header className="shrink-0 space-y-4 border-b border-gray-100 p-5 sm:p-6">
            <div className="flex items-start justify-between gap-3">
            <div className="flex items-start gap-3">
                <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand-500/10 text-brand-600"><Icon name="lock" className="h-5 w-5" /></span>
                <div>
                    <h2 className="text-xl font-bold text-gray-900">إغلاق الصناديق حسب النوع</h2>
                    <p className="mt-1 text-sm leading-6 text-gray-500">اختر النوع ثم الصناديق المفتوحة{userId ? ' للموظف المختار' : ''} التي تريد إغلاقها، وراجع ملخصها قبل التأكيد. بعد التأكيد تُقفل حركاتها وتذهب إلى التدقيق.</p>
                </div>
            </div>
            <button type="button" onClick={onClose} aria-label="إغلاق النافذة" className="rounded-control p-2 text-gray-500 transition hover:bg-gray-100 hover:text-gray-900"><Icon name="close" className="h-5 w-5" /></button>
            </div>
            <CloseSteps steps={steps} current={current} onGo={(key) => sections[key].current?.scrollIntoView({ behavior: 'smooth', block: 'start' })} />
        </header>
        <div className="min-h-0 flex-1 space-y-6 overflow-y-auto bg-gray-50/60 p-5 sm:p-6">
            <section ref={sections.type} aria-label="نوع الصندوق" className="scroll-mt-4 space-y-3">
            <StepHeading number={1} title="اختر نوع الصندوق" done />
            <div role="group" aria-label="نوع الصندوق" className="grid gap-2 sm:grid-cols-3">
                {types.map((value) => {
                    const ofType = boxes.filter((box) => box.type === value);
                    const chosen = value === type;
                    return <button key={value} type="button" aria-pressed={chosen} onClick={() => pickType(value)} className={`flex items-center gap-3 rounded-2xl border bg-surface p-3 text-start transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-gray-900 ${chosen ? 'border-gray-900 shadow-[0_0_0_3px_rgb(var(--gray-900)/0.08)]' : 'border-gray-100 hover:border-gray-300'}`}>
                        <span className="relative shrink-0"><BoxTypeIcon type={value} className="h-10 w-10" />{chosen && <span className="absolute -end-1 -top-1 flex h-4 w-4 items-center justify-center rounded-full bg-gray-900 text-surface ring-2 ring-surface"><Icon name="check" className="h-2.5 w-2.5" strokeWidth={3} /></span>}</span>
                        <span className="min-w-0">
                            <span className="block font-semibold text-gray-900">{boxTypeStyles[value].label}</span>
                            <span className="block truncate text-xs text-gray-500"><b className="font-display text-gray-700">{ofType.length}</b> صناديق · <bdi dir="ltr" className="font-display">{formatMoney(financialBoxesTotals(ofType).ILS)}</bdi> شيكل</span>
                        </span>
                    </button>;
                })}
            </div>
            </section>

            {errors?.action && <p role="alert" className="rounded-control bg-red-500/10 p-3 text-sm text-red-700">{errors.action}</p>}

            <section ref={sections.boxes} aria-label="الصناديق المفتوحة" className="scroll-mt-4 space-y-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <StepHeading number={2} title="اختر الصناديق" done={selected.length > 0} active={current === 'boxes'} hint={`${selected.length} من ${shown.length}`} />
                    {shown.length > 1 && <button type="button" onClick={toggleAll} className="rounded-lg px-2 py-1 text-sm font-semibold text-brand-600 transition hover:bg-brand-500/10">{allSelected ? 'إلغاء تحديد الكل' : 'تحديد الكل'}</button>}
                </div>
                {!shown.length ? <p className="rounded-2xl border border-dashed border-gray-200 bg-surface p-6 text-center text-sm text-gray-500">لا توجد صناديق مفتوحة من هذا النوع يمكنك إغلاقها.</p> : <ul className="grid gap-2 sm:grid-cols-2">
                    {shown.map((box) => {
                        const picked = selectedIds.includes(box.id);
                        return <li key={box.id}><label className={`flex cursor-pointer items-center gap-3 rounded-2xl border bg-surface p-3 transition focus-within:outline focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-gray-900 ${picked ? 'border-gray-900 shadow-[0_0_0_3px_rgb(var(--gray-900)/0.08)]' : 'border-gray-100 hover:border-gray-300'}`}>
                            <input type="checkbox" className="sr-only" checked={picked} onChange={() => toggle(box.id)} aria-label={`اختيار صندوق ${box.number}`} />
                            <span aria-hidden="true" className={`flex h-5 w-5 shrink-0 items-center justify-center rounded-md border-[1.5px] transition ${picked ? 'border-gray-900 bg-gray-900 text-surface' : 'border-gray-300'}`}>{picked && <Icon name="check" className="h-3.5 w-3.5" strokeWidth={3} />}</span>
                            <BoxPersonAvatar name={box.person} />
                            <span className="min-w-0 flex-1">
                                <span className="block truncate font-semibold text-gray-900">{box.person ?? '—'}</span>
                                <span className="block text-xs text-gray-500">صندوق <bdi dir="ltr" className="font-display">{box.number}</bdi> · <b className="font-display text-gray-700">{box.count}</b> حركة</span>
                            </span>
                            <span className="shrink-0 text-end"><b dir="ltr" className="block font-display text-gray-900">{formatMoney(financialBoxesTotals([box]).ILS)}</b><span className="text-xs text-gray-500">₪</span></span>
                        </label></li>;
                    })}
                </ul>}
            </section>

            {shown.length > 0 && <section ref={sections.review} aria-label="المراجعة والتأكيد" className="scroll-mt-4 space-y-3">
                <StepHeading number={3} title="راجع الملخص ثم أكّد الإغلاق" done={ready} active={current === 'review'} hint={selected.length && cash && !cashCounted ? 'أكّد عدّ النقد في الأسفل' : undefined} />
                {selected.length ? <BoxBreakdown type={type} entries={selected.flatMap((box) => box.entries ?? [])} /> : <p className="flex items-center gap-3 rounded-2xl border border-dashed border-gray-200 bg-surface p-4 text-sm text-gray-500"><Icon name="list" className="h-5 w-5 shrink-0 text-gray-400" />اختر صندوقًا أو أكثر ليظهر ملخصه {type === 'bank_transfer' ? 'حسب البنك' : type === 'clearing' ? 'حسب الخدمة' : 'حسب عملة الدفع'} قبل الإغلاق.</p>}
            </section>}
        </div>
        <footer className="shrink-0 space-y-3 border-t border-gray-100 bg-surface p-4 sm:px-6">
            {cash && selected.length > 0 && <label className="flex cursor-pointer items-start gap-3 rounded-control border border-emerald-500/25 bg-emerald-500/5 p-3 text-sm text-gray-800">
                <input type="checkbox" className="mt-0.5 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500" checked={cashCounted} onChange={(event) => setCashCounted(event.target.checked)} />
                <span>عددتُ نقد كل صندوق من الصناديق المختارة ووجدته مطابقًا لإجماليه: <b className="text-gray-900">{financialAmountsLine(totals)}</b></span>
            </label>}
            <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="text-sm text-gray-600">{selected.length ? <>{selected.length === 1 ? 'صندوق واحد مختار' : <><b className="font-display text-gray-900">{selected.length}</b> صناديق مختارة</>} · <b className="text-gray-900">{financialAmountsLine(totals)}</b></> : 'لم تختر أي صندوق بعد'}</p>
                <div className="flex flex-wrap items-center gap-2">
                    <SecondaryButton onClick={onClose}>إلغاء</SecondaryButton>
                    <PrimaryButton disabled={processing || !selected.length || (cash && !cashCounted)} onClick={() => setConfirming(true)}><Icon name="check" className="h-4 w-4" />{selected.length === 1 ? 'تأكيد إغلاق الصندوق' : selected.length ? `تأكيد إغلاق ${selected.length} صناديق` : 'تأكيد الإغلاق'}</PrimaryButton>
                </div>
            </div>
        </footer>
        <ConfirmDialog
            show={confirming}
            onCancel={() => setConfirming(false)}
            onConfirm={() => { setConfirming(false); submit(); }}
            title={selected.length === 1 ? 'إغلاق الصندوق؟' : `إغلاق ${selected.length} صناديق؟`}
            message={<>{selected.length === 1 ? 'سيُغلق صندوق' : `ستُغلق ${selected.length} صناديق`} {boxTypeStyles[type].label} ({[...new Set(selected.map((box) => box.person ?? '—'))].join('، ')}) بإجمالي <b className="text-gray-900">{financialAmountsLine(totals)}</b>.{cash ? ' وأنت تؤكد أنك عددت نقد كل صندوق ووجدته مطابقًا لإجماليه.' : ''} بعد الإغلاق تُقفل حركاتها فلا يستطيع المحصّل تعديلها، وتنتقل إلى قسم التدقيق.</>}
            confirmLabel={selected.length === 1 ? 'نعم، أغلق الصندوق' : `نعم، أغلق ${selected.length} صناديق`}
            icon="lock"
        />
    </Modal>;
}

/**
 * Where the closer is: the three steps of closing boxes by type, each done
 * (a check), the current one (dark) or still ahead (quiet), with what was
 * chosen in it; a step takes the closer to its part of the window.
 */
function CloseSteps({ steps, current, onGo }) {
    return <ol aria-label="خطوات الإغلاق" className="grid w-full grid-cols-3 gap-2">
        {steps.map((step, index) => {
            const active = step.key === current;
            const state = active ? 'current' : step.done ? 'done' : 'ahead';
            return <li key={step.key} className="min-w-0">
                <button type="button" onClick={() => onGo(step.key)} aria-current={active ? 'step' : undefined} className={`flex w-full min-w-0 items-center gap-2.5 rounded-xl border px-2.5 py-2 text-start transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-gray-900 ${state === 'current' ? 'border-gray-900 bg-gray-900 text-surface shadow-sm' : state === 'done' ? 'border-emerald-500/25 bg-emerald-500/5 hover:border-emerald-500/40' : 'border-gray-100 bg-gray-50 hover:border-gray-200'}`}>
                    <span aria-hidden="true" className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-full font-display text-sm font-bold ${state === 'current' ? 'bg-surface text-gray-900' : state === 'done' ? 'bg-emerald-600 text-white' : 'bg-gray-200 text-gray-500'}`}>{step.done ? <Icon name="check" className="h-4 w-4" strokeWidth={3} /> : index + 1}</span>
                    <span className="min-w-0">
                        <span className={`block truncate text-xs font-bold sm:text-sm ${state === 'current' ? 'text-surface' : state === 'done' ? 'text-gray-900' : 'text-gray-500'}`}>{step.label}</span>
                        <span className={`hidden truncate text-xs sm:block ${state === 'current' ? 'text-surface/70' : 'text-gray-500'}`}>{step.detail}</span>
                    </span>
                    <span className="sr-only">{active ? '(الخطوة الحالية)' : step.done ? '(مكتملة)' : '(لاحقًا)'}</span>
                </button>
            </li>;
        })}
    </ol>;
}

/** A step's title in the window, numbered as in the steps row, checked once it is done. */
function StepHeading({ number, title, done = false, active = false, hint }) {
    return <h3 className="flex items-center gap-2 font-bold text-gray-900">
        <span aria-hidden="true" className={`flex h-6 w-6 shrink-0 items-center justify-center rounded-full font-display text-xs font-bold ${done ? 'bg-emerald-600 text-white' : active ? 'bg-gray-900 text-surface' : 'bg-gray-200 text-gray-500'}`}>{done ? <Icon name="check" className="h-3.5 w-3.5" strokeWidth={3} /> : number}</span>
        {title}
        {hint && <span className="text-sm font-normal text-gray-500">({hint})</span>}
    </h3>;
}
