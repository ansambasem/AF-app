import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Icon from '@/Components/Icon';
import KpiTile from '@/Components/KpiTile';
import PrimaryButton from '@/Components/PrimaryButton';
import SearchableSelect from '@/Components/SearchableSelect';
import { formatMoney } from '@/lib/format';
import {
    boxTypes,
    BoxCard,
    BoxStage,
    BoxTypeIcon,
    CashBoxAction,
    CollectorAuditNotes,
    CollectorDebts,
    boxAction,
    actionLabel,
} from './CollectorCashBox';
import PageHeader from '@/Components/PageHeader';
import InfoTip from '@/Components/InfoTip';

const PARTS = [
    { key: 'cash', icon: 'banknotes', label: 'نقدًا' },
    { key: 'transfers', icon: 'bank', label: 'تحويلات بنكية' },
    { key: 'clearings', icon: 'layers', label: 'مقاصة' },
];

/** The choices the summary follows: the branch (Super Admin), the period, and the user (for whoever may see the staff's). */
function SummaryFilters({ summary }) {
    const { filters, options } = summary;

    function apply(changes) {
        const query = Object.fromEntries(Object.entries({ ...filters, ...changes }).filter(([, value]) => value !== '' && value !== null && value !== undefined));
        router.get('/payments/summary', query, { preserveScroll: true, preserveState: true, replace: true });
    }

    return (
        <div className="mb-6 flex flex-wrap items-end gap-4 rounded-panel border border-gray-100 bg-surface p-4 shadow-card">
            {options.branches.length > 0 && (
                <label className="grid w-full gap-1.5 text-sm font-semibold text-gray-700 sm:w-60">
                    الفرع
                    <SearchableSelect clearable={false} value={filters.branch_id} options={options.branches} onChange={(value) => apply({ branch_id: value, user: '', period: '' })} />
                </label>
            )}
            <label className="grid w-full gap-1.5 text-sm font-semibold text-gray-700 sm:w-72">
                الفترة
                <SearchableSelect clearable={false} searchable={options.periods.length > 8} value={filters.period} options={options.periods} onChange={(value) => apply({ period: value })} />
            </label>
            {summary.seesStaff ? (
                <label className="grid w-full gap-1.5 text-sm font-semibold text-gray-700 sm:w-64">
                    المستخدم
                    <SearchableSelect clearable={false} value={filters.user} options={[{ value: '', label: 'جميع المستخدمين' }, ...options.users]} onChange={(value) => apply({ user: value })} searchPlaceholder="ابحث عن موظف..." />
                </label>
            ) : (
                <p className="flex items-center gap-2 pb-2 text-sm text-gray-500">
                    <Icon name="lock" className="h-4 w-4 text-gray-400" />
                    ملخص تحصيلك أنت فقط
                </p>
            )}
        </div>
    );
}

/** The chosen collection in one wide card: the total, how many, and how it was paid. */
function CollectionTotal({ summary }) {
    const who = summary.user ? (summary.user.isYou ? 'ما حصّلته' : `ما حصّله ${summary.user.name}`) : 'تحصيل جميع المستخدمين';

    return (
        <section aria-label="ملخص التحصيل" className="mb-6">
            <KpiTile hero label={`${who} · ${summary.period.label}`} value={formatMoney(summary.total)} unit="₪" hint={`${summary.count.toLocaleString('en')} ${summary.count === 1 ? 'عملية' : 'عمليات'}${summary.branch ? ` · ${summary.branch.name}` : ''}`}>
                <dl className="mt-5 grid gap-3 border-t border-white/10 pt-4 sm:grid-cols-3">
                    {PARTS.map((part) => (
                        <div key={part.key} className="flex items-center gap-3">
                            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white/10 text-white/80">
                                <Icon name={part.icon} className="h-[18px] w-[18px]" />
                            </span>
                            <dt className="text-sm text-white/70">{part.label}</dt>
                            <dd className="ms-auto flex items-baseline gap-1.5 sm:ms-0">
                                <span className="font-display text-2xl font-bold">{formatMoney(summary[part.key])}</span>
                                <span className="text-xs text-white/60">₪</span>
                            </dd>
                        </div>
                    ))}
                </dl>
            </KpiTile>
        </section>
    );
}

