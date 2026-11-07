import { useEffect, useRef, useState } from 'react';
import { router, useHttp } from '@inertiajs/react';
import FormField from '@/Components/Form/FormField';
import SearchableSelect from '@/Components/SearchableSelect';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import Icon from '@/Components/Icon';

export default function UserTypeSelect({ value, options, onChange, onCreated, canCreate, error }) {
    const http = useHttp({ name: '' });
    const [adding, setAdding] = useState(false);
    const [failed, setFailed] = useState(false);
    const current = useRef(true);

    useEffect(() => {
        current.current = true;
        return () => { current.current = false; http.cancel(); };
    }, []);

    function startAdding(name) {
        http.setData('name', name);
        http.clearErrors();
        setFailed(false);
        setAdding(true);
    }

    async function save() {
        if (http.processing || !http.data.name.trim()) return;
        http.transform((data) => ({ name: data.name.trim() }));
        setFailed(false);
        try {
            const type = await http.post('/user-types');
            if (current.current && type) {
                onCreated({ value: type.id, label: type.name, hint: 'جديد' });
                setAdding(false);
                http.reset();
                router.reload({ only: ['userTypeOptions', 'userTypeItems', 'userForm', 'filterOptions'] });
            }
        } catch (error) {
            if (current.current && error.response?.status !== 422) setFailed(true);
        }
    }

    return <div className="space-y-2">
        <FormField id="user_type_id" label="نوع المستخدم / المسمى الوظيفي" hint="للتصنيف فقط. الصلاحيات مستقلة عنه: بعد الحفظ حدّدها من صفحة الصلاحيات أو انسخ صلاحيات موظف آخر." error={error}>
            <SearchableSelect
                value={value}
                onChange={onChange}
                options={options}
                placeholder="— اختر نوع المستخدم —"
                searchPlaceholder="ابحث عن نوع المستخدم..."
                emptyLabel="لا توجد أنواع مطابقة"
                onCreate={canCreate ? startAdding : null}
                createLabel="إضافة نوع مستخدم جديد"
                portal
            />
        </FormField>
        {adding && <div role="group" aria-label="إضافة مسمى وظيفي" className="space-y-3 rounded-control border border-brand-500/25 bg-brand-500/[0.04] p-3">
            <label htmlFor="new_user_type_name" className="flex items-center gap-2 text-xs font-bold text-brand-600"><Icon name="plus" className="h-4 w-4" />نوع مستخدم / مسمى وظيفي جديد</label>
            <TextInput id="new_user_type_name" value={http.data.name} autoFocus maxLength={255} disabled={http.processing} placeholder="مثلاً: فني صيانة" className="w-full text-sm"
                onChange={(event) => { http.setData('name', event.target.value); http.clearErrors('name'); setFailed(false); }}
                onKeyDown={(event) => { if (event.key === 'Enter') { event.preventDefault(); event.stopPropagation(); save(); } }} />
            <InputError message={http.errors.name || (failed ? 'تعذّر إضافة المسمى الوظيفي. حاول مرة أخرى.' : null)} />
            <div className="flex flex-wrap gap-2">
                <button type="button" onClick={save} disabled={http.processing || !http.data.name.trim()} className="rounded-control bg-brand-500 px-3 py-2 text-xs font-bold text-white hover:bg-brand-600 disabled:opacity-50">{http.processing ? 'جارٍ الإضافة...' : 'إضافة واختيار المسمى'}</button>
                <button type="button" onClick={() => setAdding(false)} disabled={http.processing} className="rounded-control px-3 py-2 text-xs font-semibold text-gray-600 hover:bg-gray-100">إلغاء</button>
            </div>
        </div>}
    </div>;
}
