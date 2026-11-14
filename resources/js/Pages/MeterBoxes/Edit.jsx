import FormPage from '@/Components/FormPage';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { useResourceForm } from '@/hooks/useResourceForm';
import MeterBoxForm, { meterBoxFormData } from './MeterBoxForm';

export default function Edit({ meterBox, branches, canChooseBranch, canCreateSubArea, subAreas, currentBranchId }) {
    const form = useResourceForm('/meter-boxes', meterBox, meterBoxFormData(meterBox));

    return (
        <FormPage layout={SettingsLayout} title="تعديل الطبلون" form={form} cancelHref="/meter-boxes" widthClass="max-w-4xl">
            <MeterBoxForm
                data={form.data}
                setData={form.setData}
                errors={form.errors}
                branches={branches}
                canChooseBranch={canChooseBranch}
                canCreateSubArea={canCreateSubArea}
                subAreas={subAreas}
                currentBranchId={currentBranchId}
            />
        </FormPage>
    );
}
