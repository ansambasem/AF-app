import { Fragment } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DataTableFilterMenu from '@/Components/DataTable/DataTableFilterMenu';
import DataTableToolbar from '@/Components/DataTable/DataTableToolbar';
import Pagination from '@/Components/DataTable/Pagination';
import RowIdentity from '@/Components/DataTable/RowIdentity';
import Icon from '@/Components/Icon';
import KpiTile from '@/Components/KpiTile';
import PeriodTabs, { periodCaption } from '@/Components/PeriodTabs';
import { useDataTable } from '@/hooks/useDataTable';
import { useRowClick } from '@/hooks/useRowClick';
import { useStatementWindow } from '@/hooks/useStatementWindow';
import { formatClock, formatDayLabel, formatNumber } from '@/lib/format';
import StatementModal from '@/Pages/Subscriptions/StatementModal';
import PageHeader from '@/Components/PageHeader';

const STATUS_DOTS = { active: 'green', suspended: 'amber', disconnected: 'gray' };

/** Each kind of change's badge: blue to edit, amber to correct, burgundy to delete for good. */
const KIND_STYLES = {
    amendment: { icon: 'pencil', className: 'border-blue-500/25 bg-blue-500/10 text-blue-700 dark:text-blue-500' },
    cancellation: { icon: 'close', className: 'border-gray-200 bg-gray-50 text-gray-700' },
    correction: { icon: 'repeat', className: 'border-amber-500/25 bg-amber-500/10 text-amber-700 dark:text-amber-400' },
    refund: { icon: 'undo', className: 'border-emerald-500/25 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400' },
    deletion: { icon: 'trash', className: 'border-brand-500/25 bg-brand-500/10 text-brand-700' },
};

function Dash() {
    return <span className="text-gray-300">—</span>;
}

function valueText(value) {
    return value === null || value === '' ? '—' : value;
}

/** A day's header row: its date, with "اليوم" on today. */
function DayHeader({ day, isToday }) {
    return (
        <tr className="data-table-group">
            <td colSpan={6}>
                <span className="flex items-center gap-2 font-bold text-gray-900">
                    {formatDayLabel(day)}
                    {isToday && <span className="rounded-full bg-brand-500 px-2 py-0.5 text-[12px] font-bold text-white">اليوم</span>}
                </span>
            </td>
        </tr>
    );
}

/** What the change did: the fields edited (old → new), and why. */
function ChangeDetails({ event }) {
    return (
        <div className="grid min-w-[220px] gap-1 whitespace-normal text-xs text-gray-700">
            {event.changes.map((change) => (
                <span key={change.label}>
                    <b className="font-semibold text-gray-900">{change.label}:</b> <bdi>{valueText(change.from)}</bdi> <span dir="ltr">→</span>{' '}
                    <bdi>{valueText(change.to)}</bdi>
                </span>
            ))}
            {event.reason && (
                <span>
                    <b className="font-semibold text-gray-900">السبب:</b> {event.reason}
                </span>
            )}
            {event.notes && <span className="text-gray-500">{event.notes}</span>}
            {event.changes.length === 0 && !event.reason && !event.notes && <Dash />}
        </div>
    );
}

/**
 * The audit log: every change made to the subscriptions' account lines after
 * they were recorded — edits, cancellations, corrections, refunds and
 * permanent deletions — newest first and grouped by day, with who made it
 * and why. The figures follow the period, the search and the filters.
 */
