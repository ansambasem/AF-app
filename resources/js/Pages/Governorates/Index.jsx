import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import Pagination from '@/Components/DataTable/Pagination';
import { useDataTable } from '@/hooks/useDataTable';
import { useDeleteRecord } from '@/hooks/useDeleteRecord';
import AreaModal from '../Areas/AreaModal';
import SubAreaModal from '../SubAreas/SubAreaModal';
import HierarchyColumn from './HierarchyColumn';
import PageHeader from '@/Components/PageHeader';

/** "منطقة واحدة", "منطقتان", "3 مناطق", "12 منطقة". */
function areasCountLabel(count) {
    if (count === 0) {
        return 'بلا مناطق';
    }
    if (count === 1) {
        return 'منطقة واحدة';
    }
    if (count === 2) {
        return 'منطقتان';
    }

    return count <= 10 ? `${count} مناطق` : `${count} منطقة`;
}

function subAreasCountLabel(count) {
    return count === 0 ? 'بلا منطقة 2' : `منطقة 2 · ${count}`;
}

/**
 * Governorates → areas → sub-areas (منطقة 2) side by side: pick a
 * governorate, then an area, and its sub-areas show in the third column.
 * Adding and renaming happen in place in each column; "تعديل كامل…" opens
 * the full form (e.g. to move an area to another governorate). On narrow
 * screens one column shows at a time, with the breadcrumb and a back
 * button to move between them.
 *
 * With `scopedToBranch` (a branch admin or branch staff) the page shows
 * only the user's own branch location, already selected: they can't
 * change governorates or areas, only the sub-areas inside their branch's
 * area.
 */
