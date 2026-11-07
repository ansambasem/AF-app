import FormModal from '@/Components/FormModal';
import { useResourceForm } from '@/hooks/useResourceForm';
import SubAreaForm, { subAreaFormData } from './SubAreaForm';

export default function SubAreaModal({ show, onClose, subArea, areas, defaultAreaId = '', allowNoArea = true }) {
    const form = useResourceForm('/sub-areas', subArea, subAreaFormData(subArea, defaultAreaId));

    return (
        <FormModal show={show} onClose={onClose} form={form} title={form.isEdit ? 'تعديل منطقة 2' : 'إنشاء منطقة 2'} icon="map" maxWidth="3xl">
            <SubAreaForm data={form.data} setData={form.setData} errors={form.errors} areas={areas} allowNoArea={allowNoArea} />
        </FormModal>
    );
}
