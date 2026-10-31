import { lazy, Suspense, useMemo, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import BranchBanner, { BranchChips } from '@/Components/BranchBanner';
import BarChart from '@/Components/Charts/BarChart';
import MeterBar from '@/Components/Charts/MeterBar';
import Pagination from '@/Components/DataTable/Pagination';
import DateInput from '@/Components/DateInput';
import Icon from '@/Components/Icon';
import InfoTip from '@/Components/InfoTip';
import TableExportActions from '@/Components/DataTable/TableExportActions';
import KpiTile from '@/Components/KpiTile';
import PageHeader from '@/Components/PageHeader';
import SearchableSelect from '@/Components/SearchableSelect';
import SegmentedTabs from '@/Components/SegmentedTabs';
import { accountsPart, branchesPart, collectorsPart, currenciesPart, daysPart, flowPart, linesPart, readingsPart, reportExcelParts } from '@/lib/reportExcelParts';
import { loadReportLines } from '@/lib/reportLines';
import { isPrintMode, printUrl } from '@/lib/print';
import { reportMoney, shortDate } from '@/lib/reportFormatting';
import { weekDayName } from '@/lib/weekDays';
import ReportPrintSheet from './ReportPrintSheet';
import { BoxesReport, DebtReport, StatementReport } from './ReportDetails';

const ExcelStudio = lazy(() => import('@/Components/ExcelStudio'));

/** Cents of a money string, so totals add up exactly. */
function cents(amount) {
    return Math.round(Number(amount ?? 0) * 100);
}

function periodLabel(mode, details) {
    if (mode === 'daily') return 'تقرير يومي';
    if (mode === 'weekly') return 'تقرير أسبوعي';
    if (mode === 'monthly') return 'تقرير شهري';
    return details?.isOpen ? 'تقرير جارٍ' : 'تقرير مخصص';
}

const TH = 'px-3 py-2.5 text-start text-xs font-semibold text-gray-500';
const NUM_TH = TH.replace('text-start', 'text-right');
const TD = 'px-3 py-2.5 text-start';
const NUM = 'px-3 py-2.5 text-right [direction:ltr] font-display tabular-nums text-gray-900';

/**
 * The financial reports page: one branch (or every branch, for whoever
 * reports on several) over a day or a stretch of days. The figures that
 * matter lead, then the reports themselves, picked from a list as in a
 * catalogue: the financial summary, day by day, the collections, the
 * branches side by side and every line. It prints as a clean sheet of the
 * period and its summaries, and exports to Excel through the editor.
 */
export default function Index({ branches, filters, scopeLabel, branchScope, presets, today, kinds, flow, collections, readings, days, transactions, period, comparison, branchSummary, canExport, canViewStatements, reportSection = 'overview', debts, boxData, reportStatement }) {
    const { errors, auth } = usePage().props;
    const [excelOpen, setExcelOpen] = useState(false);
    const section = reportSection;
    const [printView, setPrintView] = useState('summary');
    const printing = isPrintMode();
    const oneDay = filters.from === filters.to;
    const query = new URLSearchParams(
        Object.entries({ branch: filters.branch ?? '', from: filters.from, to: filters.to, kind: filters.kind, section }).filter(([, value]) => value !== ''),
    ).toString();
    const scopeName = branchScope?.name ?? scopeLabel;
    const periodText = oneDay ? `${weekDayName(filters.from)} ${shortDate(filters.from, true)}` : `${shortDate(filters.from, true)} – ${shortDate(filters.to, true)}`;
    const kindLabel = kinds.find((kind) => kind.value === filters.kind)?.label ?? 'كل الحركات';
    const hasFigures = Boolean(flow && collections);
    const openDay = (day) => visit({ from: day, to: day, page: undefined });

    function visit(changes) {
        const subscription = changes.branch !== undefined && String(changes.branch) !== String(filters.branch) ? undefined : reportStatement?.subscription.id;
        router.get('/reports', { ...filters, section, subscription, ...changes }, { preserveScroll: true, preserveState: true });
    }

    const excelMeta = useMemo(() => ({
        lines: [`الفرع: ${scopeName}`, `الفترة: ${periodLabel(period?.mode, period)} · ${periodText}`],
        criteria: [
            ['الفرع', scopeName],
            ['الفترة', `${shortDate(filters.from, true)} – ${shortDate(filters.to, true)}`],
            ['نوع التقرير', periodLabel(period?.mode, period)],
            ['تصفية ورقة الحركات فقط', kindLabel],
            ['الملخص والتحصيل', 'جميع أنواع الحركات في الفترة المحددة'],
            ['أعدّه', auth?.user?.name ?? ''],
            ['تاريخ الإعداد', new Date().toLocaleString('en-GB', { dateStyle: 'short', timeStyle: 'short' })],
        ],
    }), [scopeName, period, periodText, filters.from, filters.to, kindLabel, auth?.user?.name, excelOpen]);

    /** The parts on the page now, and every line of the period fetched with the same filters. */
    async function loadExcelParts(options) {
        const lines = await loadReportLines(query, options);

        return reportExcelParts({ flow, collections, comparison, readings, days, branchSummary: branchSummary ?? [], lines, showBranch: filters.branch === 'all' });
    }

    const sections = [
        { key: 'overview', icon: 'ledger', title: 'نظرة عامة', heading: 'الوضع المالي للشركة', hint: 'التحميلات والتحصيل وحركة ذمم المشتركين' },
        { key: 'collections', icon: 'wallet', title: 'التحصيل', heading: 'تقرير التحصيل', hint: 'حسب الحساب والعملة والمحصّل' },
        { key: 'boxes', icon: 'layers', title: 'الصناديق والأموال', heading: 'الصناديق والأموال', hint: 'الصناديق المفتوحة خلال الفترة والدفعات المسجلة فيها حسب العملة' },
        { key: 'debts', icon: 'calendar', title: 'الديون', heading: 'ديون المشتركين', hint: 'الأرصدة وأعمار التحميلات كما في نهاية الفترة' },
        { key: 'statement', icon: 'users', title: 'كشوفات حسابات المشتركين', heading: 'كشوفات حسابات المشتركين', hint: 'رصيد افتتاحي وحركات ورصيد متتابع' },
        { key: 'movements', icon: 'list', title: 'سجل الحركات', heading: 'سجل الحركات', hint: 'كل الحركات المالية للفترة المختارة' },
    ];
    const current = sections.find((item) => item.key === section) ?? sections[0];

    return (
        <AuthenticatedLayout
            scope={branchScope ? branchScope.name : null}
            header={
                <PageHeader
                    title="التقارير المالية"
                    meta={`${branchScope ? '' : `${scopeLabel} · `}${periodLabel(period?.mode, period)} · ${periodText}${oneDay && filters.from === today ? ' · اليوم الجاري' : ''}`}
                />
            }
        >
            <Head title={branchScope ? `التقارير المالية — ${branchScope.name}` : 'التقارير المالية'} />
            {canExport && excelOpen && (
                <Suspense fallback={null}>
                    <ExcelStudio
                        show={excelOpen}
                        onClose={() => setExcelOpen(false)}
                        title="تصدير التقرير إلى Excel"
                        storageKey="reports"
                        loadParts={loadExcelParts}
                        defaults={{ title: `التقارير المالية — ${scopeName}`, fileName: `التقارير_${scopeName}_${filters.from}_${filters.to}` }}
                        meta={excelMeta}
                    />
                </Suspense>
            )}

            <div className="reports-page space-y-5" dir="rtl">
                {branchScope && (
                    <BranchBanner
                        scope={branchScope}
                        options={branches}
                        value={filters.branch}
                        onChange={(branch) => visit({ branch, page: undefined })}
                        meta={`${periodLabel(period?.mode, period)} · ${periodText}`}
                        className=""
                    >
                        {branchSummary?.length > 0 && (
                            <BranchChips
                                items={branchSummary.map((row) => ({
                                    key: row.id,
                                    href: `/reports?${new URLSearchParams({ ...filters, branch: row.id }).toString()}`,
                                    name: row.name,
                                    figure: `${reportMoney(row.collections.total)} ₪`,
                                    amount: cents(row.collections.total),
                                }))}
                            />
                        )}
                    </BranchBanner>
                )}

                <nav aria-label="أقسام التقارير" className="flex flex-wrap gap-1 rounded-panel border border-gray-100 bg-surface p-2 shadow-card">
                    {sections.map((item) => <button key={item.key} type="button" aria-current={section === item.key ? 'page' : undefined} onClick={() => { setPrintView(item.key === 'movements' ? 'lines' : 'summary'); visit({ section: item.key, page: undefined }); }} className={`inline-flex items-center gap-2 rounded-control px-4 py-2.5 text-sm font-semibold transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-gray-900 ${section === item.key ? 'bg-graphite-800 text-white' : 'text-gray-600 hover:bg-gray-50'}`}><Icon name={item.icon} className="h-4 w-4" />{item.title}</button>)}
                </nav>
                <PeriodCard branches={branches} branchScope={branchScope} scopeLabel={scopeLabel} filters={filters} presets={presets} today={today} period={period} periodText={periodText} visit={visit} />
                {hasFigures && ['overview', 'collections', 'movements'].includes(section) && (
                    <div className="data-table-toolbar flex flex-wrap items-center gap-3">
                        <label className="flex items-center gap-2 text-sm text-gray-600">محتوى الطباعة
                            <select aria-label="محتوى الطباعة" className="text-sm" value={printView} onChange={(event) => setPrintView(event.target.value)}>
                                <option value="summary">الملخص المالي</option>
                                <option value="lines">الحركات التفصيلية</option>
                            </select>
                        </label>
                        <TableExportActions showExcel={canExport} onExcel={() => setExcelOpen(true)} printHref={printUrl(`${printView === 'lines' ? 'الحركات التفصيلية' : 'الملخص المالي'} — ${scopeName} — ${periodText}`, `/reports?${query}&print_view=${printView}`)} />
                    </div>
                )}

                {errors.from && (
                    <p role="alert" className="flex items-center gap-2 rounded-card border border-red-200 bg-red-500/5 px-4 py-3 text-sm font-semibold text-red-700">
                        <Icon name="alert" className="h-5 w-5" />
                        {errors.from}
                    </p>
                )}

                {branches.length === 0 && (
                    <div className="rounded-card border border-dashed border-gray-200 bg-surface px-6 py-16 text-center">
                        <b className="text-gray-900">لا توجد فروع</b>
                        <p className="mt-1 text-sm text-gray-500">أضف فرعًا أولًا لعرض تقاريره.</p>
                    </div>
                )}

                <div className="flex flex-wrap items-center justify-between gap-3"><h2 className="text-xl font-bold text-gray-900">{current.heading}</h2><InfoTip label={`معلومة عن ${current.heading}`}>{current.hint}</InfoTip></div>
                {hasFigures && section === 'overview' && <>
                    <Figures flow={flow} collections={collections} />
                    {comparison && <Comparison comparison={comparison} />}
                    <div className="grid gap-5 xl:grid-cols-2"><FlowStatement flow={flow} /><div className="space-y-5"><CollectionsBreakdown collections={collections} compact />{readings && <Readings readings={readings} />}</div></div>
                    {days.length > 1 && <DailyCollections days={days} onOpenDay={openDay} />}
                    {branchSummary?.length > 0 && <BranchComparison rows={branchSummary} filters={filters} />}
                </>}
                {hasFigures && section === 'collections' && <>
                    <CollectionsBreakdown collections={collections} />
                    {days.length > 1 && <><DailyCollections days={days} onOpenDay={openDay} /><DaysTable days={days} onOpenDay={openDay} /></>}
                </>}
                {section === 'boxes' && <BoxesReport data={boxData} />}
                {section === 'debts' && <DebtReport key={filters.branch} data={debts} visit={visit} />}
                {section === 'statement' && <StatementReport key={filters.branch} data={reportStatement} visit={visit} />}
                {section === 'movements' && <Transactions transactions={transactions} kinds={kinds} filters={{ ...filters, section }} showDay={!oneDay} showBranch={filters.branch === 'all'} onKind={(kind) => visit({ kind, page: undefined })} onStatement={canViewStatements ? (subscription) => visit({ section: 'statement', subscription, page: undefined }) : null} />}

            </div>

            {hasFigures && !printing && section === 'overview' && (
                <ReportPrintSheet
                    scopeName={scopeName}
                    periodName={periodLabel(period?.mode, period)}
                    from={filters.from}
                    to={filters.to}
                    flow={flow}
                    collections={collections}
                    branchSummary={branchSummary ?? []}
                    preparedBy={auth?.user?.name ?? ''}
                />
            )}
        </AuthenticatedLayout>
    );
}

function Comparison({ comparison }) {
    const difference = Number(comparison.difference);

    return (
        <section className="flex flex-wrap items-center justify-between gap-3 rounded-panel border border-gray-100 bg-surface p-4 shadow-card" aria-label="المقارنة بالفترة السابقة">
            <div>
                <h3 className="text-sm font-bold text-gray-900">التحصيل مقارنة بالفترة السابقة</h3>
                <p className="mt-1 text-xs text-gray-500">{shortDate(comparison.from, true)} – {shortDate(comparison.to, true)} · المحصّل سابقًا {reportMoney(comparison.total)} ₪</p>
            </div>
            <p className={`text-sm font-semibold ${difference > 0 ? 'text-emerald-700 dark:text-emerald-400' : difference < 0 ? 'text-red-700 dark:text-red-400' : 'text-gray-600'}`}>
                {difference > 0 ? 'زيادة' : difference < 0 ? 'انخفاض' : 'لم يتغير'} {reportMoney(Math.abs(difference))} ₪
                {comparison.percentage === null ? ' · لا تحصيل في الفترة السابقة' : ` (${Math.abs(comparison.percentage)}%)`}
            </p>
        </section>
    );
}

/** A white card with an icon and a title, as the dashboard's panels. */
function Panel({ icon, title, hint = null, className = '', excelParts, children }) {
    const { canExport, auth, scopeLabel, filters } = usePage().props;
    return (
        <section data-excel-source={excelParts ? 'custom' : undefined} className={`rounded-panel border border-gray-100 bg-surface p-5 shadow-card sm:p-6 ${className}`}>
            <div className="mb-4 flex flex-wrap items-center gap-x-2.5 gap-y-2">
                <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-gray-100 bg-gray-50 text-gray-500">
                    <Icon name={icon} className="h-[18px] w-[18px]" />
                </span>
                <h3 className="font-bold text-gray-900">{title}</h3>
                {hint && <span className="text-xs text-gray-500">{hint}</span>}
                {canExport && excelParts && <TableExportActions title={title} showPrint={false} excelProps={{ storageKey: `reports:${title}`, templateKind: 'reports', loadParts: async (options) => (await excelParts(options)).map((part) => ({ ...part, default: true })), meta: { lines: [scopeLabel, `${filters.from} – ${filters.to}`].filter(Boolean), criteria: [['أعدّه', auth?.user?.name ?? ''], ['من', filters.from], ['إلى', filters.to]] } }} />}
            </div>
            {children}
        </section>
    );
}

/** The branch (for whoever doesn't see the banner), the quick periods, the dates and stepping to the period before or after. */
function PeriodCard({ branches, branchScope, scopeLabel, filters, presets, today, period, periodText, visit }) {
    const preset = presets.find((item) => item.from === filters.from && item.to === filters.to)?.key ?? '';
    const [datesOpen, setDatesOpen] = useState(false);
    const stepClass = 'inline-flex items-center gap-1 rounded-control px-2.5 py-1.5 text-sm font-semibold text-white/80 transition hover:bg-white/10 hover:text-white disabled:opacity-40';

    return (
        <section aria-label="فلاتر التقرير" className="sticky top-[calc(var(--tn-bar-rest-height)+1.5rem)] z-30 rounded-panel border border-white/10 bg-graphite-gradient p-3 text-white shadow-card sm:p-4">
            <span aria-hidden="true" className="absolute inset-x-5 top-0 h-[2px] bg-spectrum" />
            <div className="flex flex-wrap items-center gap-3">
                <div className="flex min-w-0 flex-1 items-center gap-3"><span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-white/15 bg-white/10"><Icon name="calendar" className="h-5 w-5" /></span><div className="min-w-0"><h3 className="text-sm font-bold text-white">الفترة</h3><p className="mt-1 text-xs text-white/70"><bdi dir="ltr">{periodText}</bdi></p></div></div>
                {!branchScope && branches.length > 1 && (
                    <label className="order-3 flex w-full flex-col gap-1.5 text-xs font-semibold text-white/65 sm:order-none sm:w-60">
                        الفرع
                        <SearchableSelect
                            value={filters.branch ?? ''}
                            onChange={(value) => value && visit({ branch: value, page: undefined })}
                            options={[{ value: 'all', label: 'كل الفروع' }, ...branches.map((branch) => ({ value: String(branch.value), label: branch.label }))]}
                            searchable={branches.length > 6}
                            portal
                            onDark
                        />
                    </label>
                )}
                {!branchScope && branches.length === 1 && (
                    <div className="order-3 flex w-full flex-col gap-1.5 text-xs font-semibold text-white/65 sm:order-none sm:w-auto">
                        الفرع
                        <span className="flex h-10 items-center gap-2 rounded-control border border-white/15 bg-white/10 px-3 text-sm font-bold text-white">
                            <Icon name="pin" className="h-4 w-4 text-white/60" />
                            {scopeLabel}
                        </span>
                    </div>
                )}
                <div className="order-2 w-full sm:w-52">
                    <label htmlFor="report-period" className="sr-only">الفترة</label>
                    <SearchableSelect
                        id="report-period"
                        onDark
                        portal
                        clearable={false}
                        searchable={false}
                        value={datesOpen ? 'custom' : preset || 'custom'}
                        onChange={(key) => {
                            if (key === 'custom') { setDatesOpen(true); return; }
                            const chosen = presets.find((item) => item.key === key);
                            setDatesOpen(false);
                            visit({ from: chosen.from, to: chosen.to, view: undefined, page: undefined });
                        }}
                        options={[...presets.map((item) => ({ value: item.key, label: item.label })), { value: 'custom', label: 'تاريخ مخصص' }]}
                    />
                </div>
                <div className="order-1 flex items-center gap-1 sm:order-3">
                    <button type="button" aria-label="الفترة السابقة" disabled={!period?.previous} className={stepClass} onClick={() => period?.previous && visit({ ...period.previous, page: undefined })}><Icon name="chevron-right" className="h-4 w-4" /></button>
                    <button type="button" aria-label="الفترة التالية" disabled={!period?.next} className={stepClass} onClick={() => period?.next && visit({ ...period.next, page: undefined })}><Icon name="chevron-left" className="h-4 w-4" /></button>
                </div>
            </div>
            {datesOpen && <div className="mt-3 flex flex-wrap items-end gap-3 border-t border-white/15 pt-3">
                <div className="flex w-full flex-col gap-3 sm:w-auto sm:flex-row" role="group" aria-label="من تاريخ إلى تاريخ">
                    <label className="flex min-w-0 flex-1 flex-col gap-1.5 text-xs font-semibold text-white/65 sm:flex-none"><span>من تاريخ</span><DateInput onDark aria-label="من تاريخ" className="w-full sm:w-52" required value={filters.from} max={filters.to} onChange={(value) => value && visit({ from: value, page: undefined })} /></label>
                    <label className="flex min-w-0 flex-1 flex-col gap-1.5 text-xs font-semibold text-white/65 sm:flex-none"><span>إلى تاريخ</span><DateInput onDark aria-label="إلى تاريخ" className="w-full sm:w-52" required value={filters.to} min={filters.from} max={today} onChange={(value) => value && visit({ to: value, page: undefined })} /></label>
                </div>
                <button type="button" className={stepClass} onClick={() => setDatesOpen(false)}>إغلاق<Icon name="close" className="h-4 w-4" /></button>
            </div>}
        </section>
    );
}

/** What was collected (cash against transfers and cheques), what the subscribers owed at the start and at the end, and what was charged. */
function Figures({ flow, collections }) {
    const change = cents(flow.change);
    const total = Number(collections.total);

    return (
        <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_minmax(0,1fr)]">
            <KpiTile hero label="إجمالي المحصّل" value={reportMoney(collections.total)} unit="₪" hint={`${collections.count} دفعة في الفترة`} className="md:col-span-2 xl:col-span-1 xl:row-span-2">
                <div className="mt-5 flex h-2 overflow-hidden rounded-full bg-white/10" role="img" aria-label="النقدي والتحويلات والشيكات">
                    {total > 0 && (
                        <>
                            <span className="bg-white" style={{ width: `${(Number(collections.cash) / total) * 100}%` }} />
                            <span className="bg-brand-500" style={{ width: `${(Number(collections.nonCash) / total) * 100}%` }} />
                        </>
                    )}
                </div>
                <div className="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-xs text-white/70">
                    <span className="flex items-center gap-1.5"><i className="h-2 w-2 rounded-full bg-white" />نقدي <b className="font-display text-white">{reportMoney(collections.cash)} ₪</b></span>
                    <span className="flex items-center gap-1.5"><i className="h-2 w-2 rounded-full bg-brand-500" />تحويلات وشيكات <b className="font-display text-white">{reportMoney(collections.nonCash)} ₪</b></span>
                </div>
            </KpiTile>
            <KpiTile icon="ledger" label="الرصيد الافتتاحي" value={<bdi dir="ltr">{reportMoney(flow.opening)}</bdi>} unit="₪" hint={Number(flow.opening) < 0 ? 'لصالح المشتركين' : 'على المشتركين'} />
            <KpiTile icon="document-plus" label="التحميلات" value={reportMoney(flow.chargesTotal)} unit="₪" hint="قراءات ورسوم وغرامات" />
            <KpiTile icon="layers" label="الرصيد الختامي" value={<bdi dir="ltr">{reportMoney(flow.closing)}</bdi>} unit="₪" className="md:col-span-2">
                <p className={`mt-2 inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold ${change > 0 ? 'bg-amber-500/10 text-amber-700' : change < 0 ? 'bg-emerald-500/10 text-emerald-700' : 'bg-gray-100 text-gray-500'}`}>
                    {change > 0 ? 'زاد' : change < 0 ? 'نقص' : 'لم يتغير'}
                    {change !== 0 && <b className="font-display">{reportMoney(Math.abs(Number(flow.change)))} ₪</b>}
                    <span className="font-normal">خلال الفترة</span>
                </p>
            </KpiTile>
        </div>
    );
}