export default function Index({
    governorates,
    selectedGovernorate,
    selectedArea,
    scopedToBranch,
    canCreateGovernorate,
    filters,
    governorateOptions,
    areaOptions,
    allowSubAreaWithoutArea,
}) {
    const [activeLevel, setActiveLevel] = useState(selectedArea ? 2 : selectedGovernorate ? 1 : 0);
    const [modalArea, setModalArea] = useState(null);
    const [modalSubArea, setModalSubArea] = useState(null);
    const { requestDelete, deleteDialog } = useDeleteRecord('السجل');

    const extraParams = { selected: selectedGovernorate?.id, selectedArea: selectedArea?.id };
    const { search, setSearch } = useDataTable('/governorates', filters, extraParams);

    function visitSelection(selection) {
        router.get(
            '/governorates',
            { search, sort: filters.sort, direction: filters.direction, per_page: filters.per_page, ...selection },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    function showAllGovernorates() {
        setActiveLevel(0);
        if (!scopedToBranch && selectedGovernorate) {
            visitSelection({});
        }
    }

    function showGovernorate() {
        setActiveLevel(1);
        if (!scopedToBranch && selectedArea) {
            visitSelection({ selected: selectedGovernorate.id });
        }
    }

    const pageTitle = scopedToBranch ? 'مناطق الفرع' : 'المحافظات والمناطق';

    return (
        <SettingsLayout
            header={
                <PageHeader
                    title={pageTitle}
                    info={scopedToBranch ? 'منطقة 2 داخل منطقة فرعك. الإضافة والتعديل في مكانهما.' : 'اختر محافظة ثم منطقة لتظهر منطقة 2 داخلها. الإضافة والتعديل في مكانهما.'}
                />
            }
        >
            <Head title={pageTitle} />

            <nav aria-label="المسار" className="flex flex-wrap items-center gap-1 text-sm text-gray-500">
                <CrumbButton onClick={showAllGovernorates}>كل المحافظات</CrumbButton>
                {selectedGovernorate && (
                    <>
                        <span className="opacity-50">‹</span>
                        <CrumbButton onClick={showGovernorate}>{selectedGovernorate.name}</CrumbButton>
                    </>
                )}
                {selectedArea && (
                    <>
                        <span className="opacity-50">‹</span>
                        <CrumbButton onClick={() => setActiveLevel(2)}>{selectedArea.name}</CrumbButton>
                    </>
                )}
            </nav>

            <div className="mt-3 grid grid-cols-1 overflow-hidden rounded-card border border-gray-100 bg-surface shadow-card lg:min-h-[35rem] lg:grid-cols-3">
                <HierarchyColumn
                    title="المحافظات"
                    noun="المحافظة"
                    items={governorates.data}
                    total={governorates.total}
                    selectedId={selectedGovernorate?.id}
                    onPick={
                        scopedToBranch
                            ? undefined
                            : (governorate) => {
                                  setActiveLevel(1);
                                  visitSelection({ selected: governorate.id });
                              }
                    }
                    subtitle={(governorate) => areasCountLabel(governorate.areasCount)}
                    canAdd={canCreateGovernorate && !scopedToBranch}
                    createRequest={{ method: 'post', url: '/governorates', data: {} }}
                    updateRequest={(governorate) => ({ method: 'put', url: `/governorates/${governorate.id}`, data: {} })}
                    onDelete={(governorate) => requestDelete(`/governorates/${governorate.id}`, governorate.name, 'المحافظة')}
                    search={scopedToBranch ? undefined : search}
                    onSearchChange={scopedToBranch ? undefined : setSearch}
                    emptyText={scopedToBranch ? 'لم تُحدَّد محافظة لفرعك بعد' : 'لا توجد محافظات بعد'}
                    active={activeLevel === 0}
                    footer={
                        governorates.last_page > 1 && (
                            <div className="border-t border-gray-100 px-2">
                                <Pagination meta={governorates} filters={filters} baseUrl="/governorates" extraParams={extraParams} />
                            </div>
                        )
                    }
                />

                {/* Keyed by the governorate so an add/rename in progress
                    doesn't carry over to another governorate's areas. */}
                <HierarchyColumn
                    key={`areas-${selectedGovernorate?.id ?? 'none'}`}
                    title="المناطق"
                    noun="المنطقة"
                    items={selectedGovernorate?.areas ?? null}
                    waiting={{ title: 'اختر محافظة أولًا', text: 'من عمود المحافظات.' }}
                    selectedId={selectedArea?.id}
                    onPick={
                        scopedToBranch
                            ? undefined
                            : (area) => {
                                  setActiveLevel(2);
                                  visitSelection({ selected: selectedGovernorate.id, selectedArea: area.id });
                              }
                    }
                    subtitle={(area) => subAreasCountLabel(area.subAreasCount)}
                    canAdd={Boolean(selectedGovernorate?.canCreateArea)}
                    createRequest={{ method: 'post', url: '/areas', data: { governorate_id: selectedGovernorate?.id } }}
                    updateRequest={(area) => ({ method: 'put', url: `/areas/${area.id}`, data: { governorate_id: area.governorate_id ?? '' } })}
                    onMore={setModalArea}
                    onDelete={(area) => requestDelete(`/areas/${area.id}`, area.name, 'المنطقة')}
                    emptyText={scopedToBranch ? 'لم تُحدَّد منطقة لفرعك بعد' : 'لا توجد مناطق في هذه المحافظة بعد'}
                    active={activeLevel === 1}
                    onBack={scopedToBranch ? undefined : () => setActiveLevel(0)}
                />

                <HierarchyColumn
                    key={`sub-areas-${selectedArea?.id ?? 'none'}`}
                    title="منطقة 2"
                    noun="منطقة 2"
                    items={selectedArea?.subAreas ?? null}
                    waiting={
                        scopedToBranch
                            ? { title: 'لم تُحدَّد منطقة لفرعك بعد', text: 'اطلب من المدير العام تحديد منطقة الفرع لتتمكن من إضافة منطقة 2.' }
                            : { title: 'اختر منطقة أولًا', text: 'من عمود المناطق.' }
                    }
                    canAdd={Boolean(selectedArea?.canCreateSubArea)}
                    createRequest={{ method: 'post', url: '/sub-areas', data: { area_id: selectedArea?.id } }}
                    updateRequest={(subArea) => ({ method: 'put', url: `/sub-areas/${subArea.id}`, data: { area_id: subArea.area_id ?? '' } })}
                    onMore={setModalSubArea}
                    onDelete={(subArea) => requestDelete(`/sub-areas/${subArea.id}`, subArea.name, 'منطقة 2')}
                    emptyText="لا توجد منطقة 2 هنا بعد"
                    active={activeLevel === 2}
                    onBack={scopedToBranch ? undefined : () => setActiveLevel(1)}
                />
            </div>

            {/* Keyed by id so switching who's being edited remounts the form
                with fresh initial values — useForm() only captures its
                initial data once per mount. */}
            {modalArea && <AreaModal key={modalArea.id} show onClose={() => setModalArea(null)} area={modalArea} governorates={governorateOptions} />}

            {modalSubArea && (
                <SubAreaModal
                    key={modalSubArea.id}
                    show
                    onClose={() => setModalSubArea(null)}
                    subArea={modalSubArea}
                    areas={areaOptions}
                    allowNoArea={allowSubAreaWithoutArea}
                />
            )}

            {deleteDialog}
        </SettingsLayout>
    );
}

function CrumbButton({ onClick, children }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="rounded-lg px-1.5 py-1 font-bold text-gray-700 transition hover:bg-brand-500/10 hover:text-brand-600"
        >
            {children}
        </button>
    );
}
