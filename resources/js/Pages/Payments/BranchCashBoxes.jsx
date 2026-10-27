import { useEffect, useRef, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { BranchFilterBanner } from '@/Components/BranchBanner';
import Icon from '@/Components/Icon';
import SearchableSelect from '@/Components/SearchableSelect';
import SegmentedTabs from '@/Components/SegmentedTabs';
import StatusPill from '@/Components/DataTable/StatusPill';
import PrimaryButton from '@/Components/PrimaryButton';
import { formatMoney } from '@/lib/format';
import { boxEntryMatches, financialBoxMatches, financialBoxesTotals, sortFinancialBoxes } from '@/lib/financialBoxes';
import AuditApprovalDialog from './AuditApprovalDialog';
import AuditTransferSearch from './AuditTransferSearch';
import BankTransferAuditForm from './BankTransferAuditForm';
import BoxDetails from './BoxDetails';
import BoxTabs from './BoxTabs';
import FinancialBoxList from './FinancialBoxList';
import { boxTypes, BoxTypeIcon } from './CollectorCashBox';
import { FinancialBoxCard, financialAmountsLine } from './FinancialBoxComponents';
import PageHeader from '@/Components/PageHeader';

const periodDate = (date) => new Intl.DateTimeFormat('ar-u-nu-latn', { dateStyle: 'medium', timeZone: 'Asia/Gaza' }).format(new Date(`${date}T12:00:00Z`));

function savedPreference(key, fallback, allowed) {
    try {
        const value = localStorage.getItem(`financial-boxes:${key}`);
        return allowed.includes(value) ? value : fallback;
    } catch {
        return fallback;
    }
}

/** What each place holds, said under the tabs. */
const placeHints = {
    open: 'صناديق المحصّلين التي ما زالت تستقبل حركات. راجع الصندوق وأكّد إغلاقه فينتقل إلى «المغلقة».',
    closed: 'كل الصناديق المغلقة متاحة للتدقيق تلقائيًا. اختر الفترة الأسبوعية لعرض الصناديق حسب تاريخ إغلاقها.',
};

const emptyPlace = {
    open: 'لا صناديق مفتوحة فيها حركات الآن.',
    closed: 'لا توجد صناديق مغلقة خلال هذه الفترة.',
};

/** One type of box above the boxes: their total and how many there are; a click narrows the list to it. */
function SummaryTile({ icon, label, boxes, hint, active, onClick }) {
    return <button type="button" onClick={onClick} aria-pressed={active} className={`flex min-w-0 items-center gap-3 rounded-card border bg-surface p-4 text-start shadow-card transition hover:-translate-y-0.5 hover:shadow-lift focus-visible:outline focus-visible:outline-2 focus-visible:outline-gray-900 motion-reduce:transform-none ${active ? 'border-gray-900' : 'border-gray-100'}`}>
        {icon}
        <span className="min-w-0"><span className="block text-sm font-semibold text-gray-600">{label}</span><span className="mt-0.5 flex flex-wrap items-baseline gap-1.5"><span dir="ltr" className="font-display text-xl font-bold text-gray-900">{formatMoney(financialBoxesTotals(boxes).ILS)}</span><span className="text-xs text-gray-500">₪</span></span><span className="block text-xs text-gray-500">{boxes.length} {hint}</span></span>
    </button>;
}

/** Open boxes for closing, and closed boxes grouped by their closing week. */
export default function BranchCashBoxes({ branch, branches, branchScope = null, started, place, tabs, boxes, periods, week, pendingCount, periodAudit, urls, canSearchTransfers, searchingTransfers, transferSearch, canApprovePeriod }) {
    const { errors } = usePage().props;
    const [search, setSearch] = useState('');
    const [person, setPerson] = useState('');
    const [type, setType] = useState('');
    const [auditState, setAuditState] = useState('');
    const [confirmingPeriod, setConfirmingPeriod] = useState(false);
    const [busy, setBusy] = useState(false);
    const searchVisit = useRef(0);
    const [auditingEntry, setAuditingEntry] = useState(null);
    const [view, setView] = useState(() => savedPreference('view', 'table', ['table', 'cards']));
    const [sort, setSort] = useState({ key: 'dates', direction: 'desc' });
    const [boxId, setBoxId] = useState(() => Number(new URLSearchParams(window.location.search).get('box')) || null);
    const selectedBox = boxes.find((box) => box.id === boxId);
    const inPlace = boxes.filter((box) => box.place === place);
    const people = [...new Map(inPlace.filter((box) => box.user).map((box) => [box.user.id, box.user.name])).entries()].sort((a, b) => a[1].localeCompare(b[1], 'ar'));
    const currentPeriod = periods.find((period) => period.start === week);
    const sortOptions = [
        ['dates:desc', `${place === 'closed' ? 'الإضافة للتدقيق' : 'فتح الصندوق'} · الأحدث أولًا`],
        ['dates:asc', `${place === 'closed' ? 'الإضافة للتدقيق' : 'فتح الصندوق'} · الأقدم أولًا`],
        ...(place === 'closed' ? [['audited:desc', 'اعتماد التدقيق · الأحدث أولًا'], ['audited:asc', 'اعتماد التدقيق · الأقدم أولًا']] : []),
        ['number:desc', 'رقم الصندوق · الأكبر أولًا'], ['number:asc', 'رقم الصندوق · الأصغر أولًا'],
        ['total:desc', 'الإجمالي · الأعلى أولًا'], ['total:asc', 'الإجمالي · الأقل أولًا'],
        ['status:asc', 'الحالة · تصاعديًا'], ['status:desc', 'الحالة · تنازليًا'],
    ];
    const reviewedBoxes = currentPeriod?.reviewed ?? 0;
    const periodBoxes = currentPeriod?.count ?? 0;
    const shown = inPlace.filter((box) => (!type || box.type === type) && (!person || String(box.user?.id) === person) && (!auditState || Boolean(box.audit) === (auditState === 'reviewed')) && financialBoxMatches(box, search));
    const navigation = { branch_id: branch.id, place, week, ...(searchingTransfers ? { view: 'transfers' } : {}) };
    function searchTransfers(values) {
        const visit = ++searchVisit.current;
        router.get(urls.index, { ...navigation, ...values }, {
            preserveScroll: true, preserveState: true, replace: true,
            only: ['transferSearch', 'periods', 'canApprovePeriod', 'errors'],
            onStart: () => setBusy(true),
            onFinish: () => { if (visit === searchVisit.current) setBusy(false); },
        });
    }
    function reviewPeriod() {
        if (!searchingTransfers) { setConfirmingPeriod(true); return; }
        router.reload({ only: ['periodAudit', 'periods', 'transferSearch'], onStart: () => setBusy(true), onSuccess: (page) => { if (page.props.periodAudit) setConfirmingPeriod(true); }, onFinish: () => setBusy(false) });
    }
    useEffect(() => {
        try {
            localStorage.setItem('financial-boxes:view', view);
        } catch {
            return;
        }
    }, [view]);

    function closeBox() {
        setBoxId(null);
        const url = new URL(window.location.href);
        url.searchParams.delete('box');
        window.history.replaceState(window.history.state, '', url);
    }

    function perform(kind, record, extra = {}, options = {}) {
        router.post(`${urls.actions}/${kind}${record ? `/${record.id}` : ''}`, { branch_id: branch.id, ...extra }, { preserveScroll: true, ...options });
    }

    function sortBy(key) {
        setSort((current) => ({ key, direction: current.key === key && current.direction === 'asc' ? 'desc' : 'asc' }));
    }

    const summary = Object.keys(boxTypes).map((key) => ({ key, icon: <BoxTypeIcon type={key} className="h-10 w-10" />, label: boxTypes[key], boxes: inPlace.filter((box) => box.type === key), hint: { open: 'صناديق مفتوحة', closed: 'صناديق مغلقة في الفترة' }[place], active: type === key, onClick: () => setType(type === key ? '' : key) }));

    return <AuthenticatedLayout badges={{ '/payments/summary': pendingCount }} scope={branchScope?.name ?? null} header={<PageHeader eyebrow={branchScope ? null : branches.length > 1 ? <select aria-label="الفرع" className="rounded-control border-gray-200 py-1 text-sm" value={branch.id} onChange={(event) => router.get(urls.index, { ...navigation, branch_id: event.target.value })}>{branches.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select> : branch.name} title="الصناديق المالية" info={started && !searchingTransfers ? placeHints[place] : null} titleAddon={<BoxTabs tabs={tabs} current={place} />} />}>
        <Head title="الصناديق المالية" />
        {branchScope && <BranchFilterBanner scope={branchScope} eyebrow="تعرض الآن صناديق" group={{ options: branches.map((item) => ({ value: String(item.id), label: item.name })) }} value={String(branch.id)} onChange={(value) => value && router.get(urls.index, { ...navigation, branch_id: value })} allowAll={false} />}
        {!started ? <div className="flex flex-wrap items-center justify-between gap-4 rounded-panel border border-dashed border-gray-200 bg-surface p-6 text-sm leading-7 text-gray-500">
            <p>تُفتح الصناديق تلقائيًا عند تسجيل أول دفعة أو مقاصة في الفرع، وتُضاف كل حركة إلى صندوق الموظف حسب نوعها.</p>
        </div> : <div className="financial-boxes financial-surface space-y-5">
            {errors?.action && <p role="alert" className="rounded-control bg-red-500/10 p-4 text-sm text-red-700">{errors.action}</p>}
            {canSearchTransfers && place === 'closed' && <SegmentedTabs label="مساحة التدقيق" value={searchingTransfers ? 'transfers' : 'boxes'} onChange={(value) => router.get(urls.index, { branch_id: branch.id, place: 'closed', week, ...(value === 'transfers' ? { view: 'transfers' } : {}) })} options={[{ value: 'boxes', label: <><Icon name="wallet" className="inline h-4 w-4" /> الصناديق</> }, { value: 'transfers', label: <><Icon name="search" className="inline h-4 w-4" /> البحث عن حوالة</> }]} />}
            {place === 'closed' && <div className="flex flex-wrap items-end justify-between gap-4 rounded-card border border-gray-100 bg-surface p-4 shadow-card sm:p-5">
                <div className="flex min-w-0 flex-1 items-start gap-3"><Icon name="calendar" className="mt-1 h-5 w-5 shrink-0 text-gray-500" /><div className="space-y-2"><h3 className="font-bold text-gray-900">فترة الصناديق المغلقة</h3><p className="text-xs text-gray-500">دُقّق {reviewedBoxes} من {periodBoxes} صندوقًا في الفترة المختارة.</p><StatusPill tone={periodBoxes > 0 && reviewedBoxes === periodBoxes ? 'green' : 'amber'} label={periodBoxes > 0 && reviewedBoxes === periodBoxes ? 'اكتمل تدقيق الفترة' : 'لم يكتمل تدقيق الفترة'} /></div></div>
                <div className="flex w-full flex-wrap items-end gap-3 sm:w-auto">{(searchingTransfers ? canApprovePeriod && periodBoxes > reviewedBoxes : periodAudit?.boxes > 0) && <PrimaryButton disabled={busy} onClick={reviewPeriod}><Icon name="check" className="h-4 w-4" />{busy ? 'جارٍ تحديث الملخص…' : 'اعتماد الفترة كاملة'}</PrimaryButton>}<div className="w-full sm:w-96"><label htmlFor="closed-box-week" className="mb-1 block text-sm font-medium text-gray-700">الفترة الأسبوعية</label><SearchableSelect id="closed-box-week" value={week} clearable={false} options={periods.map((period) => ({ value: period.start, label: `${periodDate(period.start)} — ${periodDate(period.end)}`, hint: `${period.count} صندوق · ${period.reviewed === period.count ? 'تم التدقيق' : `دُقّق ${period.reviewed}`}` }))} onChange={(value) => router.get(urls.index, { ...navigation, week: value }, { preserveScroll: true })} /></div></div>
            </div>}
            {searchingTransfers ? <>
                <AuditTransferSearch key={`${branch.id}:${week}`} data={transferSearch} branchName={branch.name} busy={busy} errors={errors} onSearch={searchTransfers} onPage={(url) => router.visit(url, { preserveState: true, preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) })} onEdit={setAuditingEntry} />
                <section aria-label="ملخص الحوالات غير المعتمدة في الفترة" className="rounded-card border border-gray-100 bg-surface p-5"><h3 className="mb-4 font-bold text-gray-900">ملخص الفترة قبل الاعتماد <span className="font-normal text-gray-500">· يشمل كل الصناديق، خارج نتائج البحث أيضًا</span></h3><dl className="grid grid-cols-2 gap-5 lg:grid-cols-4">{[['الحوالات غير المعتمدة', transferSearch.summary.transfers], ['حوالات فيها فروق', transferSearch.summary.exceptions], ['تحميل على المشتركين (شيكل)', formatMoney(transferSearch.summary.debit)], ['إضافة لصالح المشتركين (شيكل)', formatMoney(transferSearch.summary.credit)]].map(([label, value]) => <div key={label}><dt className="text-sm text-gray-500">{label}</dt><dd className="mt-1 font-display text-2xl font-bold text-gray-900">{value}</dd></div>)}</dl><p className="mt-4 border-t border-gray-100 pt-3 text-sm leading-7 text-gray-600">عند الاعتماد، تُحفظ الاستثناءات وتُرحّل فروقها، وتُعتمد {transferSearch.summary.automaticMatches} حوالة لم تُراجع كمطابقة تلقائيًا.</p></section>
            </> : <><section aria-label="ملخص" className="grid gap-3 sm:grid-cols-3">{summary.map(({ key, ...tile }) => <SummaryTile key={key} {...tile} />)}</section>
            <section aria-label="الصناديق">
                <div className="data-table-toolbar flex flex-wrap items-center justify-between gap-3">
                    <div className="flex w-full flex-wrap items-center gap-3 sm:w-auto">
                        <div className="relative w-full sm:w-72"><Icon name="search" className="pointer-events-none absolute inset-y-0 start-3.5 my-auto h-[18px] w-[18px] text-gray-400" /><input type="text" aria-label="ابحث بالصندوق أو المشترك أو السند أو مرجع الحوالة" placeholder="ابحث بالصندوق أو المشترك أو السند أو مرجع الحوالة" value={search} onChange={(event) => setSearch(event.target.value)} className="block w-full py-2.5 pe-4 ps-10 text-sm" /></div>
                        <select aria-label="الموظف" value={person} onChange={(event) => setPerson(event.target.value)} className="w-full py-2.5 text-sm sm:w-56"><option value="">كل الموظفين</option>{people.map(([id, name]) => <option key={id} value={String(id)}>{name}</option>)}</select>
                        <select aria-label="ترتيب الصناديق" value={`${sort.key}:${sort.direction}`} onChange={(event) => { const [key, direction] = event.target.value.split(':'); setSort({ key, direction }); }} className="w-full py-2.5 text-sm sm:w-72">{sortOptions.map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select>
                    </div>
                    <div className="flex min-w-0 max-w-full flex-wrap items-center gap-2">
                        {place === 'closed' && <select aria-label="حالة التدقيق" value={auditState} onChange={(event) => setAuditState(event.target.value)} className="py-2.5 text-sm"><option value="">كل حالات التدقيق</option><option value="waiting">بانتظار التدقيق</option><option value="reviewed">تم التدقيق</option></select>}
                        <SegmentedTabs label="نوع الصندوق" value={type} onChange={setType} options={[{ value: '', label: 'كل الأنواع' }, ...Object.entries(boxTypes).map(([value, label]) => ({ value, label }))]} />
                        <SegmentedTabs label="طريقة العرض" value={view} onChange={setView} options={[{ value: 'table', label: <><Icon name="table" className="h-4 w-4" />جدول</> }, { value: 'cards', label: <><Icon name="grid" className="h-4 w-4" />بطاقات</> }]} />
                    </div>
                </div>
                <div className="data-table-container financial-box-list-container">
                    {!shown.length ? <div className="flex flex-col items-center gap-2 px-4 py-14 text-center text-gray-500"><Icon name="wallet" className="h-8 w-8 text-gray-400" /><p>{inPlace.length ? 'لا توجد صناديق بهذه الفلاتر.' : emptyPlace[place]}</p></div>
                        : view === 'cards' ? <div className="grid gap-4 py-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">{sortFinancialBoxes(shown, sort).map((box) => <FinancialBoxCard key={box.id} box={box} onOpen={(item) => setBoxId(item.id)} />)}</div>
                            : <FinancialBoxList boxes={sortFinancialBoxes(shown, sort)} onOpen={(box) => setBoxId(box.id)} sortState={{ sort: sort.key, direction: sort.direction }} onSort={sortBy} />}
                </div>
                <p className="data-table-totals text-sm text-gray-600"><span>المعروض <b className="font-display text-gray-900">{shown.length}</b> من <b className="font-display text-gray-900">{inPlace.length}</b> صندوقًا</span><span>مجموع الصناديق المعروضة: <b className="font-display text-gray-900">{financialAmountsLine(financialBoxesTotals(shown))}</b></span></p>
            </section></>}
        </div>}
        {selectedBox && <BoxDetails key={selectedBox.id} box={selectedBox} initialSearch={(selectedBox.payments ?? []).some((entry) => boxEntryMatches(entry, search)) ? search : ''} onClose={closeBox} perform={perform} />}
        {auditingEntry && <BankTransferAuditForm key={auditingEntry.id} entry={auditingEntry} onClose={() => setAuditingEntry(null)} />}
        {confirmingPeriod && periodAudit && <AuditApprovalDialog title="اعتماد تدقيق الفترة كاملة؟" scope={`${branch.name} · ${periodDate(week)} — ${periodDate(currentPeriod.end)}`} summary={periodAudit} url={urls.auditPeriod} payload={{ branch_id: branch.id, week, box_ids: periodAudit.box_ids, snapshot: periodAudit.snapshot }} onClose={() => setConfirmingPeriod(false)} />}
    </AuthenticatedLayout>;
}
