import FormModal from '@/Components/FormModal';
import { useResourceForm } from '@/hooks/useResourceForm';
import SubAreaForm, { subAreaFormData } from './SubAreaForm';

export default function SubAreaModal({ show, onClose, subArea, branches, canChooseBranch, defaultBranchId = '' }) {
    const form = useResourceForm('/sub-areas', subArea, subAreaFormData(subArea, defaultBranchId));

    return (
        <FormModal show={show} onClose={onClose} form={form} title={form.isEdit ? 'تعديل المنطقة' : 'إنشاء منطقة'} icon="map" maxWidth="3xl">
            <SubAreaForm data={form.data} setData={form.setData} errors={form.errors} branches={branches} canChooseBranch={canChooseBranch} />
        </FormModal>
    );
}
