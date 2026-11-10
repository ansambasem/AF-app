import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import PageHeader from '@/Components/PageHeader';
import { useDeleteRecord } from '@/hooks/useDeleteRecord';
import SubAreaModal from '../SubAreas/SubAreaModal';
import HierarchyColumn from './HierarchyColumn';
import { areaBoxesHref, areasCountLabel, boxesCountLabel } from '@/lib/branchAreas';

/**
 * The branch's areas: the branches on one side (only the Super Admin has
 * more than their own), the chosen branch's areas on the other, added and
 * renamed in place. Picking an area opens its meter boxes. Areas that
 * belong to no branch yet are listed for the Super Admin to assign.
 */
export default function Index({ branches, branch, areas, unassignedAreas, canChooseBranch }) {
    const [activeLevel, setActiveLevel] = useState(branch && !canChooseBranch ? 1 : 0);
    const [modalArea, setModalArea] = useState(null);
    const { requestDelete, deleteDialog } = useDeleteRecord('المنطقة');

    function pickBranch(option) {
        setActiveLevel(1);
        router.get('/branch-areas', { selected: option.id }, { preserveState: true, preserveScroll: true, replace: true });
    }

    return (
        <SettingsLayout header={<PageHeader title="مناطق الفروع" info="لكل فرع مناطقه، ولكل منطقة طبلوناتها. اختر الفرع لتظهر مناطقه، وأضف أو عدّل في مكانها، واضغط على منطقة لفتح طبلوناتها." />}>
            <Head title="مناطق الفروع" />

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
                    onPick={(area) => router.visit(areaBoxesHref(area))}
                    subtitle={(area) => boxesCountLabel(area.boxesCount)}
                    canAdd={Boolean(branch?.canCreateArea)}
                    createRequest={{ method: 'post', url: '/sub-areas', data: { branch_id: branch?.id } }}
                    updateRequest={(area) => ({ method: 'put', url: `/sub-areas/${area.id}`, data: { branch_id: area.branch_id ?? '' } })}
                    onMore={canChooseBranch ? setModalArea : undefined}
                    onDelete={(area) => requestDelete(`/sub-areas/${area.id}`, area.name, 'المنطقة')}
                    emptyText="لا توجد مناطق في هذا الفرع بعد"
                    active={activeLevel === 1}
                    onBack={canChooseBranch ? () => setActiveLevel(0) : undefined}
                />
            </div>

            {unassignedAreas.length > 0 && (
                <section aria-label="مناطق بلا فرع" className="mt-6 rounded-card border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950">
                    <h2 className="text-sm font-bold text-amber-900 dark:text-amber-200">مناطق لا تتبع أي فرع بعد ({unassignedAreas.length})</h2>
                    <p className="mt-1 text-xs text-amber-800 dark:text-amber-300">لم يُعرف فرعها عند نقل المناطق من المحافظات. اختر لكل منها فرعها.</p>
                    <ul className="mt-3 flex flex-wrap gap-2">
                        {unassignedAreas.map((area) => (
                            <li key={area.id}>
                                <button type="button" onClick={() => setModalArea(area)} className="rounded-control border border-amber-300 bg-surface px-3 py-1.5 text-sm font-semibold text-gray-800 hover:border-amber-500">
                                    {area.name} · {boxesCountLabel(area.boxesCount)}
                                </button>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {modalArea && <SubAreaModal key={modalArea.id} show onClose={() => setModalArea(null)} subArea={modalArea} branches={branches} canChooseBranch={canChooseBranch} />}
            {deleteDialog}
        </SettingsLayout>
    );
}
