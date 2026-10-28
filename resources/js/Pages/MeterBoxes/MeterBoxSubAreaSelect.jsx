import { useEffect, useRef, useState } from 'react';
import { router, useHttp } from '@inertiajs/react';
import FormSelect from '@/Components/Form/FormSelect';
import InputError from '@/Components/InputError';
import Icon from '@/Components/Icon';
import TextInput from '@/Components/TextInput';

export default function MeterBoxSubAreaSelect({ id, value, onChange, onCreated, subAreas, areaId, canCreate, blockedMessage, error }) {
    const http = useHttp({ name: '' });
    const [adding, setAdding] = useState(false);
    const [failed, setFailed] = useState(false);
    const current = useRef(true);
    const options = [...subAreas]
        .sort((first, second) => first.name.localeCompare(second.name, 'ar'))
        .map((item) => ({ value: item.id, label: item.name, hint: item.isNew ? 'جديدة' : undefined }));

    useEffect(() => {
        current.current = true;
        return () => { current.current = false; http.cancel(); };
    }, []);

    function startAdding(name) {
        http.clearErrors();
        http.setData('name', name);
        setFailed(false);
        setAdding(true);
    }

    async function save() {
        if (http.processing || !http.data.name.trim()) {
            return;
        }

        setFailed(false);
        http.transform((data) => ({ name: data.name.trim(), area_id: areaId }));
        try {
            const subArea = await http.post('/sub-areas');
            if (current.current && subArea) {
                onCreated(subArea);
                setAdding(false);
                http.reset();
                router.reload({ only: ['subAreas', 'filterOptions'] });
            }
        } catch (error) {
            if (current.current && error.response?.status !== 422) {
                setFailed(true);
            }
        }
    }

    return (
        <div className="space-y-2">
            <FormSelect
                id={id}
                label="منطقة الطبلون (منطقة 2)"
                searchable
                value={value}
                onChange={onChange}
                options={options}
                placeholder="— بلا منطقة 2 —"
                blockedMessage={blockedMessage}
                emptyMessage="لا توجد منطقة 2 في هذه المنطقة بعد"
                error={error}
                onCreate={canCreate && areaId && !blockedMessage ? startAdding : null}
                createLabel="إضافة منطقة جديدة"
            />
            {adding && <div className="space-y-3 rounded-control border border-brand-500/25 bg-brand-500/[0.04] p-3" role="group" aria-label="إضافة منطقة الطبلون">
                <label htmlFor={`${id}-new`} className="flex items-center gap-2 text-xs font-bold text-brand-600"><Icon name="plus" className="h-4 w-4" />منطقة جديدة ضمن منطقة الفرع المختار</label>
                <TextInput
                    id={`${id}-new`}
                    value={http.data.name}
                    onChange={(event) => { http.setData('name', event.target.value); http.clearErrors('name'); }}
                    onKeyDown={(event) => { if (event.key === 'Enter') { event.preventDefault(); event.stopPropagation(); save(); } }}
                    disabled={http.processing}
                    maxLength={255}
                    autoFocus
                    placeholder="اسم المنطقة الجديدة"
                    className="block w-full text-sm"
                />
                <InputError message={http.errors.name || http.errors.area_id || (failed ? 'تعذّر إضافة المنطقة. حاول مرة أخرى.' : null)} />
                <div className="flex flex-wrap gap-2">
                    <button type="button" onClick={save} disabled={http.processing || !http.data.name.trim()} className="rounded-control bg-brand-500 px-3 py-2 text-xs font-bold text-white transition hover:bg-brand-600 disabled:opacity-50">{http.processing ? 'جارٍ الإضافة...' : 'إضافة واختيار المنطقة'}</button>
                    <button type="button" onClick={() => setAdding(false)} disabled={http.processing} className="rounded-control px-3 py-2 text-xs font-semibold text-gray-600 hover:bg-gray-100">إلغاء</button>
                </div>
            </div>}
            {!adding && subAreas.some((item) => item.isNew && String(item.id) === String(value)) && <p role="status" className="flex items-center gap-1.5 text-xs font-semibold text-emerald-600"><Icon name="check" className="h-3.5 w-3.5" />أُضيفت المنطقة واختيرت للطبلون.</p>}
        </div>
    );
}