export default function Index({ events, period, counts, today, scopeLabel, filters, filterOptions, statement }) {
    const { can } = usePage().props;
    const { search, setSearch, setPerPage, filterValues, setFilter, setFilters, clearFilters } = useDataTable('/transaction-audit', filters, { period });
    const rowClick = useRowClick();
    const statementWindow = useStatementWindow(statement);
    const caption = periodCaption(period);
    const total = Object.values(counts).reduce((sum, count) => sum + count, 0);

    function changePeriod(next) {
        router.get(
            '/transaction-audit',
            { search, per_page: filters.per_page, filter: filterValues, period: next },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    /** A row's subscription, in the shape the statement window's header reads. */
    function openStatement(subscription) {
        statementWindow.open({
            id: subscription.id,
            fullName: subscription.name,
            accountNumber: subscription.accountNumber,
            status: subscription.status,
            statusLabel: subscription.statusLabel,
            branchName: subscription.branchName,
        });
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    eyebrow={scopeLabel}
                    title="سجل التدقيق"
                    info="كل تعديل أو إلغاء أو حذف على حركات المشتركين: من قام به، ومتى، ولماذا."
                    actions={<PeriodTabs period={period} onChange={changePeriod} />}
                />
            }
        >
            <Head title="سجل التدقيق" />

            <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                <KpiTile
                    hero
                    label={`إجراءات على الحركات · ${caption}`}
                    value={formatNumber(total)}
                    unit="إجراء"
                    hint={`منها ${formatNumber(counts.refund)} إرجاع`}
                />
                <KpiTile icon="pencil" label="تعديلات" value={formatNumber(counts.amendment)} hint="بيانات أو مبالغ عُدّلت في مكانها" />
                <KpiTile
                    icon="repeat"
                    label="إلغاءات وتصحيحات"
                    value={formatNumber(counts.cancellation + counts.correction)}
                    hint={`${formatNumber(counts.correction)} تصحيح · ${formatNumber(counts.cancellation)} إلغاء`}
                />
                <KpiTile icon="trash" label="حذف نهائي" value={formatNumber(counts.deletion)} hint="حركات أُزيلت من الكشف" />
            </div>

            <div className="mt-5">
                <DataTableToolbar
                    search={search}
                    onSearchChange={setSearch}
                    placeholder="بحث باسم المشترك أو رقم الحساب أو الطبلون..."
                    perPage={filters.per_page}
                    onPerPageChange={setPerPage}
                    total={events.total}
                    filterMenu={
                        <DataTableFilterMenu
                            tableKey="transaction-audit"
                            groups={filterOptions}
                            values={filterValues}
                            onChange={setFilter}
                            onChangeMany={setFilters}
                            onClear={clearFilters}
                        />
                    }
                />

                <div className="data-table-container">
                    <table className="data-table w-full text-start text-sm">
                        <thead>
                            <tr>
                                <th>الوقت</th>
                                <th>الإجراء</th>
                                <th>المشترك</th>
                                <th>الحركة</th>
                                <th>التفاصيل</th>
                                <th>بواسطة</th>
                            </tr>
                        </thead>
                        <tbody>
                            {events.data.length === 0 ? (
                                <tr>
                                    <td colSpan={6}>لا توجد إجراءات مطابقة في هذه الفترة.</td>
                                </tr>
                            ) : (
                                events.data.map((event, index) => {
                                    const style = KIND_STYLES[event.kind];

                                    return (
                                        <Fragment key={event.key}>
                                            {event.day !== events.data[index - 1]?.day && <DayHeader day={event.day} isToday={event.day === today} />}
                                            <tr {...rowClick(can?.viewSubscriptions && event.subscription ? () => openStatement(event.subscription) : null)}>
                                                <td className="whitespace-nowrap">
                                                    <span className="inline-flex items-center gap-1.5 font-semibold text-gray-900">
                                                        <Icon name="clock" className="h-4 w-4 text-gray-400" />
                                                        {formatClock(event.time)}
                                                    </span>
                                                </td>
                                                <td>
                                                    <span
                                                        className={`inline-flex items-center gap-1 whitespace-nowrap rounded-full border px-2.5 py-0.5 text-xs font-semibold ${style.className}`}
                                                    >
                                                        <Icon name={style.icon} className="h-3.5 w-3.5" />
                                                        {event.kindLabel}
                                                    </span>
                                                    {event.kindNote && <span className="mt-1 block text-xs text-gray-500">{event.kindNote}</span>}
                                                </td>
                                                <td>
                                                    {event.subscription ? (
                                                        <RowIdentity
                                                            name={event.subscription.name}
                                                            subtitle={`${event.subscription.accountNumber} · ${event.subscription.branchName}`}
                                                            status={STATUS_DOTS[event.subscription.status]}
                                                        />
                                                    ) : (
                                                        <span className="text-gray-500">مشترك محذوف</span>
                                                    )}
                                                </td>
                                                <td>
                                                    <div className="grid gap-1">
                                                        {event.lines.map((line, lineIndex) => (
                                                            <span key={lineIndex} className="whitespace-nowrap">
                                                                <span className="font-medium text-gray-900">{line.label}</span>{' '}
                                                                <b className="font-display font-bold text-gray-900">{line.amount}</b>{' '}
                                                                <span className="text-xs text-gray-500">₪</span>
                                                                {line.voucherNumber && (
                                                                    <span className="text-xs text-gray-500">
                                                                        {' '}
                                                                        · سند <bdi dir="ltr">{line.voucherNumber}</bdi>
                                                                    </span>
                                                                )}
                                                            </span>
                                                        ))}
                                                    </div>
                                                </td>
                                                <td>
                                                    <ChangeDetails event={event} />
                                                </td>
                                                <td className="text-gray-600">{event.userName ?? 'مستخدم محذوف'}</td>
                                            </tr>
                                        </Fragment>
                                    );
                                })
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination meta={events} filters={filters} baseUrl="/transaction-audit" extraParams={{ period }} />
            </div>

            {statementWindow.subscription && (
                <StatementModal
                    key={statementWindow.subscription.id}
                    subscription={statementWindow.subscription}
                    statement={statementWindow.statement}
                    initialForm={statementWindow.form}
                    onSwitch={(header) => statementWindow.open(header)}
                    onClose={statementWindow.close}
                />
            )}
        </AuthenticatedLayout>
    );
}
