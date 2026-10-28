import FormModal from '@/Components/FormModal';
import { useResourceForm } from '@/hooks/useResourceForm';
import MeterBoxForm, { meterBoxFormData } from './MeterBoxForm';

export default function MeterBoxModal({ show, onClose, meterBox, branches, canChooseBranch, canCreateSubArea, governorates, areas, subAreas, currentBranchAreaId, branchId }) {
    const form = useResourceForm('/meter-boxes', meterBox, { ...meterBoxFormData(meterBox), branch_id: meterBox?.branch_id ?? branchId ?? '' });

    return (
        <FormModal show={show} onClose={onClose} form={form} title={form.isEdit ? 'تعديل الطبلون' : 'إنشاء طبلون'} icon="table" maxWidth="3xl">
            <MeterBoxForm
                data={form.data}
                setData={form.setData}
                errors={form.errors}
                branches={branches}
                canChooseBranch={canChooseBranch}
                canCreateSubArea={canCreateSubArea}
                governorates={governorates}
                areas={areas}
                subAreas={subAreas}
                currentBranchAreaId={currentBranchAreaId}
            />
        </FormModal>
    );
}
