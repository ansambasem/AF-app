import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import Icon from '@/Components/Icon';
import InputError from '@/Components/InputError';

function NameInput({ value, onChange, onSave, onCancel, processing, error, label, maxLength }) {
    return <div className="max-w-full">
        <span className="inline-flex h-[38px] max-w-full items-center rounded-full border border-gray-900 bg-surface pe-1 ps-1.5">
            <input autoFocus value={value} placeholder={label} aria-label={label} aria-invalid={Boolean(error)} maxLength={maxLength} disabled={processing}
                onChange={(event) => onChange(event.target.value)}
                onKeyDown={(event) => {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        onSave();
                    } else if (event.key === 'Escape') {
                        event.stopPropagation();
                        if (!processing) onCancel();
                    }
                }}
                className="min-w-0 w-36 border-0 bg-transparent px-2 py-0 text-sm font-semibold text-gray-900 focus:ring-0" />
            <button type="button" onClick={onSave} disabled={processing || !value.trim()} aria-label="حفظ" className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-emerald-700 hover:bg-emerald-500/10 disabled:opacity-40"><Icon name="check" className="h-4 w-4" strokeWidth={2} /></button>
            <button type="button" onClick={onCancel} disabled={processing} aria-label="إلغاء" className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-gray-500 hover:bg-gray-100 disabled:opacity-40"><Icon name="close" className="h-4 w-4" strokeWidth={2} /></button>
        </span>
        <InputError message={error} className="mt-1" />
    </div>;
}

/** Shared inline classification management for customer segments and user job titles. */
export default function ClassificationChips({ title, description, items, baseUrl, canCreate, onDelete, countLabel, addLabel, inputLabel, emptyLabel, maxLength = 100, className = '', visitOptions = {}, showHeader = true }) {
    const [editing, setEditing] = useState(null);
    const form = useForm({ name: '' });
    const busiest = Math.max(1, ...items.map((item) => item.count));

    function edit(item = null) {
        form.clearErrors();
        form.setData('name', item?.name ?? '');
        setEditing(item?.id ?? 'new');
    }

    function save() {
        if (form.processing || !form.data.name.trim()) return;
        form.transform((data) => ({ name: data.name.trim() }));
        form.submit(editing === 'new' ? 'post' : 'put', editing === 'new' ? baseUrl : `${baseUrl}/${editing}`, {
            preserveScroll: true,
            ...visitOptions,
            onSuccess: (page) => {
                setEditing(null);
                form.resetAndClearErrors();
                visitOptions.onSuccess?.(page);
            },
        });
    }

    function editor(key) {
        return <NameInput key={key} value={form.data.name} onChange={(value) => { form.setData('name', value); form.clearErrors('name'); }} onSave={save} onCancel={() => setEditing(null)} processing={form.processing} error={form.errors.name} label={inputLabel} maxLength={maxLength} />;
    }

    return <section aria-label={title} className={`overflow-hidden rounded-card border border-gray-100 bg-surface shadow-card ${className}`}>
        {showHeader && <div className="border-b border-gray-100 px-5 py-4 sm:px-6">
            <h3 className="font-luxe text-lg font-bold text-gray-900">{title}</h3>
            <p className="text-[13.5px] text-gray-500">{description}</p>
        </div>}
        <div className="flex flex-wrap items-center gap-2 px-5 py-4 sm:px-6">
            {items.map((item) => editing === item.id ? editor(item.id) : <span key={item.id} className="inline-flex min-h-[38px] w-full max-w-full items-center gap-2 rounded-full border border-gray-100 bg-gray-50 py-1 pe-1.5 ps-3.5 text-sm font-semibold text-gray-900 sm:h-[38px] sm:w-auto sm:py-0">
                <span className="min-w-0 flex-1 break-words sm:flex-initial sm:truncate" title={item.name}>{item.name}</span>
                <span className="shrink-0 font-display text-xs font-semibold text-gray-500">{item.count} {countLabel}</span>
                <span className="hidden h-[5px] w-11 shrink-0 overflow-hidden rounded-full bg-gray-200 sm:block" aria-hidden="true"><i className="block h-full rounded-full bg-gray-700" style={{ width: `${(item.count / busiest) * 100}%` }} /></span>
                {item.canUpdate && <button type="button" disabled={form.processing} onClick={() => edit(item)} aria-label={`تعديل ${item.name}`} className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-blue-600 hover:bg-blue-500/10 disabled:opacity-40"><Icon name="pencil" className="h-[15px] w-[15px]" /></button>}
                {item.canDelete && item.count === 0 && <button type="button" disabled={form.processing} onClick={() => onDelete(item)} aria-label={`حذف ${item.name}`} className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-brand-600 hover:bg-brand-500/10 disabled:opacity-40"><Icon name="trash" className="h-[15px] w-[15px]" /></button>}
            </span>)}
            {items.length === 0 && editing !== 'new' && <span className="text-[13.5px] text-gray-500">{emptyLabel}</span>}
            {canCreate && (editing === 'new' ? editor('new') : <button type="button" disabled={form.processing} onClick={() => edit()} className="inline-flex h-[38px] items-center gap-1.5 rounded-full border-[1.5px] border-dashed border-gray-300 px-3.5 text-sm font-semibold text-gray-600 transition hover:border-gray-900 hover:text-gray-900 disabled:opacity-40"><Icon name="plus" className="h-[15px] w-[15px]" strokeWidth={2} />{addLabel}</button>)}
        </div>
    </section>;
}
