import { useEffect, useMemo, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import StatusPill from '@/Components/DataTable/StatusPill';
import Icon from '@/Components/Icon';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton, { secondaryButtonClass } from '@/Components/SecondaryButton';
import InputError from '@/Components/InputError';
import { formatNumber } from '@/lib/format';
import { STAGES, assignmentMatches, boardColumns, boardCounters, boxMatches, columnTotals, distributionAreas, distributionBoxOptions, movePayload, toggleBoxSelection } from '@/lib/readingBoard';
import PageHeader from '@/Components/PageHeader';
import DateInput from '@/Components/DateInput';
import DistributionTable from './DistributionTable';
import DistributionAreas from './DistributionAreas';
import DistributionSummaryModal from './DistributionSummaryModal';
import DistributionActionModal from './DistributionActionModal';
import SearchableSelect from '@/Components/SearchableSelect';
import DataTableToolbar from '@/Components/DataTable/DataTableToolbar';
import PrintButton from '@/Components/PrintButton';


function DistributionSummary({ boxes }) {
    const counters = boardCounters(boxes);
    const read = columnTotals(boxes).read;
    return (
        <div className="mb-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-[1.4fr_repeat(3,minmax(0,1fr))]">
            <div className="relative overflow-hidden rounded-card bg-graphite-gradient p-5 text-white shadow-card">
                <span aria-hidden="true" className="absolute inset-x-0 top-0 h-[3px] bg-spectrum" />
                <p className="text-sm text-white/70">الطبلونات ضمن نتائج البحث والتصفية</p>
                <p className="mt-1 font-display text-4xl font-bold">{formatNumber(boxes.length)} <span className="text-sm font-semibold text-white/70">طبلون</span></p>
                <div className="mt-4 flex items-center gap-2 border-t border-white/15 pt-3 text-xs text-white/70"><Icon name="users" className="h-4 w-4" />المشتركون <b className="font-display text-sm text-white">{formatNumber(counters.subscribers)}</b></div>
            </div>
            {[
                { icon: 'users', label: 'موزعة على القرّاء', value: counters.mobile, hint: `قُرئ ${formatNumber(read)} من ${formatNumber(counters.subscribers)} مشترك`, tone: 'text-gray-900' },
                { icon: 'table', label: 'بانتظار التوزيع', value: counters.available, hint: 'اختر قارئًا أو خصصها للكشوف الورقية', tone: 'text-amber-700 dark:text-amber-400' },
                { icon: 'printer', label: 'للكشوف الورقية', value: counters.paper, hint: 'مخصصة للقراءة دون هاتف', tone: 'text-gray-900' },
            ].map((stat) => <div key={stat.label} className="rounded-card border border-gray-100 bg-surface p-5 shadow-card">
                <span className="mb-3 flex h-9 w-9 items-center justify-center rounded-control bg-gray-50 text-gray-600"><Icon name={stat.icon} className="h-5 w-5" /></span>
                <p className="text-xs font-semibold text-gray-500">{stat.label}</p>
                <p className={`mt-1 font-display text-2xl font-bold ${stat.tone}`}>{formatNumber(stat.value)}</p>
                <p className="mt-2 text-xs leading-5 text-gray-500">{stat.hint}</p>
            </div>)}
        </div>
    );
}

/** What a move or approval did, with each box it could not move and why. */
function AllocationReport({ result, onConfirmDownloaded, onClose }) {
    const needsConfirmation = result.conflicts.some((conflict) => conflict.reason.includes('أكّد النقل'));

    return (
        <section role="status" aria-live="polite" className={`mb-5 rounded-card border p-4 ${result.conflicts.length ? 'border-amber-500/30 bg-amber-500/5' : 'border-emerald-500/30 bg-emerald-500/5'}`}>
            <div className="flex items-start justify-between gap-3">
                <p className="font-bold text-gray-900">
                    {result.action === 'approve' ? 'اعتُمد' : result.action === 'copy' ? 'نُسخ من الدورة السابقة' : 'نُقل'} {formatNumber(result.moved)} طبلون
                    {result.conflicts.length > 0 && <> · راجع الطبلونات التي تعذّر تحديثها أدناه</>}
                </p>
                <button type="button" onClick={onClose} aria-label="إغلاق التقرير" className="rounded-control p-1.5 text-gray-500 hover:bg-gray-100">
                    <Icon name="close" className="h-4 w-4" />
                </button>
            </div>
            {result.conflicts.length > 0 && (
                <ul className="mt-3 max-h-48 divide-y divide-gray-100 overflow-y-auto rounded-control border border-gray-100 bg-surface text-sm">
                    {result.conflicts.map((conflict, index) => (
                        <li key={`${conflict.boxId ?? conflict.label}-${index}`} className="flex flex-wrap justify-between gap-2 px-3 py-2">
                            <b className="text-gray-900">{conflict.label}</b>
                            <span className="text-xs text-gray-600">{conflict.reason}</span>
                        </li>
                    ))}
                </ul>
            )}
            {needsConfirmation && onConfirmDownloaded && (
                <button type="button" onClick={onConfirmDownloaded} className="mt-3 inline-flex items-center gap-1.5 rounded-control bg-amber-600 px-3 py-2 text-sm font-bold text-white">
                    <Icon name="warning" className="h-4 w-4" />
                    أؤكد نقل الطبلونات التي نزّلها القارئ (قراءاته غير المرفوعة تُحفظ للمراجعة)
                </button>
            )}
        </section>
    );
}

/** Move the cycle's reading day within the days its week allows. */
function ReadingDayModal({ cycle, onClose }) {
    const { errors } = usePage().props;
    const [date, setDate] = useState(cycle.readingDate);

    function submit(event) {
        event.preventDefault();
        router.patch(`/reading-cycles/${cycle.id}`, { reading_date: date }, { preserveScroll: true, onSuccess: onClose });
    }

    return (
        <Modal show onClose={onClose} maxWidth="md" centered>
            <form onSubmit={submit} className="space-y-4 p-7" aria-labelledby="reading-day-title">
                <h3 id="reading-day-title" className="text-lg font-bold text-gray-900">تغيير يوم القراءة لهذه الدورة</h3>
                <p className="text-sm text-gray-600">نهاية الأسبوع {cycle.weekEnd}. يمكن تأخير القراءة حتى {cycle.maxReadingDate} إذا واجه الفرع مشكلة، ولا يمكن تقديمها قبل نهاية الأسبوع.</p>
                <DateInput name="reading_date" aria-label="يوم القراءة" min={cycle.weekEnd} max={cycle.maxReadingDate} value={date} onChange={setDate} required />
                <InputError message={errors.reading_date} />
                <p className="text-xs text-gray-500">القرّاء الذين نزّلوا مهامهم ينزّلونها من جديد ليعرفوا اليوم الجديد؛ القراءات في غير يوم القراءة تُحفظ للمراجعة.</p>
                <div className="flex justify-end gap-3">
                    <SecondaryButton onClick={onClose}>إلغاء</SecondaryButton>
                    <PrimaryButton type="submit">حفظ</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}

/** Weekly allocations grouped by area, with standing and one-week changes. */
export default function Show({ cycle, boards, readers, paperSheets, reviewCount, abilities, showBranchSelector = false }) {
    const { flash, props } = usePage();
    const errors = props.errors ?? {};
    const [selected, setSelected] = useState(() => new Set());
    const [search, setSearch] = useState('');
    const [subAreaIds, setSubAreaIds] = useState([]);
    const [target, setTarget] = useState('');
    const [result, setResult] = useState(null);
    const [lastMove, setLastMove] = useState(null);
    const [editingDay, setEditingDay] = useState(false);
    const [summaryOpen, setSummaryOpen] = useState(false);
    const [actionsOpen, setActionsOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const [recurring, setRecurring] = useState(true);
    const [assignmentFilter, setAssignmentFilter] = useState('');
    const [boxId, setBoxId] = useState('');
    const [perPage, setPerPage] = useState(25);
    const [page, setPage] = useState(1);
    const boxOptions = useMemo(() => distributionBoxOptions(boards, subAreaIds, assignmentFilter), [boards, subAreaIds, assignmentFilter]);
    const visibleBoxes = boards.filter((box) => boxMatches(box, search, subAreaIds, boxId) && assignmentMatches(box, assignmentFilter));
    const remainingBoxes = visibleBoxes.filter((box) => box.column === 'available');
    const selectedBoxes = boards.filter((box) => selected.has(box.id));
    const selectedTotals = columnTotals(selectedBoxes);
    const visibleIds = new Set(visibleBoxes.map((box) => box.id));
    const hiddenSelected = selectedBoxes.filter((box) => !visibleIds.has(box.id)).length;
    const allVisibleSelected = visibleBoxes.length > 0 && visibleBoxes.every((box) => selected.has(box.id));
    const areas = useMemo(() => distributionAreas(boards, assignmentFilter), [boards, assignmentFilter]);
    const columns = useMemo(() => boardColumns(readers), [readers]);
    const counters = boardCounters(boards);
    const totalPages = Math.max(1, Math.ceil(visibleBoxes.length / perPage));
    const currentPage = Math.min(page, totalPages);
    const pageBoxes = visibleBoxes.slice((currentPage - 1) * perPage, currentPage * perPage);
    const stage = STAGES[cycle.stage];

    useEffect(() => {
        if (flash?.allocationResult) {
            setResult(flash.allocationResult);
        }
    }, [flash]);

    useEffect(() => {
        if (boxId && !boxOptions.some((option) => option.value === boxId)) {
            setBoxId('');
            setPage(1);
        }
    }, [boxId, boxOptions]);

    function toggle(id) {
        setSelected((current) => {
            const next = new Set(current);
            next.has(id) ? next.delete(id) : next.add(id);
            return next;
        });
    }

    function toggleColumn(boxes) {
        setSelected((current) => toggleBoxSelection(current, boxes));
    }

    function move(boxIds, column, confirmDownloaded = false, repeat = recurring) {
        const ids = boxIds;

        if (busy || ids.length === 0 || ids.length > 1000) {
            return;
        }

        setBusy(true);
        setResult(null);
        const saveRecurring = abilities.approve && repeat;
        setLastMove({ boxIds: ids, column, recurring: saveRecurring });
        router.post(`/reading-cycles/${cycle.id}/assignments`, { ...movePayload(ids, column, boards, confirmDownloaded), recurring: saveRecurring, publish: abilities.approve }, {
            preserveScroll: true,
            onSuccess: () => {
                setSelected(column.key === 'paper' ? new Set(ids) : new Set());
                setActionsOpen(false);
                setSummaryOpen(true);
            },
            onFinish: () => setBusy(false),
        });
    }

    function approve(data) {
        if (busy) return;
        setBusy(true);
        router.post(`/reading-cycles/${cycle.id}/assignments/approve`, data, { preserveScroll: true, onFinish: () => setBusy(false) });
    }

    function assignSelected() {
        const column = columns.find((candidate) => candidate.key === target);

        if (column) {
            move(selectedBoxes.map((box) => box.id), column);
        }
    }

    function createSheet() {
        if (busy) return;
        const ids = [...selected].filter((id) => {
            const box = boards.find((candidate) => candidate.id === id);
            return box?.column === 'paper' && box.status === 'approved';
        });
        if (ids.length === 0 || ids.length > 200) return;
        setBusy(true);
        router.post(`/reading-cycles/${cycle.id}/paper-sheets`, { box_ids: ids }, { onFinish: () => setBusy(false) });
    }

    function openActions(boxes, defaultTarget = '') {
        setSelected(new Set(boxes.map((box) => box.id)));
        setTarget(defaultTarget);
        if (defaultTarget === 'paper') setRecurring(false);
        setSummaryOpen(false);
        setActionsOpen(true);
    }

    function showPaperSheets() {
        setSummaryOpen(false);
        requestAnimationFrame(() => document.getElementById('sheets-title')?.scrollIntoView({ behavior: 'smooth', block: 'center' }));
    }

    const selectedPaperApproved = [...selected].filter((id) => {
        const box = boards.find((candidate) => candidate.id === id);

        return box?.column === 'paper' && box.status === 'approved';
    }).length;
    const allocationReport = result && <AllocationReport result={result} onClose={() => setResult(null)} onConfirmDownloaded={abilities.manage && lastMove ? () => move(lastMove.boxIds, lastMove.column, true, lastMove.recurring) : null} />;

    return (
        <AuthenticatedLayout
            header={
                <>
                    <PageHeader
                        eyebrow={showBranchSelector ? <><Link href="/reading-cycles" className="hover:underline">الفروع</Link> / {cycle.branchName}</> : 'توزيع القراءة القادمة'}
                        title={<>قراءة {cycle.readingDayName} <span dir="ltr" className="font-display">{cycle.readingDate}</span></>}
                        titleAddon={<StatusPill tone={stage.tone} label={stage.label} />}
                        meta={<>أسبوع القراءة <span dir="ltr">{cycle.weekStart}</span> — <span dir="ltr">{cycle.weekEnd}</span> · إتاحة المهام <span dir="ltr">{cycle.availableOn}</span>{cycle.readingDate !== cycle.weekEnd && ' · يوم القراءة مؤجل لهذه الدورة'}</>}
                    />
                    <div className="flex shrink-0 flex-wrap items-center gap-2">
                        <SecondaryButton disabled={busy} onClick={() => setSummaryOpen(true)}>ملخّص توزيع الفرع</SecondaryButton>
                        <PrintButton title={`توزيع القراءات — ${cycle.branchName}`} base="/reading-cycles/print" params={{ cycle: cycle.id, search, area_ids: subAreaIds, box_id: boxId, assignment: assignmentFilter, print_page: currentPage, per_page: perPage }} selectedIds={[...selected]} selectionPage="/reading-cycles/print" />
                        {abilities.review && (
                            <Link href={`/reading-cycles/${cycle.id}/submissions`} className={secondaryButtonClass}>
                                <Icon name="list" className="h-4 w-4" />
                                القراءات الميدانية
                                {reviewCount > 0 && <span className="rounded-full bg-amber-500 px-2 text-xs font-bold text-white">{formatNumber(reviewCount)}</span>}
                            </Link>
                        )}
                        {abilities.manage && (
                            <button type="button" onClick={() => setEditingDay(true)} className={secondaryButtonClass}>
                                <Icon name="calendar" className="h-4 w-4" />
                                يوم القراءة
                            </button>
                        )}
                        {abilities.approve && counters.draft > 0 && (
                            <PrimaryButton disabled={busy} onClick={() => setSummaryOpen(true)}>
                                <Icon name="check" className="h-4 w-4" />
                                مراجعة واعتماد ({formatNumber(counters.draft)})
                            </PrimaryButton>
                        )}
                    </div>
                </>
            }
        >
            <Head title={`توزيع القراءات — ${cycle.readingDate}`} />

            {!actionsOpen && allocationReport}
            {(errors.box_ids || errors.approve || errors.copy) && !result && <p className="mb-4 rounded-control bg-red-500/10 px-4 py-2 text-sm text-red-700">{errors.box_ids ?? errors.approve ?? errors.copy}</p>}

            <DistributionSummary boxes={visibleBoxes} />

            <div className="grid items-start gap-4 lg:grid-cols-[17rem_minmax(0,1fr)]">
                <DistributionAreas areas={areas} chosen={subAreaIds} onChange={(ids) => { setSubAreaIds(ids); setPage(1); }} disabled={busy}
                    onDistribute={abilities.manage || abilities.printSheets ? () => openActions(visibleBoxes) : null} distributionCount={visibleBoxes.length}
                    filter={assignmentFilter} counts={{ all: boards.length, assigned: counters.mobile + counters.paper, available: counters.available }} onFilterChange={(value) => { setAssignmentFilter(value); setPage(1); }} />
                <div className="flex min-w-0 flex-col gap-4">
                    {selectedBoxes.length > 0 && <section aria-label="الطبلونات المحددة" className="flex flex-wrap items-center justify-between gap-3 rounded-card border border-brand-500/30 bg-surface p-4">
                        <div><p role="status" className="text-sm text-gray-700"><b className="font-display text-lg text-brand-600">{formatNumber(selectedTotals.boxes)}</b> طبلون محدد · {formatNumber(selectedTotals.subscribers)} مشترك</p>{hiddenSelected > 0 && <p className="mt-1 text-xs text-amber-700">منها {formatNumber(hiddenSelected)} خارج النتائج الظاهرة.</p>}</div>
                        <div className="flex flex-wrap items-center gap-3"><button type="button" disabled={busy} onClick={() => setSelected(new Set())} className="text-xs font-semibold text-gray-500 hover:underline">إلغاء التحديد كله</button><PrimaryButton disabled={busy} onClick={() => setActionsOpen(true)}>{abilities.manage ? 'توزيع المحدد' : 'إجراء على المحدد'} ({formatNumber(selectedTotals.boxes)})</PrimaryButton></div>
                    </section>}
                    <div>
                        <DataTableToolbar search={search} onSearchChange={(value) => { setSearch(value); setPage(1); }} placeholder="ابحث باسم المنطقة أو الطبلون أو رقمه..." perPage={perPage} onPerPageChange={(size) => { setPerPage(size); setPage(1); }} total={visibleBoxes.length} printable={false}
                            filterMenu={<div className="flex w-full flex-wrap items-center justify-between gap-3 border-t border-gray-100 pt-3">
                                <div className="grid w-full min-w-0 flex-1 gap-3 sm:grid-cols-2">
                                    <div className="min-w-0">
                                        <label htmlFor="assignment-filter" className="mb-1.5 block text-xs font-semibold text-gray-600">عرض التوزيع</label>
                                        <select id="assignment-filter" value={assignmentFilter} disabled={busy} onChange={(event) => { setAssignmentFilter(event.target.value); setPage(1); }} className="block w-full text-sm">
                                            <option value="">كل حالات التوزيع</option>
                                            <option value="assigned">كل الطبلونات الموزعة</option>
                                            {columns.map((column) => <option key={column.key} value={column.key}>{column.key === 'available' ? 'غير موزع' : column.label}</option>)}
                                        </select>
                                    </div>
                                    <div className="min-w-0">
                                        <label htmlFor="distribution-box-filter" className="mb-1.5 block text-xs font-semibold text-gray-600">الطبلون</label>
                                        <SearchableSelect id="distribution-box-filter" value={boxId} options={boxOptions} disabled={busy} placeholder="كل الطبلونات" searchPlaceholder="ابحث باسم الطبلون أو رقمه..." emptyLabel="لا توجد طبلونات ضمن التصفية الحالية" onChange={(value) => { setBoxId(value); setPage(1); }} />
                                    </div>
                                    {(search || subAreaIds.length > 0 || assignmentFilter || boxId) && <button type="button" disabled={busy} onClick={() => { setSearch(''); setSubAreaIds([]); setAssignmentFilter(''); setBoxId(''); setPage(1); }} className="justify-self-start text-xs font-semibold text-gray-500 hover:underline sm:col-span-2">مسح التصفية</button>}
                                </div>
                                {(abilities.manage || abilities.printSheets) && <SecondaryButton disabled={busy || visibleBoxes.length === 0} onClick={() => toggleColumn(visibleBoxes)}>{allVisibleSelected ? 'إلغاء تحديد النتائج' : 'تحديد كل النتائج'} ({formatNumber(visibleBoxes.length)})</SecondaryButton>}
                            </div>}
                        />
                        <DistributionTable boxes={pageBoxes} allBoxes={visibleBoxes} readers={readers} selected={selected} canSelect={abilities.manage || abilities.printSheets} busy={busy} onToggle={toggle} onToggleGroup={toggleColumn} />
                        {totalPages > 1 && <nav aria-label="صفحات الطبلونات" className="mt-3 flex flex-wrap items-center justify-between gap-3 text-sm text-gray-500">
                            <span>الصفحة {formatNumber(currentPage)} من {formatNumber(totalPages)} · التحديد يشمل جميع صفحات النتائج</span>
                            <div className="flex gap-2"><SecondaryButton disabled={busy || currentPage === 1} onClick={() => setPage(currentPage - 1)}>السابق</SecondaryButton><SecondaryButton disabled={busy || currentPage === totalPages} onClick={() => setPage(currentPage + 1)}>التالي</SecondaryButton></div>
                        </nav>}
                    </div>

                    {remainingBoxes.length > 0 && abilities.manage && <div className="flex flex-wrap items-center justify-between gap-3 rounded-control border border-amber-500/20 bg-amber-500/5 px-4 py-3 text-sm text-gray-700">
                        <span>{formatNumber(remainingBoxes.length)} طبلون غير موزع ضمن النتائج الحالية.</span>
                        <button type="button" disabled={busy} onClick={() => openActions(remainingBoxes, 'paper')} className="font-semibold text-brand-600 hover:underline">تحديد المتبقي للكشوف الورقية</button>
                    </div>}

                </div>
            </div>

            {(abilities.printSheets || paperSheets.length > 0) && (
                <section className="mt-6 rounded-card border border-gray-100 bg-surface p-5 shadow-card" aria-labelledby="sheets-title">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 id="sheets-title" className="font-bold text-gray-900">الكشوف الورقية</h3>
                            <p className="text-xs text-gray-500">للقرّاء الذين لا يملكون هواتف: حدّد الطبلونات المخصصة للورق والمعتمدة، ثم أنشئ كشفًا للطباعة. تُدخل القراءات لاحقًا من الموقع.</p>
                        </div>
                        {abilities.printSheets && (
                            <PrimaryButton onClick={createSheet} disabled={busy || selectedPaperApproved === 0 || selectedPaperApproved > 200}>
                                <Icon name="printer" className="h-4 w-4" />
                                إنشاء كشف ({formatNumber(selectedPaperApproved)})
                            </PrimaryButton>
                        )}
                    </div>
                    {paperSheets.length > 0 && (
                        <ul className="mt-4 divide-y divide-gray-100 rounded-control border border-gray-100 text-sm">
                            {paperSheets.map((sheet) => (
                                <li key={sheet.id} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                                    <span>
                                        <b className="font-display" dir="ltr">{sheet.number}</b> · {formatNumber(sheet.boxes)} طبلون
                                    </span>
                                    <span className="text-xs text-gray-500">
                                        {sheet.printCount > 0 ? `طُبع ${formatNumber(sheet.printCount)} مرة · آخرها ${sheet.lastPrintedAt} (${sheet.lastPrintedBy})` : 'لم يُطبع بعد'}
                                    </span>
                                    {abilities.printSheets && <Link href={`/paper-sheets/${sheet.id}?print=1`} className="text-sm font-semibold text-brand-600 hover:underline">طباعة وتخصيص</Link>}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            )}

            {summaryOpen && <DistributionSummaryModal cycle={cycle} boards={boards} readers={readers} paperSheets={paperSheets} abilities={abilities} busy={busy} errors={errors} result={result} onClose={() => setSummaryOpen(false)} onApprove={() => approve({})} onPaper={showPaperSheets} onPreparePaper={() => openActions(boards.filter((box) => box.column === 'paper'), 'paper')} onRemaining={() => openActions(boards.filter((box) => box.column === 'available'), 'paper')} />}
            {actionsOpen && <DistributionActionModal boards={boards} selectedBoxes={selectedBoxes} readers={readers} abilities={abilities} busy={busy} errors={errors} target={target} onTargetChange={setTarget} recurring={recurring} onRecurringChange={setRecurring} hiddenSelected={hiddenSelected} onRemoveHidden={() => setSelected(new Set(selectedBoxes.filter((box) => visibleIds.has(box.id)).map((box) => box.id)))} onClose={() => setActionsOpen(false)} onAssign={assignSelected} onCreateSheet={createSheet} allocationReport={errors.box_ids ? allocationReport : null} />}
            {editingDay && <ReadingDayModal cycle={cycle} onClose={() => setEditingDay(false)} />}
        </AuthenticatedLayout>
    );
}
