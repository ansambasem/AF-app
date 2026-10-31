import { Link, router, usePage } from '@inertiajs/react';
import Balance from '@/Components/Balance';
import TableExportActions from '@/Components/DataTable/TableExportActions';
import DataTableToolbar from '@/Components/DataTable/DataTableToolbar';
import DataTableFilterMenu from '@/Components/DataTable/DataTableFilterMenu';
import SortableTh from '@/Components/DataTable/SortableTh';
import Pagination from '@/Components/DataTable/Pagination';
import { useDataTable } from '@/hooks/useDataTable';
import Icon from '@/Components/Icon';
import InfoTip from '@/Components/InfoTip';
import KpiTile from '@/Components/KpiTile';
import SecondaryButton from '@/Components/SecondaryButton';
import BlueActionButton from '@/Components/BlueActionButton';
import RowActionsMenu from '@/Components/DataTable/RowActionsMenu';
import { secondaryButtonClass } from '@/Components/SecondaryButton';
import { reportMoney, shortDate } from '@/lib/reportFormatting';
import { printUrl } from '@/lib/print';

import { boxColumns, debtColumns, detailExcelPart, statementColumns } from '@/lib/reportDetailParts';
import { formatDate } from '@/lib/format';

const isAmount = (column) => ['money', 'decimal'].includes(column.type);
const reportHelp = {
    statement: 'الموجب مستحق للشركة، والسالب لصالح المشترك. مبالغ عليه وله موجبة. الرصيد يتبع ترتيب الحركات التاريخي حتى عند التصفية. الحركة الملغاة وقيدها العكسي داخل الفترة يظهران في الكشف ولا يدخلان في مجموع عليه وله.',
    debts: 'عمر الدين من تاريخ التحميل، والدفعات تسدّد الأقدم أولًا. الأرصدة كما في نهاية الفترة وتشمل الحركات السابقة.',
    boxes: 'مبالغ مسجلة بعملة قيد الصندوق، والعملات الأخرى تُحوّل بسعر الصرف وقت التسجيل. العملة الأصلية في تقرير التحصيل. تسليم المحصّل لا يُعد تحصيلًا جديدًا.',
};

/** Every row matching the table's search and filters, fetched only when exporting; the screen holds one page. */
function loadExportRows() {
    return new Promise((resolve, reject) => router.reload({
        only: ['detailExport'],
        onSuccess: (page) => resolve(page.props.detailExport ?? []),
        onError: () => reject(new Error('تعذر تحميل صفوف التقرير للتصدير.')),
        onCancel: () => reject(new Error('أُلغي تحميل صفوف التقرير للتصدير.')),
    }));
}

function Actions({ title, section, columns, exportRows, identity = '' }) {
    const { canExport, filters, reportStatement, scopeLabel, detailTable } = usePage().props;
    const params = new URLSearchParams({ ...filters, section, search: detailTable?.state.search ?? '', sort: detailTable?.state.sort ?? '', direction: detailTable?.state.direction ?? '' });
    Object.entries(detailTable?.state.filter ?? {}).forEach(([key, value]) => value && params.set(`filter[${key}]`, value));
    if (section === 'statement' && reportStatement) params.set('subscription', reportStatement.subscription.id);
    return <div className="flex flex-wrap items-center gap-2">
        <InfoTip label="معلومة عن التقرير">{reportHelp[section]}</InfoTip>
        <TableExportActions title={title} showExcel={canExport} excelProps={{ storageKey: `reports:${section}`, templateKind: 'reports', loadParts: async () => { const rows = await loadExportRows(); return [detailExcelPart(section, title, columns, exportRows ? exportRows(rows) : rows)]; }, meta: { lines: [scopeLabel, identity, detailTable?.active ? 'الحركات المطابقة للبحث والفلاتر؛ الأرصدة تاريخية' : '', `${filters.from} – ${filters.to}`].filter(Boolean), criteria: [['الفرع', scopeLabel], ...(identity ? [['الحساب', identity]] : []), ['من', filters.from], ['إلى', filters.to]] } }} printHref={printUrl(title, `/reports?${params}`)} />
    </div>;
}

