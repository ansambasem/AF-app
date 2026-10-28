import SearchableSelect from '@/Components/SearchableSelect';

export default function DataTableSortControls({ id, label, sort, direction, options, onChange, sortLabel = 'ترتيب حسب', directionLabel = 'الاتجاه' }) {
    const type = options.find((option) => option.value === sort)?.type;
    const directionOptions = [
        { value: 'asc', label: type === 'name' ? 'من أ إلى ي' : 'تصاعديًا' },
        { value: 'desc', label: type === 'name' ? 'من ي إلى أ' : 'تنازليًا' },
    ];

    return (
        <div role="group" aria-label={label} className="flex w-full flex-wrap items-end gap-3 sm:w-auto">
            <div className="flex w-full min-w-0 flex-col gap-1.5 sm:w-44">
                <label htmlFor={`${id}-sort`} className="text-xs font-semibold text-gray-500">{sortLabel}</label>
                <SearchableSelect id={`${id}-sort`} value={sort} onChange={(value) => onChange(value, direction)} options={options} searchable={false} clearable={false} portal />
            </div>
            <div className="flex w-full min-w-0 flex-col gap-1.5 sm:w-44">
                <label htmlFor={`${id}-direction`} className="text-xs font-semibold text-gray-500">{directionLabel}</label>
                <SearchableSelect id={`${id}-direction`} value={direction} onChange={(value) => onChange(sort, value)} options={directionOptions} searchable={false} clearable={false} portal />
            </div>
        </div>
    );
}
