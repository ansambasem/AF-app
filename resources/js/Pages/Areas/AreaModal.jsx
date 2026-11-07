import FormModal from '@/Components/FormModal';
import { useResourceForm } from '@/hooks/useResourceForm';
import AreaForm, { areaFormData } from './AreaForm';

export default function AreaModal({ show, onClose, area, governorates, defaultGovernorateId = '' }) {
    const form = useResourceForm('/areas', area, areaFormData(area, defaultGovernorateId));

    return (
        <FormModal show={show} onClose={onClose} form={form} title={form.isEdit ? 'تعديل المنطقة' : 'إنشاء منطقة'} icon="map" maxWidth="3xl">
            <AreaForm data={form.data} setData={form.setData} errors={form.errors} governorates={governorates} />
        </FormModal>
    );
}