/** The reports of the period: a list beside them on a wide screen, a row of tabs on a phone. */
function SectionNav({ sections, current, onChange }) {
    return (
        <nav aria-label="التقارير" className="min-w-0 lg:sticky lg:top-24 lg:self-start">
            <h2 className="mb-2 hidden px-1 text-xs font-bold text-gray-500 lg:block">التقارير</h2>
            <ul className="flex gap-2 overflow-x-auto pb-1 lg:flex-col lg:overflow-visible lg:pb-0">
                {sections.map((item) => (
                    <li key={item.key} className="shrink-0">
                        <button
                            type="button"
                            aria-current={item.key === current ? 'page' : undefined}
                            onClick={() => onChange(item.key)}
                            className={`flex w-full items-center gap-3 rounded-card border px-3 py-2.5 text-start transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-gray-900 ${
                                item.key === current ? 'border-transparent bg-graphite-gradient text-white shadow-card' : 'border-gray-100 bg-surface text-gray-700 hover:border-gray-200'
                            }`}
                        >
                            <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-xl ${item.key === current ? 'bg-white/10' : 'bg-gray-50 text-gray-500'}`}>
                                <Icon name={item.icon} className="h-[18px] w-[18px]" />
                            </span>
                            <span className="min-w-0">
                                <b className="block whitespace-nowrap text-sm">{item.title}</b>
                                <small className={`block whitespace-nowrap text-xs ${item.key === current ? 'text-white/60' : 'text-gray-500'}`}>{item.hint}</small>
                            </span>
                        </button>
                    </li>
                ))}
            </ul>
        </nav>
    );
}

/** What the subscribers owed at the start, plus what was charged, less what was paid and discounted, ± the cancellations: what they owe at the end. */
function FlowStatement({ flow }) {
    const row = 'border-t border-gray-100';
    const sum = 'border-t border-gray-200 bg-gray-50 font-bold';

    return (
        <Panel icon="ledger" title="حركة الأرصدة" hint="من الرصيد الافتتاحي إلى الختامي" excelParts={async () => [flowPart(flow)]}>
            <p className="mb-3 rounded-control bg-gray-50 px-3 py-2 text-xs leading-6 text-gray-600">
                الرصيد الافتتاحي + التحميلات − الدفعات والخصومات ± الإلغاءات والقيود العكسية = الرصيد الختامي
            </p>
            <table className="w-full text-sm">
                <thead>
                    <tr>
                        <th className={TH}>البند</th>
                        <th className={NUM_TH}>العدد</th>
                        <th className={NUM_TH}>المبلغ ₪</th>
                    </tr>
                </thead>
                <tbody>
                    <tr className={sum}>
                        <td className={TD}>الرصيد الافتتاحي</td>
                        <td />
                        <td className={NUM}>{reportMoney(flow.opening)}</td>
                    </tr>
                    {flow.charges.map((line) => (
                        <tr key={line.type} className={row}>
                            <td className={`${TD} text-gray-700`}><span className="me-1.5 text-emerald-600">+</span>{line.label}</td>
                            <td className={`${NUM} text-gray-500`}>{line.count}</td>
                            <td className={NUM}>{reportMoney(line.total)}</td>
                        </tr>
                    ))}
                    <tr className={`${row} font-semibold`}>
                        <td className={TD}>مجموع التحميلات (عليه)</td>
                        <td />
                        <td className={NUM}>{reportMoney(flow.chargesTotal)}</td>
                    </tr>
                    {flow.credits.map((line) => (
                        <tr key={line.type} className={row}>
                            <td className={`${TD} text-gray-700`}><span className="me-1.5 text-brand-600">−</span>{line.label}</td>
                            <td className={`${NUM} text-gray-500`}>{line.count}</td>
                            <td className={NUM}>{reportMoney(line.total)}</td>
                        </tr>
                    ))}
                    <tr className={`${row} font-semibold`}>
                        <td className={TD}>مجموع الدفعات والخصومات (له)</td>
                        <td />
                        <td className={NUM}>{reportMoney(flow.creditsTotal)}</td>
                    </tr>
                    <tr className={row}>
                        <td className={`${TD} text-gray-700`}><span className="me-1.5 text-amber-600">±</span>الإلغاءات والقيود العكسية</td>
                        <td className={`${NUM} text-gray-500`}>{flow.corrections.count}</td>
                        <td className={`${NUM} ${cents(flow.corrections.total) !== 0 ? 'text-amber-700' : ''}`}>{reportMoney(flow.corrections.total)}</td>
                    </tr>
                    <tr className={`${sum} border-t-2`}>
                        <td className={TD}>الرصيد الختامي</td>
                        <td />
                        <td className={NUM}>{reportMoney(flow.closing)}</td>
                    </tr>
                </tbody>
            </table>
            <p className="mt-3 text-xs leading-6 text-gray-500">الإلغاء يظهر في يومه؛ والسطر الملغى في نفس يوم تسجيله يُحسب مع الإلغاءات لا مع نوعه، فتبقى أرقام الأيام السابقة ثابتة.</p>
        </Panel>
    );
}

/** Where the payments came in: by account, then (in full) by currency and by collector. */
function CollectionsBreakdown({ collections, compact = false }) {
    const largest = Math.max(1, ...collections.accounts.map((account) => Number(account.total)));
    const largestCollector = Math.max(1, ...collections.collectors.map((collector) => Number(collector.total)));
    const foreign = collections.currencies.length > 1 || (collections.currencies[0] && collections.currencies[0].currency !== 'ILS');

    if (collections.count === 0) {
        return (
            <Panel icon="wallet" title="التحصيل">
                <p className="text-sm text-gray-500">لا دفعات في هذه الفترة.</p>
            </Panel>
        );
    }

    return (
        <>
            <Panel icon="wallet" title={compact ? 'التحصيل حسب الحساب' : 'حسب الحساب'} hint={`${collections.count} دفعة · ${reportMoney(collections.total)} ₪`} excelParts={async () => [accountsPart(collections)]}>
                <ul className="space-y-3">
                    {collections.accounts.map((account) => (
                        <li key={account.key}>
                            <div className="mb-1.5 flex items-center justify-between gap-3 text-sm">
                                <span className="flex items-center gap-2 font-semibold text-gray-800">
                                    <Icon name={account.key === 'cash' ? 'wallet' : 'bank'} className="h-4 w-4 text-gray-400" />
                                    {account.label}
                                    <span className="text-xs font-normal text-gray-500">{account.count} دفعة</span>
                                </span>
                                <b className="font-display tabular-nums text-gray-900">{reportMoney(account.total)} ₪</b>
                            </div>
                            <MeterBar value={Number(account.total)} max={largest} />
                        </li>
                    ))}
                </ul>
            </Panel>

            {!compact && foreign && (
                <Panel icon="currency" title="حسب العملة" excelParts={async () => [currenciesPart(collections)]}>
                    <table className="w-full text-sm">
                        <thead>
                            <tr>
                                <th className={TH}>العملة</th>
                                <th className={NUM_TH}>الدفعات</th>
                                <th className={NUM_TH}>المبلغ بالعملة</th>
                                <th className={NUM_TH}>بالشيكل</th>
                            </tr>
                        </thead>
                        <tbody>
                            {collections.currencies.map((currency) => (
                                <tr key={currency.currency} className="border-t border-gray-100">
                                    <td className={TD}>{currency.label}</td>
                                    <td className={`${NUM} text-gray-500`}>{currency.count}</td>
                                    <td className={NUM}>{reportMoney(currency.amount)} {currency.currency}</td>
                                    <td className={NUM}>{reportMoney(currency.total)} ₪</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Panel>
            )}

            {!compact && (
                <Panel icon="users" title="حسب المحصِّل" excelParts={async () => [collectorsPart(collections)]}>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr>
                                    <th className={TH}>الموظف</th>
                                    <th className={NUM_TH}>الدفعات</th>
                                    <th className={NUM_TH}>نقدي</th>
                                    <th className={NUM_TH}>تحويلات وشيكات</th>
                                    <th className={NUM_TH}>المجموع</th>
                                    <th className={`${TH} w-32`}><span className="sr-only">الحصة</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                {collections.collectors.map((collector) => (
                                    <tr key={collector.name} className="border-t border-gray-100">
                                        <td className={`${TD} font-semibold text-gray-900`}>{collector.name}</td>
                                        <td className={`${NUM} text-gray-500`}>{collector.count}</td>
                                        <td className={NUM}>{reportMoney(collector.cash)}</td>
                                        <td className={NUM}>{reportMoney((cents(collector.total) - cents(collector.cash)) / 100)}</td>
                                        <td className={`${NUM} font-bold`}>{reportMoney(collector.total)}</td>
                                        <td className={TD}><MeterBar value={Number(collector.total)} max={largestCollector} /></td>
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot>
                                <tr className="border-t-2 border-gray-200 bg-gray-50 font-bold">
                                    <td className={TD}>المجموع</td>
                                    <td className={NUM}>{collections.count}</td>
                                    <td className={NUM}>{reportMoney(collections.cash)}</td>
                                    <td className={NUM}>{reportMoney(collections.nonCash)}</td>
                                    <td className={NUM}>{reportMoney(collections.total)}</td>
                                    <td />
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </Panel>
            )}
        </>
    );
}

function Readings({ readings }) {
    return (
        <Panel icon="gauge" title="القراءات" excelParts={async () => [readingsPart(readings)]}>
            <dl className="grid grid-cols-3 gap-3 text-center">
                <div className="rounded-control bg-gray-50 px-2 py-3">
                    <dt className="text-xs text-gray-500">أُدخلت</dt>
                    <dd className="mt-1 font-display text-xl font-bold text-gray-900">{readings.entered}</dd>
                    <dd className="text-xs text-gray-500">{readings.consumption.toLocaleString('en-US')} كيلو</dd>
                </div>
                <div className="rounded-control bg-gray-50 px-2 py-3">
                    <dt className="text-xs text-gray-500">اعتُمدت وحُمِّلت</dt>
                    <dd className="mt-1 font-display text-xl font-bold text-gray-900">{readings.approved}</dd>
                    <dd className="text-xs text-gray-500">{reportMoney(readings.billed)} ₪</dd>
                </div>
                <div className={`rounded-control px-2 py-3 ${readings.pending > 0 ? 'bg-amber-500/10' : 'bg-gray-50'}`}>
                    <dt className="text-xs text-gray-500">بانتظار الاعتماد الآن</dt>
                    <dd className={`mt-1 font-display text-xl font-bold ${readings.pending > 0 ? 'text-amber-700' : 'text-gray-900'}`}>{readings.pending}</dd>
                    {readings.pending > 0 && (
                        <dd><Link href="/meter-readings" className="text-xs font-semibold text-amber-700 underline-offset-2 hover:underline">فتح القراءات</Link></dd>
                    )}
                </div>
            </dl>
        </Panel>
    );
}

/** What was collected each day of the period. */
function DailyCollections({ days }) {
    return (
        <Panel icon="chart" title="التحصيل اليومي" hint="بالشيكل" excelParts={async () => [daysPart(days)]}>
            <BarChart data={days.map((day) => ({ date: day.day, value: Number(day.payments), count: day.lines }))} label="التحصيل اليومي في الفترة" formatValue={(value) => `${reportMoney(value)} ₪`} countLabel="حركة" height={220} />
        </Panel>
    );
}

/** One row per day: what moved and what was owed at its end; a row opens that day's report. */
function DaysTable({ days, onOpenDay }) {
    const total = (key) => reportMoney(days.reduce((sum, day) => sum + cents(day[key]), 0) / 100);

    return (
        <Panel icon="calendar" title="يومًا بيوم" hint="اضغط يومًا لفتح تقريره" excelParts={async () => [daysPart(days)]}>
            <div className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead>
                        <tr>
                            <th className={TH}>اليوم</th>
                            <th className={NUM_TH}>التحميلات</th>
                            <th className={NUM_TH}>الدفعات</th>
                            <th className={NUM_TH}>الخصومات</th>
                            <th className={NUM_TH}>الإلغاءات</th>
                            <th className={NUM_TH}>الرصيد الختامي لليوم</th>
                        </tr>
                    </thead>
                    <tbody>
                        {days.map((day) => (
                            <tr key={day.day} className="cursor-pointer border-t border-gray-100 transition hover:bg-gray-50" onClick={() => onOpenDay(day.day)}>
                                <td className={TD}>
                                    <span className="text-gray-700">{weekDayName(day.day)}</span> <span className="font-display text-gray-500">{shortDate(day.day)}</span>
                                </td>
                                <td className={NUM}>{reportMoney(day.charges)}</td>
                                <td className={NUM}>{reportMoney(day.payments)}</td>
                                <td className={NUM}>{reportMoney(day.discounts)}</td>
                                <td className={`${NUM} ${cents(day.corrections) !== 0 ? 'text-amber-700' : ''}`}>{reportMoney(day.corrections)}</td>
                                <td className={`${NUM} font-semibold`}>{reportMoney(day.balance)}</td>
                            </tr>
                        ))}
                    </tbody>
                    <tfoot>
                        <tr className="border-t-2 border-gray-200 bg-gray-50 font-bold">
                            <td className={TD}>المجموع</td>
                            <td className={NUM}>{total('charges')}</td>
                            <td className={NUM}>{total('payments')}</td>
                            <td className={NUM}>{total('discounts')}</td>
                            <td className={NUM}>{total('corrections')}</td>
                            <td className={NUM}>{reportMoney(days[days.length - 1].balance)}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </Panel>
    );
}

function BranchComparison({ rows, filters }) {
    const largest = Math.max(1, ...rows.map((row) => Number(row.collections.total)));
    const sorted = [...rows].sort((first, second) => Number(second.collections.total) - Number(first.collections.total));
    const sum = (pick) => reportMoney(rows.reduce((total, row) => total + cents(pick(row)), 0) / 100);

    return (
        <Panel icon="layers" title="مقارنة الفروع" hint="نفس الفترة · الأعلى تحصيلًا أولًا · اضغط اسم الفرع لتقريره" excelParts={async () => [branchesPart(sorted)]}>
            <div className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead>
                        <tr>
                            <th className={TH}>الفرع</th>
                            <th className={NUM_TH}>إجمالي المحصّل</th>
                            <th className={`${TH} w-32`}><span className="sr-only">الحصة</span></th>
                            <th className={NUM_TH}>نقدي</th>
                            <th className={NUM_TH}>الدفعات</th>
                            <th className={NUM_TH}>الرصيد الختامي</th>
                        </tr>
                    </thead>
                    <tbody>
                        {sorted.map((row) => (
                            <tr key={row.id} className="border-t border-gray-100">
                                <td className={TD}>
                                    <Link href={`/reports?${new URLSearchParams({ ...filters, branch: row.id }).toString()}`} className="font-semibold text-gray-900 hover:text-brand-600">{row.name}</Link>
                                </td>
                                <td className={`${NUM} font-bold`}>{reportMoney(row.collections.total)}</td>
                                <td className={TD}><MeterBar value={Number(row.collections.total)} max={largest} /></td>
                                <td className={NUM}>{reportMoney(row.collections.cash)}</td>
                                <td className={`${NUM} text-gray-500`}>{row.collections.count}</td>
                                <td className={NUM}>{reportMoney(row.flow.closing)}</td>
                            </tr>
                        ))}
                    </tbody>
                    <tfoot>
                        <tr className="border-t-2 border-gray-200 bg-gray-50 font-bold">
                            <td className={TD}>المجموع</td>
                            <td className={NUM}>{sum((row) => row.collections.total)}</td>
                            <td />
                            <td className={NUM}>{sum((row) => row.collections.cash)}</td>
                            <td className={NUM}>{rows.reduce((total, row) => total + row.collections.count, 0)}</td>
                            <td className={NUM}>{sum((row) => row.flow.closing)}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </Panel>
    );
}

/** Every line of the period, newest first, narrowed to one kind. */
function Transactions({ transactions, kinds, filters, showDay, showBranch, onKind, onStatement }) {
    return (
        <Panel icon="list" title="الحركات" hint={`${transactions.total} حركة`} excelParts={async (options) => [linesPart(await loadReportLines(new URLSearchParams(Object.entries(filters).filter(([, value]) => value != null)).toString(), options), { showBranch })]}>
            <p className="mb-3 text-xs leading-6 text-gray-500">تصفية النوع تخص الحركات التفصيلية وتصديرها فقط؛ الملخص والتحصيل يعرضان جميع أنواع الحركات للفترة والفرع المحددين.</p>
            <div className="mb-4 overflow-x-auto pb-1">
                <SegmentedTabs label="نوع الحركات" value={filters.kind} onChange={onKind} options={kinds} />
            </div>
            <div className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead>
                        <tr>
                            <th className={TH}>الوقت</th>
                            <th className={TH}>السند</th>
                            <th className={TH}>المشترك</th>
                            {showBranch && <th className={TH}>الفرع</th>}
                            <th className={TH}>البيان</th>
                            <th className={TH}>الحساب</th>
                            <th className={NUM_TH}>عليه</th>
                            <th className={NUM_TH}>له</th>
                            <th className={TH}>سجّله</th>
                        </tr>
                    </thead>
                    <tbody>
                        {transactions.data.length === 0 && (
                            <tr>
                                <td colSpan={showBranch ? 9 : 8} className="px-3 py-10 text-center text-gray-500">لا حركات في هذه الفترة.</td>
                            </tr>
                        )}
                        {transactions.data.map((line) => (
                            <tr key={line.id} className={`border-t border-gray-100 align-top ${line.isCancelled ? 'text-gray-400 line-through decoration-gray-300' : ''}`}>
                                <td className={`${TD} whitespace-nowrap font-display text-gray-600`}>
                                    {line.time}
                                    {showDay && <small className="block text-xs text-gray-500">{shortDate(line.day)}</small>}
                                </td>
                                <td className={`${TD} font-display`}>{line.voucherNumber ?? '—'}</td>
                                <td className={TD}>
                                    <span className="font-semibold text-gray-900">{onStatement ? <button type="button" className="text-brand-600 hover:underline" onClick={() => onStatement(line.subscriptionId)}>{line.subscriptionName}</button> : line.subscriptionName}</span>
                                    <small className="block font-display text-xs text-gray-500">
                                        {line.accountNumber}
                                        {line.meterBoxNumber && ` · ${line.meterBoxNumber}`}
                                    </small>
                                </td>
                                {showBranch && <td className={TD}>{line.branchName}</td>}
                                <td className={`${TD} max-w-[260px]`}>
                                    <b className="font-semibold text-gray-800">{line.typeLabel}</b>
                                    <small className="block text-xs text-gray-500">{line.description}</small>
                                </td>
                                <td className={TD}>
                                    {line.account ?? '—'}
                                    {line.currency && line.currency !== 'ILS' && (
                                        <small className="block font-display text-xs text-gray-500">{line.currencyAmount} {line.currency}</small>
                                    )}
                                </td>
                                <td className={NUM}>{line.isCredit ? '—' : reportMoney(Math.abs(Number(line.amount)))}</td>
                                <td className={`${NUM} text-emerald-700`}>{line.isCredit ? reportMoney(Math.abs(Number(line.amount))) : '—'}</td>
                                <td className={`${TD} text-gray-600`}>{line.recordedBy ?? '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <div className="mt-4">
                <Pagination meta={transactions} filters={filters} baseUrl="/reports" />
            </div>
        </Panel>
    );
}
