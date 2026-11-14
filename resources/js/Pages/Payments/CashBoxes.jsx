import { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DataTableToolbar from '@/Components/DataTable/DataTableToolbar';
import Pagination from '@/Components/DataTable/Pagination';
import SegmentedTabs from '@/Components/SegmentedTabs';
import Icon from '@/Components/Icon';
import { financialBoxesTotals } from '@/lib/financialBoxes';
import { FinancialBoxCard, FinancialBoxSummary, financialAmountsLine } from './FinancialBoxComponents';
import FinancialBoxList from './FinancialBoxList';
import { boxTypeStyles, BoxReportActions } from './CollectorCashBox';
import PageHeader from '@/Components/PageHeader';

const typeFilters = [{ value: '', label: 'الكل' }, ...Object.entries(boxTypeStyles).map(([value, style]) => ({ value, label: style.label }))];
const stateFilters = [{ value: '', label: 'الكل' }, { value: 'open', label: 'مفتوح' }, { value: 'closed', label: 'مغلق' }];

/**
 * Every box the collector had, in the site's table: its type, totals,
 * dates, where and by whom it was closed, its stage, its details and its
 * report to print or download. What is open now and what waits on the
 * collector are on the summary page.
 */
export default function CashBoxes({ started, branchName, boxes, filters, pendingCount, collectionUrls }) {
    const { auth } = usePage().props;
    const [view, setView] = useState('table');
    const presentedBoxes = boxes.data.map((box) => ({ ...box, user: auth.user, opened_at: box.openedAt, closed_at: box.closedAt, confirmed_at: box.confirmedAt }));
    function filter(key, value) {
        router.get(collectionUrls.cashBoxes, { ...filters, [key]: value }, { preserveState: true, preserveScroll: true, replace: true });
    }

    return <AuthenticatedLayout badges={{ '/payments/summary': pendingCount }} header={<PageHeader eyebrow={branchName ?? 'حسابك الحالي'} title="الصناديق المالية" />}>
        <Head title="الصناديق المالية" />
        {started ? <div className="financial-boxes financial-surface space-y-5">
            <FinancialBoxSummary boxes={presentedBoxes} pageOnly />
            <section aria-label="كل صناديقي">
                <DataTableToolbar
                    showSearch={false}
                    perPage={filters.per_page}
                    onPerPageChange={(perPage) => filter('per_page', perPage)}
                    total={boxes.total}
                    filterMenu={
                        <div className="flex w-full flex-wrap items-center gap-3">
                            <SegmentedTabs label="نوع الصندوق" value={filters.type} onChange={(value) => filter('type', value)} options={typeFilters} />
                            <SegmentedTabs label="حالة الصندوق" value={filters.state} onChange={(value) => filter('state', value)} options={stateFilters} />
                            <SegmentedTabs label="طريقة العرض" value={view} onChange={setView} options={[{ value: 'table', label: <><Icon name="table" className="h-4 w-4" />جدول</> }, { value: 'cards', label: <><Icon name="grid" className="h-4 w-4" />بطاقات</> }]} />
                        </div>
                    }
                />
                <div className="data-table-container financial-box-list-container">
                    {view === 'cards' ? (presentedBoxes.length ? <div className="grid gap-4 py-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">{presentedBoxes.map((box) => <FinancialBoxCard key={box.id} box={box} onOpen={(item) => router.visit(item.urls.show)} />)}</div> : <p className="px-4 py-14 text-center text-gray-500">لا توجد صناديق بهذه الفلاتر.</p>) : <FinancialBoxList boxes={presentedBoxes} collector onOpen={(box) => router.visit(box.urls.show)} renderActions={(box) => <BoxReportActions box={box} onView={() => router.visit(box.urls.show)} />} /> }
                </div>
                <p className="data-table-totals text-sm text-gray-600"><span>المعروض <b className="font-display text-gray-900">{boxes.data.length}</b> من <b className="font-display text-gray-900">{boxes.total}</b> صندوقًا</span><span>مجموع الصناديق المعروضة: <b className="font-display text-gray-900">{financialAmountsLine(financialBoxesTotals(presentedBoxes))}</b></span></p>
                <Pagination meta={boxes} filters={filters} baseUrl={collectionUrls.cashBoxes} />
            </section>
        </div> : <p className="rounded-panel border border-dashed border-gray-200 bg-surface p-6 text-sm leading-7 text-gray-500">{branchName ? 'تُفتح الصناديق تلقائيًا عند تسجيل أول دفعة أو مقاصة في فرعك.' : 'حسابك غير مرتبط بفرع، لذلك لا يوجد سجل صناديق شخصي.'}</p>}
    </AuthenticatedLayout>;
}
