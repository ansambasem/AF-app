import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Icon from '@/Components/Icon';
import { BranchFilterBanner } from '@/Components/BranchBanner';
import PrimaryButton from '@/Components/PrimaryButton';
import { useDataTable } from '@/hooks/useDataTable';
import BoxTabs from './BoxTabs';
import CloseBoxesByType from './CloseBoxesByType';
import OpenEntriesTable from './OpenEntriesTable';
import PageHeader from '@/Components/PageHeader';

/**
 * The open entries tab of the financial boxes page, for those who close
 * the branch's boxes: every entry of the branch's open boxes, theirs among
 * them, which a filter narrows to one person's and another to one type of
 * box. The entries of a closed box are looked over inside that box, on the
 * boxes tab. Those who may confirm the branch's boxes closed can close
 * every open box of one type at once.
 */
export default function Entries({ started, branch, branches, branchScope = null, people, tabs, entries, filters, counts, closableBoxes = null, pendingCount, urls }) {
    const [closingByType, setClosingByType] = useState(false);
    const extraParams = { branch_id: branch?.id ?? '' };
    const table = useDataTable(urls.index, filters, extraParams);

    return <AuthenticatedLayout badges={{ '/payments/summary': pendingCount }} scope={branchScope?.name ?? null} header={<PageHeader
        eyebrow={branchScope ? null : branch?.name}
        title="الصناديق المالية"
        titleAddon={<BoxTabs tabs={tabs} current="entries" />}
        info={started ? 'تعرض هذه الصفحة حركات الصناديق المفتوحة فقط؛ حركات الصناديق المغلقة تجدها داخل كل صندوق في تبويب «الصناديق».' : null}
        actions={started && closableBoxes && <PrimaryButton type="button" onClick={() => setClosingByType(true)} disabled={!closableBoxes.length}><Icon name="lock" className="h-4 w-4" />إغلاق الصناديق حسب النوع{closableBoxes.length > 0 && <span className="rounded-full bg-white/20 px-2 py-0.5 font-display text-xs">{closableBoxes.length}</span>}</PrimaryButton>}
    />}>
        <Head title="الحركات" />
        {branchScope && <BranchFilterBanner scope={branchScope} eyebrow="تعرض الآن حركات صناديق" group={{ options: branches.map((item) => ({ value: String(item.id), label: item.name })) }} value={branch ? String(branch.id) : ''} onChange={(value) => router.get(urls.index, { branch_id: value })} />}
        {!started ? <p className="rounded-panel border border-dashed border-gray-200 bg-surface p-6 text-sm leading-7 text-gray-500">تُفتح الصناديق تلقائيًا عند تسجيل أول دفعة أو مقاصة في الفرع.</p> : (
            <OpenEntriesTable table={table} entries={entries} filters={filters} counts={counts} url={urls.index} extraParams={extraParams} people={people} label="حركات الفرع" />
        )}
        {closingByType && <CloseBoxesByType boxes={closableBoxes} branchId={branch.id} userId={table.filterValues.user} initialType={table.filterValues.type ?? ''} actionsUrl={urls.actions} onClose={() => setClosingByType(false)} />}
    </AuthenticatedLayout>;
}
