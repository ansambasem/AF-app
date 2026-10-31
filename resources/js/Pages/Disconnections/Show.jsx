import { useEffect, useRef, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import AddButton from '@/Components/AddButton';
import DataTableToolbar from '@/Components/DataTable/DataTableToolbar';
import DataTableFilterMenu from '@/Components/DataTable/DataTableFilterMenu';
import SortableTh from '@/Components/DataTable/SortableTh';
import StatusPill from '@/Components/DataTable/StatusPill';
import RowIdentity from '@/Components/DataTable/RowIdentity';
import RowActionsMenu from '@/Components/DataTable/RowActionsMenu';
import Pagination from '@/Components/DataTable/Pagination';
import ActionsTh from '@/Components/DataTable/ActionsTh';
import Icon from '@/Components/Icon';
import Balance from '@/Components/Balance';
import { useDataTable } from '@/hooks/useDataTable';
import { printFieldsProps, printRowProps } from '@/lib/print';
import { formatMoney, formatNumber, formatDate } from '@/lib/format';
import { itemTone } from '@/lib/disconnections';
import ActionModal from './ActionModal';
import HistoryModal from './HistoryModal';
import ResultReport from './ResultReport';
import { secondaryButtonClass } from '@/Components/SecondaryButton';
import PageHeader from '@/Components/PageHeader';

const FOCUS_RING = 'focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white';

const PRINT_FIELDS = [
    { key: 'name', label: 'المشترك' },
    { key: 'accountNumber', label: 'رقم الاشتراك' },
    { key: 'meterBoxNumber', label: 'الطبلون' },
    { key: 'reasonLabel', label: 'السبب' },
    { key: 'scheduledFor', label: 'موعد القطع' },
    { key: 'balance', label: 'الرصيد' },
    { key: 'statusLabel', label: 'الحالة' },
];

function BarButton({ icon, label, onClick, className = '' }) {
    return (
        <button type="button" onClick={onClick} className={`inline-flex items-center gap-1.5 whitespace-nowrap rounded-control bg-white/10 px-3 py-2 text-sm font-semibold text-white transition hover:bg-white/20 ${FOCUS_RING} ${className}`}>
            <Icon name={icon} className="h-4 w-4" />
            {label}
        </button>
    );
}

function Stat({ label, value, tone = 'text-gray-900', hint }) {
    return (
        <div className="rounded-card border border-gray-100 bg-surface px-4 py-3 shadow-card">
            <p className="text-xs font-semibold text-gray-500">{label}</p>
            <p className={`mt-1 font-display text-2xl font-bold ${tone}`}>{value}</p>
            {hint && <p className="mt-0.5 text-xs text-gray-500">{hint}</p>}
        </div>
    );
}

/**
 * One disconnection list: its subscribers with their reason, date, balance
 * now and warnings. Any of them — one, the ticked ones, or every match of
 * the filters across pages — can be given a new date, warned, removed, or
 * confirmed as disconnected, each action listing exactly whom it reaches
 * first and reporting how it went for each.
 */
export default function Show({ list, items, summary, abilities, reasonOptions, channelOptions, smsDeliversMessages, warningTemplates, defaultWarning, placeholders, today, usualFee, filters, filterOptions, history }) {
    const { flash } = usePage();
    const { search, setSearch, sort, setPerPage, filterValues, setFilter, setFilters, clearFilters } = useDataTable(`/disconnection-lists/${list.id}`, filters);
    const [selectedIds, setSelectedIds] = useState(() => new Set());
    const [allMatching, setAllMatching] = useState(false);
    const [action, setAction] = useState(null);
    const [result, setResult] = useState(null);
    const [historyLoading, setHistoryLoading] = useState(null);
    const headerCheckbox = useRef(null);
    const pageIds = items.data.map((item) => item.id);
    const pageSelectedCount = pageIds.filter((id) => selectedIds.has(id)).length;
    const selectionCount = allMatching ? items.total : selectedIds.size;
    const selection = allMatching ? { all: true, search: filters.search, filter: filters.filter } : { ids: [...selectedIds] };
    const canSelect = abilities.manage || abilities.warn || abilities.execute;
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

    function clearSelection() {
        setSelectedIds(new Set());
        setAllMatching(false);
    }

    /** An action on one row only. */
    function single(item, name) {
        setAllMatching(false);
        setSelectedIds(new Set([item.id]));
        setAction(name);
    }

    function openHistory(item) {
        setHistoryLoading(item.id);
        router.reload({ only: ['history'], data: { history: item.id }, onFinish: () => setHistoryLoading(null) });
    }

    function closeHistory() {
        setHistoryLoading(null);
        const url = new URL(window.location.href);
        url.searchParams.delete('history');
        router.replace({ url: `${url.pathname}${url.search}`, props: (props) => ({ ...props, history: null }), preserveScroll: true, preserveState: true });
    }

    function toggleClosed() {
        router.patch(`/disconnection-lists/${list.id}`, { closed: !list.isClosed }, { preserveScroll: true });
    }

    function rowMenu(item) {
        const waiting = item.status === 'scheduled';

        return {
            title: item.name,
            subtitle: item.accountNumber,
            groups: [
                {
                    label: 'القائمة',
                    items: [
                        { label: 'سجل المشترك في القائمة', icon: 'history', tone: 'graphite', onSelect: () => openHistory(item) },
                        ...(abilities.manage && waiting ? [{ label: 'تغيير موعد القطع', icon: 'calendar', tone: 'blue', onSelect: () => single(item, 'reschedule') }] : []),
                        ...(abilities.warn && waiting ? [{ label: 'إرسال إنذار', icon: 'send', tone: 'sky', onSelect: () => single(item, 'warn') }] : []),
                        ...(abilities.execute && waiting ? [{ label: 'تأكيد تنفيذ القطع', icon: 'power', tone: 'red', onSelect: () => single(item, 'confirm') }] : []),
                        ...(abilities.manage && waiting ? [{ label: 'إزالة من القائمة', icon: 'undo', tone: 'amber', onSelect: () => single(item, 'remove') }] : []),
                    ],
                },
            ],
        };
    }

    return (
        <AuthenticatedLayout
            header={
                <>
                    <PageHeader
                        eyebrow={<><Link href="/disconnection-lists" className="hover:underline">قوائم القطع</Link> / {list.branchName}</>}
                        title={list.title}
                        titleAddon={list.isClosed && <StatusPill tone="gray" label="مغلقة" />}
                        meta={list.notes}
                    />
                    <div className="flex shrink-0 flex-wrap items-center gap-2">
                        {abilities.manageList && (
                            <button type="button" onClick={toggleClosed} className={secondaryButtonClass}>
                                <Icon name={list.isClosed ? 'unlock' : 'lock'} className="h-4 w-4" />
                                {list.isClosed ? 'إعادة فتح القائمة' : 'إغلاق القائمة'}
                            </button>
                        )}
                        {abilities.manage && <AddButton href={`/disconnection-lists/${list.id}/candidates`}>إضافة مشتركين</AddButton>}
                    </div>
                </>
            }
        >
            <Head title={list.title} />

            {result && <ResultReport result={result} onClose={() => setResult(null)} />}

            <div className="mb-5 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                <Stat label="بانتظار القطع" value={formatNumber(summary.scheduled)} tone="text-amber-700 dark:text-amber-400" hint={`موعدهم اليوم: ${formatNumber(summary.dueToday)}`} />
                <Stat label="متأخر — بانتظار إجراء" value={formatNumber(summary.overdue)} tone="text-red-700 dark:text-red-400" hint="لا يُقطع أحد تلقائيًا" />
                <Stat label="تم قطعهم" value={formatNumber(summary.disconnected)} />
                <Stat label="أُزيلوا" value={formatNumber(summary.removed)} />
                <Stat label="إنذارات · تمديدات" value={`${formatNumber(summary.warnings)} · ${formatNumber(summary.extensions)}`} />
                <Stat label="رسوم القطع المحمّلة" value={`${formatMoney(summary.feesTotal)} ₪`} />
            </div>

            <div className="mb-5 flex flex-wrap gap-2 text-xs">
                {summary.reasons.map((reason) => (
                    <button key={reason.value} type="button" onClick={() => setFilter('reason', filterValues.reason === reason.value ? '' : reason.value)} aria-pressed={filterValues.reason === reason.value} className={`rounded-full border px-3 py-1 font-semibold ${filterValues.reason === reason.value ? 'border-gray-900 bg-gray-900 text-white' : 'border-gray-200 text-gray-600 hover:bg-gray-50'}`}>
                        {reason.label} <b className="font-display">{formatNumber(reason.count)}</b>
                    </button>
                ))}
            </div>

            <DataTableToolbar
                search={search}
                onSearchChange={setSearch}
                placeholder="بحث بالاسم أو رقم الاشتراك أو الطبلون..."
                perPage={filters.per_page}
                onPerPageChange={setPerPage}
                total={items.total}
                filterMenu={
                    <div className="flex w-full flex-wrap items-center gap-2">
                        <DataTableFilterMenu tableKey="disconnection_items" groups={filterOptions} values={filterValues} onChange={setFilter} onChangeMany={setFilters} onClear={clearFilters} />
                    </div>
                }
            />

            {canSelect && !allMatching && pageIds.length > 0 && pageSelectedCount === pageIds.length && items.total > pageIds.length && (
                <div role="status" className="flex flex-wrap items-center justify-center gap-2 border-x border-gray-100 bg-brand-500/5 px-4 py-2.5 text-sm text-gray-700">
                    تم تحديد {pageIds.length.toLocaleString('en')} مشترك في هذه الصفحة.
                    <button type="button" onClick={() => setAllMatching(true)} className="rounded font-semibold text-brand-600 hover:underline">
                        تحديد كل النتائج المطابقة ({items.total.toLocaleString('en')})
                    </button>
                </div>
            )}

            <div className="data-table-container">
                <table className="data-table w-full text-sm text-start" aria-label="مشتركو القائمة" {...printFieldsProps(PRINT_FIELDS)}>
                    <thead>
                        <tr>
                            {canSelect && (
                                <th data-actions="" className="w-10">
                                    <input ref={headerCheckbox} type="checkbox" checked={allMatching || (pageIds.length > 0 && pageSelectedCount === pageIds.length)} onChange={togglePage} aria-label="تحديد كل مشتركي هذه الصفحة" className="rounded border-gray-300 text-brand-600" />
                                </th>
                            )}
                            <th>المشترك</th>
                            <th>السبب</th>
                            <SortableTh column="scheduled_for" label="موعد القطع" sortState={filters} onSort={sort} />
                            <SortableTh column="balance" label="الرصيد" sortState={filters} onSort={sort} />
                            <SortableTh column="warnings_count" label="الإنذارات" sortState={filters} onSort={sort} />
                            <SortableTh column="status" label="الحالة" sortState={filters} onSort={sort} />
                            <ActionsTh />
                        </tr>
                    </thead>
                    <tbody>
                        {items.data.length === 0 ? (
                            <tr>
                                <td colSpan={canSelect ? 8 : 7} className="text-gray-500">لا يوجد مشتركون مطابقون في هذه القائمة.</td>
                            </tr>
                        ) : (
                            items.data.map((item) => (
                                <tr key={item.id} className={allMatching || selectedIds.has(item.id) ? 'bg-brand-50' : undefined} {...printRowProps(Object.fromEntries(PRINT_FIELDS.map((field) => [field.key, item[field.key]])))}>
                                    {canSelect && (
                                        <td>
                                            <input type="checkbox" checked={allMatching || selectedIds.has(item.id)} onChange={() => toggle(item.id)} aria-label={`تحديد ${item.name}`} className="rounded border-gray-300 text-brand-600" />
                                        </td>
                                    )}
                                    <td data-label="المشترك">
                                        <RowIdentity name={item.name} subtitle={[item.accountNumber, item.meterBoxNumber && `طبلون ${item.meterBoxNumber}`].filter(Boolean).join(' · ')} />
                                    </td>
                                    <td data-label="السبب" className="text-gray-700">
                                        {item.reasonLabel}
                                        {item.note && <span className="block max-w-[16rem] truncate text-xs text-gray-500" title={item.note}>{item.note}</span>}
                                    </td>
                                    <td data-label="موعد القطع" className="whitespace-nowrap">
                                        <span dir="ltr" className={item.isOverdue ? 'font-semibold text-red-700 dark:text-red-400' : 'text-gray-700'}>{formatDate(item.scheduledFor)}</span>
                                        {item.extensionsCount > 0 && <span className="block text-xs text-gray-500">مُدّد {formatNumber(item.extensionsCount)} مرة</span>}
                                    </td>
                                    <td data-label="الرصيد" className="whitespace-nowrap">
                                        <Balance value={item.balance} className="font-bold" />
                                        {item.isPaid && item.status === 'scheduled' ? (
                                            <span className="block"><StatusPill tone="green" label="سدّد — تحديث الرصيد" /></span>
                                        ) : item.balanceReduced ? (
                                            <span className="block text-xs text-gray-500">كان <Balance value={item.balanceWhenAdded} colored={false} /></span>
                                        ) : null}
                                    </td>
                                    <td data-label="الإنذارات" className="text-gray-700">
                                        {item.warningsCount > 0 ? (
                                            <>
                                                <b className="font-display">{formatNumber(item.warningsCount)}</b>
                                                <span className="block text-xs text-gray-500" dir="ltr">{item.lastWarnedAt}</span>
                                            </>
                                        ) : (
                                            <span className="text-gray-400">—</span>
                                        )}
                                    </td>
                                    <td data-label="الحالة">
                                        <StatusPill tone={itemTone(item)} label={item.isOverdue ? 'متأخر — بانتظار إجراء' : item.statusLabel} />
                                        {item.status === 'disconnected' && <span className="block text-xs text-gray-500">{item.disconnectedBy} · {formatMoney(item.fee)} ₪</span>}
                                        {item.status === 'removed' && item.removalReason && <span className="block text-xs text-gray-500">{item.removalReason}</span>}
                                        {item.status === 'scheduled' && !item.serviceIsActive && <span className="block text-xs text-amber-700">الخدمة: {item.serviceStatusLabel}</span>}
                                    </td>
                                    <td data-actions="" className="text-end">
                                        <RowActionsMenu onView={() => openHistory(item)} menu={rowMenu(item)} />
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>

            <Pagination meta={items} filters={filters} baseUrl={`/disconnection-lists/${list.id}`} />

            {canSelect && selectionCount > 0 && !action && (
                <>
                    <div className="h-24" aria-hidden="true" />
                    <div role="region" aria-label="إجراءات على المشتركين المحددين" className="fixed inset-x-0 bottom-4 z-40 mx-auto flex w-[calc(100%-2rem)] max-w-4xl flex-wrap items-center gap-2 rounded-2xl bg-graphite-gradient px-4 py-3 text-white shadow-lift ring-1 ring-white/10">
                        <span className="me-2 flex items-center gap-2 text-sm" aria-live="polite">
                            <span className="flex h-7 min-w-[1.75rem] items-center justify-center rounded-full bg-white px-2 font-display text-sm font-bold text-gray-900">{selectionCount.toLocaleString('en')}</span>
                            {allMatching ? 'مشترك (كل النتائج المطابقة)' : 'مشترك محدد'}
                        </span>
                        <div className="flex flex-1 flex-wrap items-center gap-2">
                            {abilities.manage && <BarButton icon="calendar" label="تمديد / تغيير الموعد" onClick={() => setAction('reschedule')} />}
                            {abilities.warn && <BarButton icon="send" label="إنذار" onClick={() => setAction('warn')} />}
                            {abilities.execute && <BarButton icon="power" label="تأكيد القطع" onClick={() => setAction('confirm')} className="bg-red-700/80 hover:bg-red-700" />}
                            {abilities.manage && <BarButton icon="undo" label="إزالة" onClick={() => setAction('remove')} />}
                        </div>
                        <button type="button" onClick={clearSelection} aria-label="إلغاء التحديد" className={`flex h-9 w-9 items-center justify-center rounded-control text-white/80 hover:bg-white/10 ${FOCUS_RING}`}>
                            <Icon name="close" className="h-5 w-5" />
                        </button>
                    </div>
                </>
            )}

            {action && (
                <ActionModal
                    action={action}
                    list={list}
                    selection={selection}
                    count={selectionCount}
                    reasonOptions={reasonOptions}
                    channelOptions={channelOptions}
                    smsDeliversMessages={smsDeliversMessages}
                    warningTemplates={warningTemplates}
                    defaultWarning={defaultWarning}
                    placeholders={placeholders}
                    today={today}
                    usualFee={usualFee}
                    canChangeFee={abilities.changeFee}
                    onClose={() => setAction(null)}
                    onDone={() => {
                        setAction(null);
                        clearSelection();
                    }}
                />
            )}

            {(historyLoading || history) && <HistoryModal history={history} loading={historyLoading !== null && history?.id !== historyLoading} reasonOptions={reasonOptions} onClose={closeHistory} />}
        </AuthenticatedLayout>
    );
}
