import { useEffect, useState } from 'react';
import { useForm } from '@inertiajs/react';
import AddButton from '@/Components/AddButton';
import FormModal from '@/Components/FormModal';
import FormField from '@/Components/Form/FormField';
import FormSection from '@/Components/Form/FormSection';
import FormPreview, { countFilled } from '@/Components/Form/FormPreview';
import Icon from '@/Components/Icon';
import TextInput from '@/Components/TextInput';
import Switch from '@/Components/Switch';
import DataTableToolbar from '@/Components/DataTable/DataTableToolbar';
import RowActionsMenu from '@/Components/DataTable/RowActionsMenu';
import ActionsTh from '@/Components/DataTable/ActionsTh';
import Pagination from '@/Components/DataTable/Pagination';
import StatusPill from '@/Components/DataTable/StatusPill';
import { BankMark } from '@/Components/Banks';

function BankLogoInput({ id, file, currentLogo, bankName, onChange, disabled, ...props }) {
    const [preview, setPreview] = useState(null);

    useEffect(() => {
        if (!file || !['image/png', 'image/jpeg', 'image/webp'].includes(file.type)) {
            setPreview(null);
            return;
        }

        const url = URL.createObjectURL(file);
        setPreview(url);
        return () => URL.revokeObjectURL(url);
    }, [file]);

    const image = preview ?? (!file ? currentLogo : null);

    return <div className="flex flex-wrap items-center gap-4 rounded-row border border-gray-200 bg-gray-50 p-4">
        <span className="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-control border border-gray-100 bg-surface text-gray-400">
            {image ? <img src={image} alt={`شعار ${bankName || 'البنك أو المحفظة'}`} className="h-full w-full object-contain p-1" /> : <Icon name="bank" className="h-7 w-7" />}
        </span>
        <div className="min-w-0 flex-1 basis-40">
            <p className="text-sm font-semibold text-gray-900">{file ? 'الشعار المختار' : currentLogo ? 'الشعار الحالي' : 'شعار البنك أو المحفظة'}</p>
            <p className="mt-1 break-all text-xs text-gray-500" dir="auto">{file?.name ?? (currentLogo ? 'سيبقى محفوظًا ما لم تختر صورة أخرى.' : 'لم يتم اختيار صورة بعد.')}</p>
        </div>
        <div className="relative shrink-0">
            <input {...props} id={id} name="logo" type="file" accept="image/png,image/jpeg,image/webp" disabled={disabled} className="peer sr-only" onChange={(event) => onChange(event.target.files?.[0] ?? null)} />
            <label htmlFor={id} className={`inline-flex items-center justify-center gap-2 rounded-control border border-gray-200 bg-surface px-4 py-2.5 text-sm font-semibold text-gray-900 transition peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-gray-900 ${disabled ? 'cursor-not-allowed opacity-40' : 'cursor-pointer hover:border-gray-300 hover:bg-gray-50'}`}>
                <Icon name="plus" className="h-4 w-4" />{file || currentLogo ? 'تغيير الشعار' : 'اختيار الشعار'}
            </label>
        </div>
    </div>;
}

