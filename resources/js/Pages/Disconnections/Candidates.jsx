import { useEffect, useRef, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DataTableToolbar from '@/Components/DataTable/DataTableToolbar';
import DataTableFilterMenu from '@/Components/DataTable/DataTableFilterMenu';
import SortableTh from '@/Components/DataTable/SortableTh';
import StatusPill from '@/Components/DataTable/StatusPill';
import RowIdentity from '@/Components/DataTable/RowIdentity';
import Pagination from '@/Components/DataTable/Pagination';
import Modal from '@/Components/Modal';
import ChoiceChips from '@/Components/ChoiceChips';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import InputError from '@/Components/InputError';
import Icon from '@/Components/Icon';
import Balance from '@/Components/Balance';
import { useDataTable } from '@/hooks/useDataTable';
import ResultReport from './ResultReport';
import PageHeader from '@/Components/PageHeader';
import DateInput from '@/Components/DateInput';

/** The reason, note and date for the chosen subscribers, after saying how many. */
function AddModal({ list, selection, count, reasonOptions, today, onClose, onDone }) {
    const { errors } = usePage().props;
    const [reason, setReason] = useState('');
    const [note, setNote] = useState('');
    const [date, setDate] = useState(today);
    const [saving, setSaving] = useState(false);

    function submit(event) {
        event.preventDefault();
        setSaving(true);
        router.post(`/disconnection-lists/${list.id}/items`, { ...selection, reason, note, scheduled_for: date }, { preserveScroll: true, onSuccess: onDone, onError: (found) => found.ids && onDone(), onFinish: () => setSaving(false) });
    }

    return (
        <Modal show onClose={onClose} maxWidth="lg" centered>
            <form onSubmit={submit} role="dialog" aria-modal="true" aria-labelledby="add-title">
                <div className="flex items-start gap-4 px-7 pb-2 pt-7">
                    <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand-500/10 text-brand-600">
                        <Icon name="plus" strokeWidth={2} />
                    </span>
                    <div>
                        <h3 id="add-title" className="text-lg font-bold text-gray-900">إضافة إلى «{list.title}»</h3>
                        <p className="mt-1.5 text-sm text-gray-600">
                            <b className="text-gray-900">{count.toLocaleString('en')}</b> مشترك{selection.all ? ' (كل النتائج المطابقة للفلاتر في كل الصفحات)' : ' محدد'}. من لا يمكن إدراجه (مدرج في قائمة أخرى أو خدمته غير فعّالة) يُستثنى ويظهر في التقرير.
                        </p>
                    </div>
                </div>
                <div className="space-y-4 px-7 py-5">
                    <div>
                        <ChoiceChips options={reasonOptions} value={reason} onChange={setReason} label="سبب القطع" required name="reason" />
                        <InputError message={errors.reason} className="mt-1" />
                    </div>
                    <div>
                        <label htmlFor="add-note" className="mb-1.5 block text-sm font-semibold text-gray-700">ملاحظة {reason === 'other' ? '(يُفضّل توضيح السبب)' : '(اختياري)'}</label>
                        <textarea id="add-note" value={note} onChange={(event) => setNote(event.target.value)} rows={2} className="block w-full text-sm" maxLength={1000} />
                        <InputError message={errors.note} className="mt-1" />
                    </div>
                    <div>
                        <label htmlFor="add-date" className="mb-1.5 block text-sm font-semibold text-gray-700">موعد القطع المخطط</label>
                        <DateInput id="add-date" name="scheduled_for" min={today} value={date} onChange={setDate} required />
                        <InputError message={errors.scheduled_for} className="mt-1" />
                        <p className="mt-1 text-xs text-gray-500">الموعد للتخطيط فقط: لا يُقطع أحد ولا تُرسل رسائل تلقائيًا.</p>
                    </div>
                </div>
                <div className="flex justify-end gap-3 border-t border-gray-100 bg-gray-50 px-7 py-4">
                    <SecondaryButton onClick={onClose}>إلغاء</SecondaryButton>
                    <PrimaryButton type="submit" disabled={saving || !reason || !date}>{saving ? 'جارٍ الإضافة…' : `إضافة ${count.toLocaleString('en')} مشترك`}</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}

/**
 * The branch's active subscribers to pick from for a disconnection list,
 * found by name, area, meter box or what they owe. Debt helps find them but
 * never decides: anyone may be listed with a reason.
 */
export default function Candidates({ list, subscriptions, reasonOptions, today, filters, filterOptions }) {
    const { flash } = usePage();
    const { search, setSearch, sort, setPerPage, filterValues, setFilter, setFilters, clearFilters } = useDataTable(`/disconnection-lists/${list.id}/candidates`, filters);
    const [selectedIds, setSelectedIds] = useState(() => new Set());
    const [allMatching, setAllMatching] = useState(false);
    const [adding, setAdding] = useState(false);
    const [result, setResult] = useState(null);
    const headerCheckbox = useRef(null);
    const pageIds = subscriptions.data.filter((row) => !row.waitingOn).map((row) => row.id);
    const pageSelectedCount = pageIds.filter((id) => selectedIds.has(id)).length;
    const selectionCount = allMatching ? subscriptions.total : selectedIds.size;
    const selection = allMatching ? { all: true, search: filters.search, filter: filters.filter } : { ids: [...selectedIds] };
    const filtersKey = JSON.stringify([filters.search, filters.filter]);

    useEffect(() => {
        if (flash?.disconnectionResult) {
            setResult(flash.disconnectionResult);
        }
    }, [flash]);

    useEffect(() => {
        setSelectedIds(new Set());
        setAllMatching(false);
    }, [filtersKey]);

    useEffect(() => {
        if (headerCheckbox.current) {
            headerCheckbox.current.indeterminate = !allMatching && pageSelectedCount > 0 && pageSelectedCount < pageIds.length;
        }
    });

    function toggle(id) {
        setAllMatching(false);
        setSelectedIds((current) => {
            const next = new Set(current);
            next.has(id) ? next.delete(id) : next.add(id);
            return next;
        });
    }

    function togglePage() {
        setAllMatching(false);
        setSelectedIds((current) => {
            const next = new Set(current);
            const everyChosen = pageIds.every((id) => next.has(id));
            pageIds.forEach((id) => (everyChosen ? next.delete(id) : next.add(id)));
            return next;
        });
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    eyebrow={<><Link href="/disconnection-lists" className="hover:underline">قوائم القطع</Link> / <Link href={`/disconnection-lists/${list.id}`} className="hover:underline">{list.title}</Link></>}
                    title="إضافة مشتركين إلى القائمة"
                    info={`مشتركو ${list.branchName} الفعّالون. اختر واحدًا أو أكثر، أو كل النتائج المطابقة للفلاتر.`}
                />
            }
        >
            <Head title={`إضافة مشتركين — ${list.title}`} />

            {result && <ResultReport result={result} onClose={() => setResult(null)} />}
            {list.isClosed && <p className="mb-4 rounded-control bg-amber-500/10 px-4 py-3 text-sm text-amber-800">القائمة مغلقة؛ أعد فتحها لإضافة مشتركين.</p>}

            <DataTableToolbar
                search={search}
                onSearchChange={setSearch}
                placeholder="بحث بالاسم أو رقم الاشتراك أو الطبلون..."
                perPage={filters.per_page}
                onPerPageChange={setPerPage}
                total={subscriptions.total}
                printable={false}
                filterMenu={<DataTableFilterMenu tableKey="disconnection_candidates" groups={filterOptions} values={filterValues} onChange={setFilter} onChangeMany={setFilters} onClear={clearFilters} />}
            />

            {!allMatching && pageIds.length > 0 && pageSelectedCount === pageIds.length && subscriptions.total > pageIds.length && (
                <div role="status" className="flex flex-wrap items-center justify-center gap-2 border-x border-gray-100 bg-brand-500/5 px-4 py-2.5 text-sm text-gray-700">
                    تم تحديد {pageIds.length.toLocaleString('en')} مشترك في هذه الصفحة.
                    <button type="button" onClick={() => setAllMatching(true)} className="rounded font-semibold text-brand-600 hover:underline">
                        تحديد كل النتائج المطابقة ({subscriptions.total.toLocaleString('en')})
                    </button>
                </div>
            )}

            <div className="data-table-container">
                <table className="data-table w-full text-sm text-start" aria-label="المشتركون المتاحون للإضافة">
                    <thead>
                        <tr>
                            <th data-actions="" className="w-10">
                                <input ref={headerCheckbox} type="checkbox" checked={allMatching || (pageIds.length > 0 && pageSelectedCount === pageIds.length)} onChange={togglePage} aria-label="تحديد كل مشتركي هذه الصفحة" className="rounded border-gray-300 text-brand-600" />
                            </th>
                            <SortableTh column="display_name" label="المشترك" sortState={filters} onSort={sort} />
                            <th>الطبلون</th>
                            <th>منطقة 2</th>
                            <SortableTh column="balance" label="الرصيد" sortState={filters} onSort={sort} />
                            <th>قوائم القطع</th>
                        </tr>
                    </thead>
                    <tbody>
                        {subscriptions.data.length === 0 ? (
                            <tr>
                                <td colSpan={6} className="text-gray-500">لا توجد نتائج مطابقة.</td>
                            </tr>
                        ) : (
                            subscriptions.data.map((row) => (
                                <tr key={row.id} className={allMatching || selectedIds.has(row.id) ? 'bg-brand-50' : undefined}>
                                    <td>
                                        <input type="checkbox" disabled={Boolean(row.waitingOn)} checked={!row.waitingOn && (allMatching || selectedIds.has(row.id))} onChange={() => toggle(row.id)} aria-label={`تحديد ${row.name}`} className="rounded border-gray-300 text-brand-600 disabled:opacity-40" />
                                    </td>
                                    <td data-label="المشترك"><RowIdentity name={row.name} subtitle={row.accountNumber} subtitleDir="ltr" /></td>
                                    <td data-label="الطبلون" className="text-gray-600">{row.meterBoxNumber ? <span className="data-chip">{row.meterBoxNumber}</span> : '—'}</td>
                                    <td data-label="منطقة 2" className="text-gray-600">{row.subAreaName ?? '—'}</td>
                                    <td data-label="الرصيد" className="whitespace-nowrap font-semibold"><Balance value={row.balance} /></td>
                                    <td data-label="قوائم القطع">
                                        {row.waitingOn ? <StatusPill tone="amber" label={row.waitingOn.isThisList ? 'في هذه القائمة' : `في «${row.waitingOn.title}»`} /> : <span className="text-gray-400">—</span>}
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>

            <Pagination meta={subscriptions} filters={filters} baseUrl={`/disconnection-lists/${list.id}/candidates`} />

            {selectionCount > 0 && !list.isClosed && (
                <>
                    <div className="h-24" aria-hidden="true" />
                    <div role="region" aria-label="إضافة المحددين" className="fixed inset-x-0 bottom-4 z-40 mx-auto flex w-[calc(100%-2rem)] max-w-2xl flex-wrap items-center justify-between gap-2 rounded-2xl bg-graphite-gradient px-4 py-3 text-white shadow-lift">
                        <span className="text-sm" aria-live="polite">
                            <b className="font-display">{selectionCount.toLocaleString('en')}</b> {allMatching ? 'مشترك (كل النتائج المطابقة)' : 'مشترك محدد'}
                        </span>
                        <div className="flex items-center gap-2">
                            <button type="button" onClick={() => setAdding(true)} className="inline-flex items-center gap-1.5 rounded-control bg-white px-4 py-2 text-sm font-bold text-gray-900">
                                <Icon name="plus" className="h-4 w-4" />
                                إضافة إلى القائمة
                            </button>
                            <button type="button" onClick={() => { setSelectedIds(new Set()); setAllMatching(false); }} aria-label="إلغاء التحديد" className="flex h-9 w-9 items-center justify-center rounded-control text-white/80 hover:bg-white/10">
                                <Icon name="close" className="h-5 w-5" />
                            </button>
                        </div>
                    </div>
                </>
            )}

            {adding && (
                <AddModal
                    list={list}
                    selection={selection}
                    count={selectionCount}
                    reasonOptions={reasonOptions}
                    today={today}
                    onClose={() => setAdding(false)}
                    onDone={() => {
                        setAdding(false);
                        setSelectedIds(new Set());
                        setAllMatching(false);
                    }}
                />
            )}
        </AuthenticatedLayout>
    );
}
