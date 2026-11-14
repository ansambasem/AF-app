import { useForm } from '@inertiajs/react';
import FormModal from '@/Components/FormModal';
import FormField from '@/Components/Form/FormField';
import FormSection from '@/Components/Form/FormSection';
import TextInput from '@/Components/TextInput';
import Switch from '@/Components/Switch';

export default function DomainModal({ branch, onClose, routingEnabled }) {
    const form = useForm({ domain: branch.domain ?? '', domain_enabled: Boolean(branch.domain_enabled) });
    return <FormModal show onClose={onClose} form={{ ...form, isEdit: true, save: (options) => form.put(`/branch-domains/${branch.id}`, options) }} title={`رابط الفرع · ${branch.name}`} icon="building" maxWidth="2xl">
        <FormSection icon="building" title="دومين الفرع" columns={1}>
            <FormField id="domain" label="اسم الدومين" error={form.errors.domain} hint="مثل jenin.company.com — دون https أو مسار.">
                <TextInput dir="ltr" className="w-full" value={form.data.domain} onChange={(e) => form.setData('domain', e.target.value)} placeholder="jenin.company.com" autoComplete="off" />
            </FormField>
            <Switch checked={form.data.domain_enabled} onChange={(value) => form.setData('domain_enabled', value)} label="السماح بالدخول من هذا الرابط" ariaLabel="السماح بالدخول من هذا الرابط" />
            <div className="rounded-control border border-gray-200 bg-gray-50 p-3 text-sm leading-7 text-gray-600">بعد الحفظ، اربط الدومين بالاستضافة وفعّل HTTPS. {routingEnabled ? 'توجيه الفروع مفعّل على هذا النظام.' : 'توجيه الدومينات غير مفعّل على هذا النظام بعد؛ يمكنك تجهيز الروابط قبل النشر.'}</div>
        </FormSection>
    </FormModal>;
}
