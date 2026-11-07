import { Head, router, useForm, usePage } from '@inertiajs/react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import FormSection from '@/Components/Form/FormSection';
import FormField from '@/Components/Form/FormField';
import SearchableSelect from '@/Components/SearchableSelect';
import PrimaryButton from '@/Components/PrimaryButton';
import CurrencyRatesSection from './CurrencyRatesSection';
import BanksSection from './BanksSection';
import PageHeader from '@/Components/PageHeader';

const weekdays = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];

export default function Payments({ currencies, banks, storeUrl, boxPeriod, canManagePaymentCatalog }) {
    const { status } = usePage().props;
    const form = useForm({ week_starts_on: boxPeriod.week_starts_on, branch_id: boxPeriod.branch?.id ?? null });

    function savePeriod(event) {
        event.preventDefault();
        form.put(boxPeriod.updateUrl, { preserveScroll: true, onSuccess: () => form.setDefaults() });
    }

    return <SettingsLayout header={<PageHeader title="إعدادات الدفعات" />}>
        <Head title="إعدادات الدفعات" />
        <div className="space-y-6">
            {status && <p role="status" className="rounded-control bg-emerald-500/10 p-4 text-sm text-emerald-700 dark:text-emerald-300">{status}</p>}
            <FormSection icon="calendar" title="فترات الصناديق المالية" info="تُعرض الصناديق المغلقة في فترات أسبوعية حسب تاريخ إغلاقها، دون تغيير حركاتها أو مبالغها. عند بداية الفترة الجديدة تُغلق صناديق الفترة المنتهية تلقائيًا بحركاتها وتظهر لقسم التدقيق، دون اعتماد التدقيق تلقائيًا." columns={1}>
                {boxPeriod.canSelectBranch ? <FormField id="box-period-branch" label="نطاق الإعداد" span="w-full sm:max-w-sm">
                    <SearchableSelect value={boxPeriod.branch?.id ?? ''} clearable={false} options={[{ value: '', label: 'الإعداد الافتراضي للشركة' }, ...boxPeriod.branches]} onChange={(value) => router.get(boxPeriod.indexUrl, value === '' ? {} : { branch: value }, { preserveScroll: true })} />
                </FormField> : <p className="text-sm font-semibold text-gray-900">إعدادات فرع {boxPeriod.branch.name}</p>}
                {boxPeriod.followsCompany && <p className="text-xs text-gray-500">يتبع هذا الفرع الإعداد الافتراضي للشركة حاليًا. عند الحفظ يُصبح له يوم بداية خاص به.</p>}
                <form onSubmit={savePeriod} className="flex flex-wrap items-end gap-4">
                    <FormField id="box-week-start" label="يوم بداية الفترة الأسبوعية" error={form.errors.week_starts_on} span="w-full sm:w-72" required>
                        <SearchableSelect name="week_starts_on" required clearable={false} searchable={false} value={form.data.week_starts_on} onChange={(value) => form.setData('week_starts_on', Number(value))} options={weekdays.map((label, value) => ({ value, label }))} />
                    </FormField>
                    <PrimaryButton type="submit" disabled={form.processing || (!form.isDirty && !boxPeriod.followsCompany)}>{form.processing ? 'جارٍ الحفظ…' : 'حفظ بداية الفترة'}</PrimaryButton>
                    <p className="w-full text-sm text-gray-500">من {weekdays[form.data.week_starts_on]} إلى {weekdays[(Number(form.data.week_starts_on) + 6) % 7]} · {boxPeriod.branch ? `لفرع ${boxPeriod.branch.name} فقط` : 'الإعداد الافتراضي للفروع التي ليس لها إعداد خاص'}</p>
                </form>
            </FormSection>
            {canManagePaymentCatalog && <><CurrencyRatesSection currencies={currencies} /><BanksSection banks={banks} storeUrl={storeUrl} /></>}
        </div>
    </SettingsLayout>;
}
