import FormModal from '@/Components/FormModal';
import { useResourceForm } from '@/hooks/useResourceForm';
import TariffForm, { tariffFormData } from './TariffForm';

export default function TariffModal({ show, onClose, tariff, categoryOptions }) {
    const form = useResourceForm('/tariffs', tariff, tariffFormData(tariff, categoryOptions));

    return (
        <FormModal show={show} onClose={onClose} form={form} title={form.isEdit ? 'تعديل التعرفة' : 'إنشاء تعرفة'} icon="currency" maxWidth="3xl">
            <TariffForm data={form.data} setData={form.setData} errors={form.errors} categoryOptions={categoryOptions} />
        </FormModal>
    );
}