function ReportTable({ columns, title, section, nameCell, empty, exportRows }) {
    const { detailTable: model, filters, reportStatement } = usePage().props;
    const controls = useDataTable('/reports', model.state, { ...filters, section, subscription: reportStatement?.subscription.id });
    const size = model.state.per_page;
    const rows = model.rows;
    function renderValue(row, column) {
        if (nameCell && column.key === 'name') return nameCell(row);
        if (column.balance) return <Balance value={row[column.key]} colored={false} unit="" />;
        if (isAmount(column)) return Number(row[column.key]) === 0 ? <span className="text-gray-400">—</span> : <bdi dir="ltr">{reportMoney(Math.abs(Number(row[column.key])))}</bdi>;
        if (column.type === 'int') return <bdi dir="ltr">{row[column.key]}</bdi>;
        if (column.type === 'date') return row[column.key] ? <bdi dir="ltr">{formatDate(row[column.key])}</bdi> : '—';
        return row[column.key] || '—';
    }
    return <section data-excel-source="custom" aria-label={title}>
        <DataTableToolbar
            search={controls.search}
            onSearchChange={controls.setSearch}
            placeholder={section === 'statement' ? 'بحث في البيان أو رقم السند أو المبلغ...' : section === 'debts' ? 'بحث بالمشترك أو رقم الحساب...' : 'بحث برقم الصندوق أو المحصّل...'}
            perPage={size} onPerPageChange={controls.setPerPage} total={model.total}
            actions={<Actions title={title} section={section} columns={columns} exportRows={exportRows} identity={reportStatement ? `${reportStatement.subscription.name} · ${reportStatement.subscription.accountNumber}` : ''} />}
            filterMenu={<DataTableFilterMenu tableKey={`reports:${section}`} groups={model.options} values={controls.filterValues} onChange={controls.setFilter} onChangeMany={controls.setFilters} onClear={controls.clearFilters} />}
        />
        <div className="data-table-container">
            <table aria-label={title} className="data-table reports-detail-table w-full text-start">
                <thead><tr>{columns.map((column) => <SortableTh key={column.key} column={column.key} label={column.label} sortState={model.state} onSort={controls.sort} className={isAmount(column) || column.type === 'int' ? '!text-right' : ''} />)}</tr></thead>
                <tbody>{rows.length ? rows.map((row, index) => <tr key={row.id ?? index}>{columns.map((column) => <td key={column.key} data-label={column.label} dir={isAmount(column) || column.type === 'int' ? 'ltr' : undefined} className={isAmount(column) || column.type === 'int' ? 'font-display tabular-nums' : ''}>{renderValue(row, column)}</td>)}</tr>) : <tr><td colSpan={columns.length}><div role="status" className="mx-auto flex max-w-lg flex-col items-center gap-3 py-4"><Icon name="list" className="h-8 w-8 text-gray-400" /><b className="text-base text-gray-700">{model.active ? 'لا توجد حركات تطابق البحث والفلاتر' : empty?.title ?? 'لا توجد سجلات خلال الفترة المختارة'}</b><p className="text-sm leading-7 text-gray-500">{model.active ? 'جرّب تعديل البحث أو إزالة الفلاتر لعرض السجلات المتاحة.' : empty?.description ?? 'اختر فترة أخرى أو فرعًا آخر من أعلى الصفحة.'}</p>{model.active ? <button type="button" className={secondaryButtonClass} onClick={() => { controls.setSearch(''); controls.clearFilters(); }}>مسح البحث والفلاتر</button> : empty?.action}</div></td></tr>}</tbody>
            </table>
        </div>
        {model.total > 0 && Object.keys(model.totals).length > 0 && <div className="data-table-totals flex flex-wrap items-center gap-x-8 gap-y-3"><b className="text-sm">{model.active ? 'إجمالي الحركات المطابقة' : 'إجمالي النتائج'}</b>{columns.filter((column) => model.totals[column.key] != null).map((column) => <span key={column.key} className="text-sm text-gray-500">{column.label} <bdi dir="ltr" className="ms-2 font-display font-bold text-gray-900">{reportMoney(model.totals[column.key])}</bdi></span>)}</div>}
        <Pagination meta={model.meta} onPageChange={controls.setPage} />
    </section>;
}

function TableReport({ section, ...props }) {
    const { detailTable, filters, reportStatement } = usePage().props;
    if (!detailTable) return null;
    return <ReportTable key={`${section}:${filters.branch}:${filters.from}:${filters.to}:${reportStatement?.subscription.id ?? ""}`} section={section} {...props} />;
}