/** Each user's share of the collection, when every user is shown. */
function CollectionByUser({ rows }) {
    return (
        <section className="mb-8 overflow-x-auto rounded-panel border border-gray-100 bg-surface shadow-card">
            <h3 className="px-5 pt-5 text-lg font-bold text-gray-900">التحصيل حسب المستخدم</h3>
            <table className="mt-3 w-full text-sm" aria-label="التحصيل حسب المستخدم">
                <thead className="bg-gray-50 text-gray-600">
                    <tr>
                        {['المستخدم', 'العمليات', 'نقدًا', 'تحويلات بنكية', 'مقاصة', 'الإجمالي'].map((label) => <th key={label} className="px-4 py-2 text-start font-semibold">{label}</th>)}
                    </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                    {rows.length === 0 && <tr><td colSpan={6} className="px-4 py-8 text-center text-gray-500">لا تحصيل في هذه الفترة.</td></tr>}
                    {rows.map((row) => (
                        <tr key={row.id}>
                            <td className="px-4 py-2 font-semibold text-gray-900">{row.name}{row.isYou && <span className="text-gray-500"> (أنت)</span>}</td>
                            <td className="px-4 py-2 tabular-nums">{row.count.toLocaleString('en')}</td>
                            {['cash', 'transfers', 'clearings', 'total'].map((key) => <td key={key} className={`px-4 py-2 tabular-nums ${key === 'total' ? 'font-bold' : ''}`} dir="ltr">{formatMoney(row[key])}</td>)}
                        </tr>
                    ))}
                </tbody>
            </table>
        </section>
    );
}

/**
 * The collection summary: the total collected in the chosen period —
 * the user's own, or for whoever may look over the branch's collections
 * every user's or one picked user's — with how it was paid, then what
 * waits on the user (a handover to confirm, audit notes, debts) and
 * their own open boxes.
 */
export default function Summary({ summary, cashBox, current, needsAction, pendingCount }) {
    const [selected, setSelected] = useState(null);
    const [saved, setSaved] = useState(null);

    return <AuthenticatedLayout badges={{ '/payments/summary': pendingCount }} header={<PageHeader eyebrow={summary.branch?.name ?? cashBox.branchName ?? 'حسابك الحالي'} title="ملخص التحصيل" />}>
        <Head title="ملخص التحصيل" />
        {saved && <p role="status" className="mb-4 rounded-control bg-emerald-500/10 p-4 text-sm text-emerald-700">{saved}</p>}
        <SummaryFilters summary={summary} />
        <CollectionTotal summary={summary} />
        {summary.byUser && <CollectionByUser rows={summary.byUser} />}
        {cashBox.started ? <div className="space-y-8">
            {needsAction.length > 0 && <section className="space-y-3 rounded-panel border border-amber-500/25 bg-amber-500/5 p-5">
                <h3 className="font-bold text-gray-900">تحتاج إجراء منك</h3>
                {needsAction.map((box) => <div key={box.id} className="flex flex-wrap items-center justify-between gap-3 border-t border-amber-500/15 pt-3"><div className="flex items-center gap-3"><BoxTypeIcon type={box.type} className="h-10 w-10" /><div className="space-y-2"><Link href={box.urls.show} prefetch className="font-semibold text-gray-900">{boxTypes[box.type]} · <bdi dir="ltr" className="font-display text-xs">{box.number}</bdi></Link><BoxStage box={box} /></div></div><PrimaryButton onClick={() => setSelected({ box, action: boxAction(box) })}>{actionLabel(box)}</PrimaryButton></div>)}
            </section>}
            <CollectorAuditNotes notes={cashBox.auditNotes} onSaved={() => setSaved('أُرسل ردّك إلى الفرع.')} />
            <CollectorDebts debts={cashBox.debts} />

            <section aria-labelledby="open-boxes" className="space-y-4">
                <div className="flex items-center gap-1">
                    <h3 id="open-boxes" className="text-lg font-bold text-gray-900">صناديقي المفتوحة</h3>
                    <InfoTip label="عن صناديقي المفتوحة">لكل نوع صندوق خاص به؛ يراجعه الفرع ويؤكد إغلاقه. حركات كل صندوق تجدها داخله.</InfoTip>
                </div>
                <div className="grid gap-4 lg:grid-cols-3">
                    {Object.keys(boxTypes).map((type) => <BoxCard key={type} type={type} box={current[type]} />)}
                </div>
            </section>
        </div> : null}
        {selected && <CashBoxAction key={`${selected.box.id}-${selected.action}`} {...selected} onClose={() => setSelected(null)} onSuccess={() => setSaved('تم تأكيد التسليم.')} />}
    </AuthenticatedLayout>;
}
