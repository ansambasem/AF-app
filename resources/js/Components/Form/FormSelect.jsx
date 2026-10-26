import FormField from '@/Components/Form/FormField';
import SearchableSelect from '@/Components/SearchableSelect';

/**
 * A labelled drop-down of `options` (`{ value, label }`). When it cannot
 * offer anything yet it is greyed out and says why in its place:
 * `blockedMessage` while what it depends on is not chosen ("pick a
 * governorate first"), `emptyMessage` when that has no options at all.
 */
export default function FormSelect({ id, label, value, onChange, options, placeholder = '— اختر —', blockedMessage = null, emptyMessage = null, required, error, hint, searchable = false }) {
    const message = blockedMessage ?? (options.length === 0 ? emptyMessage : null);

    return (
        <FormField id={id} label={label} required={required} error={error} hint={hint}>
            {searchable ? <SearchableSelect
                name={id}
                value={value}
                onChange={onChange}
                options={options}
                placeholder={message ?? placeholder}
                disabled={message !== null}
                required={required}
                portal
            /> : <select
                className="block w-full disabled:cursor-not-allowed disabled:bg-gray-50 disabled:text-gray-500"
                value={value}
                disabled={message !== null}
                onChange={(event) => onChange(event.target.value)}
            >
                <option value="">{message ?? placeholder}</option>
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>}
        </FormField>
    );
}

/** `{ id, name }` records as drop-down options. */
export function namedOptions(records) {
    return records.map((record) => ({ value: record.id, label: record.name }));
}