export function DebtReport({ data, visit }) {
    const { canViewStatements } = usePage().props;
    if (!data) return null;
    const footer = Object.fromEntries(data.buckets.map((bucket) => [bucket.key, bucket.total]));
    footer.balance = data.total;
    return <div className="space-y-5">
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <KpiTile hero label="إجمالي ديون المشتركين" value={reportMoney(data.total)} unit="₪" hint={`كما في ${shortDate(data.asOf, true)}`} />
            <KpiTile label="المشتركون المدينون" value={data.count} />
            <KpiTile label="أكثر من 90 يومًا" value={reportMoney(footer.older)} unit="₪" />
            <KpiTile label="أرصدة لصالح المشتركين" value={<bdi dir="ltr">{reportMoney(data.credit)}</bdi>} unit="₪" hint={`${data.creditCount} حساب`} />
        </div>
        <TableReport section="debts" title="ديون المشتركين" columns={debtColumns} nameCell={(row) => canViewStatements ? <button type="button" className="font-semibold text-brand-600 underline-offset-4 hover:underline" onClick={() => visit({ section: 'statement', subscription: row.id, page: undefined })}>{row.name}</button> : row.name} />
    </div>;
}

const boxStatusLabels = { 'مفتوح': 'في صناديق مفتوحة', 'مغلق': 'في صناديق مغلقة لم يؤكد الفرع استلامها', 'مستلم': 'أكّد الفرع استلامها' };

export function BoxesReport({ data }) {
    if (!data) return null;
    return <div className="space-y-5">
        <section aria-label="الأموال حسب حالة الصندوق" className="grid gap-4 sm:grid-cols-3">
            {data.byStatus.map((group) => {
                const [main, ...others] = group.currencies;
                return <KpiTile key={group.status} label={boxStatusLabels[group.status]} value={<bdi dir="ltr">{reportMoney(main?.amount ?? 0)}</bdi>} unit={main && main.currency !== 'ILS' ? main.currency : 'شيكل'} hint={[`${group.boxes} صندوق`, ...others.map((total) => `${reportMoney(total.amount)} ${total.currency}`)].join(' · ')} />;
            })}
        </section>
        <TableReport section="boxes" title="الصناديق والأموال" columns={boxColumns} />
    </div>;
}

