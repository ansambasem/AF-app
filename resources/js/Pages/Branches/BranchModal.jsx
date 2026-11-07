import FormModal from '@/Components/FormModal';
import { useResourceForm } from '@/hooks/useResourceForm';
import BranchForm, { branchFormData } from './BranchForm';

export default function BranchModal({ show, onClose, branch, governorates, areas, visitOptions }) {
    const form = useResourceForm('/branches', branch, branchFormData(branch));

    return (
        <FormModal show={show} onClose={onClose} form={form} title={form.isEdit ? 'تعديل الفرع' : 'إنشاء فرع'} icon="building" maxWidth="3xl" visitOptions={visitOptions}>
            <BranchForm data={form.data} setData={form.setData} errors={form.errors} governorates={governorates} areas={areas} />
        </FormModal>
    );
}