function BankModal({ bank, storeUrl, onClose }) {
    const form = useForm({ name: bank?.name ?? '', is_receiver: bank?.is_receiver ?? true, is_sender: bank?.is_sender ?? true, is_active: bank?.is_active ?? true, logo: null, _method: bank ? 'put' : 'post' });
    const resourceForm = { ...form, isEdit: Boolean(bank), save: (options) => form.post(bank?.updateUrl ?? storeUrl, { ...options, forceFormData: true }) };

    return <FormModal show onClose={onClose} form={resourceForm} title={bank ? 'تعديل البنك' : 'إضافة بنك أو محفظة'} icon="bank" maxWidth="3xl">
        <div className="space-y-4">
            <FormPreview avatar={<Icon name="bank" className="h-6 w-6 text-white/70" />} dotClass={form.data.is_active ? 'bg-emerald-500' : 'bg-gray-400'} title={form.data.name.trim() || 'بنك أو محفظة جديدة'} chips={[form.data.is_receiver && 'البنك المستلم', form.data.is_sender && 'البنك المرسل', form.data.is_active ? 'نشط' : 'غير نشط'].filter(Boolean)} filled={countFilled(form.data, ['name'])} total={1} />
            <FormSection icon="bank" title="بيانات البنك أو المحفظة" columns={1}>
                <FormField id="bank-name" label="الاسم" required error={form.errors.name}><TextInput name="name" required maxLength={255} className="w-full" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} /></FormField>
                <FormField id="bank-logo" label="الشعار" hint="PNG أو JPG أو WebP، بحد أقصى 2 ميجابايت." error={form.errors.logo}>
                    <BankLogoInput file={form.data.logo} currentLogo={bank?.logoUrl} bankName={form.data.name} disabled={form.processing} onChange={(file) => form.setData('logo', file)} />
                </FormField>
            </FormSection>
            <FormSection icon="card" title="الاستخدام والحالة" columns={2}>
                <FormField id="bank-receiver" label="البنك المستلم" error={form.errors.is_receiver}><Switch checked={form.data.is_receiver} onChange={(value) => form.setData('is_receiver', value)} label="متاح لاستلام دفعات المشتركين" /></FormField>
                <FormField id="bank-sender" label="البنك المرسل" error={form.errors.is_sender}><Switch checked={form.data.is_sender} onChange={(value) => form.setData('is_sender', value)} label="متاح كبنك مرسل" /></FormField>
                <FormField id="bank-active" label="الحالة" span="sm:col-span-2" error={form.errors.is_active}><Switch checked={form.data.is_active} onChange={(value) => form.setData('is_active', value)} label="نشط ويظهر عند تسجيل دفعة جديدة" /></FormField>
            </FormSection>
        </div>
    </FormModal>;
}

export default function BanksSection({ banks, storeUrl }) {
    const [editing, setEditing] = useState(null);
    const [creating, setCreating] = useState(false);
    const [search, setSearch] = useState('');
    const [perPage, setPerPage] = useState(15);
    const [page, setPage] = useState(1);
    const filtered = banks.filter((bank) => bank.name.includes(search.trim()));
    const lastPage = Math.max(1, Math.ceil(filtered.length / perPage));
    const currentPage = Math.min(page, lastPage);
    const shown = filtered.slice((currentPage - 1) * perPage, currentPage * perPage);
    const meta = { current_page: currentPage, last_page: lastPage, from: (currentPage - 1) * perPage + 1, to: Math.min(currentPage * perPage, filtered.length), total: filtered.length };

    return <section aria-labelledby="payment-banks-title" className="space-y-4">
        <div className="flex flex-wrap items-center justify-between gap-3"><h3 id="payment-banks-title" className="text-xl font-bold text-gray-900">البنوك والمحافظ</h3><AddButton onClick={() => setCreating(true)}>إضافة بنك أو محفظة</AddButton></div>
        <DataTableToolbar search={search} onSearchChange={(value) => { setSearch(value); setPage(1); }} perPage={perPage} onPerPageChange={(value) => { setPerPage(value); setPage(1); }} placeholder="ابحث باسم البنك أو المحفظة" total={filtered.length} printable={false} />
        <div className="data-table-container"><table className="data-table w-full text-start text-sm">
            <thead><tr><th>البنك أو المحفظة</th><th>الاستخدام</th><th>الحالة</th><ActionsTh /></tr></thead>
            <tbody>{shown.map((bank) => <tr key={bank.id}>
                <td><div className="flex items-center gap-3"><BankMark bank={bank.name} /><span className="font-semibold text-gray-900">{bank.name}</span></div></td>
                <td className="text-gray-600">{[bank.is_receiver && 'استلام', bank.is_sender && 'إرسال'].filter(Boolean).join(' و')}</td>
                <td><StatusPill label={bank.is_active ? 'نشط' : 'غير نشط'} tone={bank.is_active ? 'green' : 'gray'} /></td>
                <td><RowActionsMenu onEdit={() => setEditing(bank)} /></td>
            </tr>)}{shown.length === 0 && <tr><td colSpan={4} className="text-gray-500">لا توجد بنوك مطابقة.</td></tr>}</tbody>
        </table></div>
        <Pagination meta={meta} onPageChange={setPage} />
        {(creating || editing) && <BankModal key={editing?.id ?? 'new'} bank={editing} storeUrl={storeUrl} onClose={() => { setCreating(false); setEditing(null); }} />}
    </section>;
}