export function StatementReport({ data, visit }) {
    const { filters, canViewStatements, detailTable, presets } = usePage().props;
    if (!canViewStatements) return <p className="rounded-panel border border-gray-200 bg-surface p-8 text-center text-gray-500">عرض كشف الحساب يتطلب صلاحية عرض المشتركين.</p>;
    if (!data) return <AccountDirectory visit={visit} />;
    const withBalances = (lines) => [{ id: 'opening', day: filters.from, description: 'الرصيد الافتتاحي', balance: data.opening, debit: '0.00', credit: '0.00' }, ...lines, { id: 'closing', day: filters.to, description: 'الرصيد الختامي', balance: data.closing, debit: '0.00', credit: '0.00' }];
    const last90 = (presets ?? []).find((preset) => preset.key === 'last90' || preset.key === 'quarter');
    const closingHint = Number(data.closing) > 0 ? 'مستحق للشركة' : Number(data.closing) < 0 ? 'لصالح المشترك' : 'الحساب مسدّد';
    return <div className="space-y-5">
        <section aria-label="المشترك المحدد" className="rounded-panel border border-gray-100 bg-surface p-5 shadow-card sm:p-6">
            <div className="flex flex-wrap items-center justify-between gap-4">
                <div className="flex min-w-0 items-center gap-3"><span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-graphite-gradient text-white"><Icon name="users" className="h-6 w-6" /></span><div className="min-w-0"><p className="text-xs font-semibold text-gray-500">كشف حساب المشترك المحدد</p><h3 className="mt-1 break-words text-lg font-bold text-gray-900">{data.subscription.name}</h3><div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-gray-500"><span>رقم الحساب <bdi dir="ltr" className="font-display font-semibold text-gray-700">{data.subscription.accountNumber}</bdi></span><span className="inline-flex items-center gap-1.5"><Icon name="building" className="h-4 w-4" />{data.subscription.branchName}</span></div></div></div>
                <div className="flex w-full flex-wrap items-center gap-2 sm:w-auto"><SecondaryButton className="h-11 w-full sm:w-auto" onClick={() => visit({ subscription: undefined, page: undefined })}><Icon name="users" className="h-4 w-4" />اختيار مشترك آخر</SecondaryButton><BlueActionButton className="h-11 w-full sm:w-auto" onClick={() => router.visit(data.subscription.url)}><Icon name="ledger" className="h-4 w-4" />كشف الحساب الكامل</BlueActionButton></div>
            </div>
        </section>
        <section aria-label="ملخص كشف الحساب" className="statement-summary-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <KpiTile compact icon="ledger" label="الرصيد الافتتاحي" value={<bdi dir="ltr">{reportMoney(data.opening)}</bdi>} unit="₪" />
            <KpiTile compact icon="document-plus" label="عليه (مدين)" value={<bdi dir="ltr">{reportMoney(data.debit)}</bdi>} unit="₪" />
            <KpiTile compact icon="wallet" label="له (دائن)" value={<bdi dir="ltr">{reportMoney(data.credit)}</bdi>} unit="₪" />
            <KpiTile compact hero icon="layers" label="الرصيد الختامي" value={<bdi dir="ltr">{reportMoney(data.closing)}</bdi>} unit="₪" hint={closingHint} />
        </section>
            <TableReport section="statement" title={`كشف حساب ${data.subscription.name}`} columns={statementColumns} exportRows={detailTable?.active ? undefined : withBalances} empty={{ title: 'لا توجد حركات لهذا المشترك خلال الفترة المختارة', description: <>الرصيد الافتتاحي <bdi dir="ltr">{reportMoney(data.opening)}</bdi> شيكل لم يتغيّر خلال هذه الفترة. غيّر الفترة لعرض الحركات السابقة.</>, action: last90 ? <button type="button" className={secondaryButtonClass} onClick={() => visit({ from: last90.from, to: last90.to, view: undefined })}>عرض آخر 90 يومًا<Icon name="calendar" className="h-4 w-4" /></button> : <Link className={secondaryButtonClass} href={data.subscription.url}>عرض كشف الحساب الكامل</Link> }} />

    </div>;
}

function AccountDirectory({ visit }) {
    const { reportAccounts: accounts, accountFilters, filters } = usePage().props;
    const controls = useDataTable('/reports', accountFilters, { ...filters, section: 'statement' });
    if (!accounts) return null;
    return <section aria-label="قائمة المشتركين" data-excel-source="custom">
        <DataTableToolbar search={controls.search} onSearchChange={controls.setSearch} placeholder="بحث في جميع المشتركين بالاسم أو رقم الحساب..." perPage={accountFilters.per_page} onPerPageChange={controls.setPerPage} total={accounts.total} printable={false} actions={<InfoTip label="معلومة عن قائمة المشتركين">ابحث بالاسم أو رقم الحساب، ثم اضغط عرض الكشف.</InfoTip>} />
        <div className="data-table-container"><table className="data-table w-full text-start" aria-label="المشتركون المتاحون"><thead><tr><SortableTh column="account_number" label="رقم الحساب" sortState={accountFilters} onSort={controls.sort} /><SortableTh column="display_name" label="المشترك" sortState={accountFilters} onSort={controls.sort} /><th>الفرع</th><th>كشف الحساب</th></tr></thead><tbody>
            {accounts.data.length ? accounts.data.map((account) => <tr key={account.id}><td data-label="رقم الحساب" className="font-display tabular-nums"><bdi dir="ltr">{account.accountNumber}</bdi></td><td data-label="المشترك"><button type="button" className="font-bold text-brand-600 underline-offset-4 hover:underline" onClick={() => visit({ subscription: account.id, page: undefined })}>{account.name}</button></td><td data-label="الفرع">{account.branchName}</td><td data-label="كشف الحساب"><RowActionsMenu onView={() => visit({ subscription: account.id, page: undefined })} viewLabel="عرض الكشف" viewIcon="ledger" /></td></tr>) : <tr><td colSpan={4}>لا يوجد مشتركون مطابقون للبحث ضمن الفروع المتاحة.</td></tr>}
        </tbody></table></div><Pagination meta={accounts} onPageChange={controls.setPage} />
    </section>;
}
