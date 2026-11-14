import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import PageHeader from '@/Components/PageHeader';
import { useDeleteRecord } from '@/hooks/useDeleteRecord';
import HierarchyColumn from './HierarchyColumn';
import { areaBoxesHref, areasCountLabel, boxesCountLabel } from '@/lib/branchAreas';

/**
 * The branch's areas: the branches on one side (only the Super Admin has
 * more than their own), the chosen branch's areas on the other, added and
 * renamed in place. Picking an area opens its meter boxes. What the data
 * leaves undecided (a container two branches share, an area in no branch)
 * is listed for the Super Admin as it is, unchanged.
 */
export default function Index({ branches, branch, areas, review, canChooseBranch }) {
    const [activeLevel, setActiveLevel] = useState(branch && !canChooseBranch ? 1 : 0);
    const { requestDelete, deleteDialog } = useDeleteRecord('المنطقة');

    function pickBranch(option) {
        setActiveLevel(1);
        router.get('/branch-areas', { selected: option.id }, { preserveState: true, preserveScroll: true, replace: true });
    }

    return (
        <SettingsLayout header={<PageHeader title="مناطق الفروع" info="لكل فرع مناطقه، ولكل منطقة طبلوناتها. اختر الفرع لتظهر مناطقه، وأضف أو عدّل في مكانها، واضغط على منطقة لفتح طبلوناتها." />}>
            <Head title="مناطق الفروع" />

            {branch?.sharedWith.length > 0 && (
                <p role="status" className="mt-3 rounded-control border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
                    مناطق هذا الفرع مسجّلة مشتركة مع: {branch.sharedWith.join('، ')}. تظهر هنا كما هي، ولا تُضاف إليها مناطق حتى يُحسم فرعها.
                </p>
            )}

            <div className={`mt-3 grid grid-cols-1 overflow-hidden rounded-card border border-gray-100 bg-surface shadow-card lg:min-h-[30rem] ${canChooseBranch ? 'lg:grid-cols-2' : ''}`}>
                {canChooseBranch && (
                    <HierarchyColumn
                        title="الفروع"
                        noun="الفرع"
                        items={branches}
                        selectedId={branch?.id}
                        onPick={pickBranch}
                        subtitle={(option) => areasCountLabel(option.areasCount)}
                        emptyText="لا توجد فروع بعد"
                        active={activeLevel === 0}
                    />
                )}

                {/* Keyed by the branch so an add or rename in progress doesn't carry over to another branch's areas. */}
                <HierarchyColumn
                    key={`areas-${branch?.id ?? 'none'}`}
                    title={branch ? `مناطق ${branch.name}` : 'المناطق'}
                    noun="المنطقة"
                    items={branch ? areas : null}
                    waiting={{ title: 'اختر فرعًا أولًا', text: 'من عمود الفروع.' }}
                    onPick={(area) => router.visit(areaBoxesHref(branch.id, area))}
                    subtitle={(area) => boxesCountLabel(area.boxesCount)}
                    canAdd={Boolean(branch?.canCreateArea)}
                    createRequest={{ method: 'post', url: '/sub-areas', data: { branch_id: branch?.id } }}
                    updateRequest={(area) => ({ method: 'put', url: `/sub-areas/${area.id}`, data: {} })}
                    onDelete={(area) => requestDelete(`/sub-areas/${area.id}`, area.name, 'المنطقة')}
                    emptyText="لا توجد مناطق في هذا الفرع بعد"
                    active={activeLevel === 1}
                    onBack={canChooseBranch ? () => setActiveLevel(0) : undefined}
                />
            </div>

            {review && (review.sharedContainers.length > 0 || review.unattachedAreas.length > 0) && (
                <section aria-label="حالات تحتاج قرارًا" className="mt-6 space-y-3 rounded-card border border-amber-200 bg-amber-50 p-4 text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
                    <h2 className="text-sm font-bold">حالات لا يحدد فيها النظام فرع المنطقة بشكل مؤكد</h2>
                    <p className="text-xs">معروضة كما هي في البيانات، ولم يُغيَّر أو يُنقل منها شيء.</p>
                    {review.sharedContainers.map((shared) => (
                        <p key={shared.branches.join('|')} className="text-sm">فروع تشترك في مناطق واحدة ({shared.areas}): {shared.branches.join('، ')}</p>
                    ))}
                    {review.unattachedAreas.length > 0 && (
                        <p className="text-sm">مناطق لا تتبع أي فرع: {review.unattachedAreas.map((area) => `${area.name} (${boxesCountLabel(area.boxesCount)})`).join('، ')}</p>
                    )}
                </section>
            )}

            {deleteDialog}
        </SettingsLayout>
    );
}
