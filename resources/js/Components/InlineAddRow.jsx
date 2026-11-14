import Icon from '@/Components/Icon';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';

/** The text input style inside an inline add row. */
export const inlineInputClass =
    'h-11 w-full rounded-xl border-[1.5px] border-gray-200 bg-surface px-3 text-[15px] text-gray-900 transition placeholder:text-gray-400 focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-500/15';

/** One labelled field in an inline add row, with its error under it. */
export function InlineField({ id, label, error, className = '', children }) {
    return (
        <div className={`flex flex-col gap-1 ${className}`}>
            <label htmlFor={id} className="text-[12.5px] font-semibold text-gray-500">
                {label}
            </label>
            {children}
            <span className="min-h-4 text-xs font-semibold text-brand-600" role={error ? 'alert' : undefined}>
                {error}
            </span>
        </div>
    );
}

/**
 * Adding a record in place, at the foot of its list: a dashed "add" row
 * that opens into one line of fields with Cancel and Add. Enter saves and
 * Esc cancels.
 */
export default function InlineAddRow({
    open,
    onOpen,
    onCancel,
    onSubmit,
    title,
    icon = 'plus',
    openLabel,
    hint,
    submitLabel = 'إضافة',
    processing = false,
    children,
}) {
    if (!open) {
        return (
            <div className="mx-3 mb-3.5 mt-1.5 flex flex-wrap items-center gap-3 rounded-[18px] border-2 border-dashed border-gray-200 bg-gray-50 px-3.5 py-3">
                <button
                    type="button"
                    onClick={onOpen}
                    className="inline-flex h-11 items-center gap-2 rounded-[13px] border border-dashed border-gray-300 bg-surface px-4 text-[15px] font-bold text-gray-700 transition hover:bg-gray-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-900"
                >
                    <Icon name="plus" className="h-[18px] w-[18px]" strokeWidth={2.2} />
                    {openLabel}
                </button>
                {hint && <span className="text-[12.5px] text-gray-500">{hint}</span>}
            </div>
        );
    }

    return (
        <form
            noValidate
            onSubmit={(event) => {
                event.preventDefault();
                onSubmit();
            }}
            onKeyDown={(event) => {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    onCancel();
                }
            }}
            className="mx-3 mb-3.5 mt-1.5 flex flex-wrap items-end gap-3 rounded-[18px] border-2 border-gray-900 bg-surface px-3.5 py-3"
        >
            <span className="flex items-center gap-2 self-center font-bold text-gray-700">
                <Icon name={icon} className="h-[18px] w-[18px]" strokeWidth={1.8} />
                {title}
            </span>
            {children}
            <div className="mb-5 flex gap-2">
                <SecondaryButton onClick={onCancel} disabled={processing} className="h-11 rounded-[13px] text-[15px] font-bold">
                    إلغاء
                </SecondaryButton>
                <PrimaryButton type="submit" disabled={processing} className="h-11 rounded-[13px] text-[15px] font-bold">
                    <Icon name="check" className="h-[18px] w-[18px]" strokeWidth={2.2} />
                    {processing ? 'جارٍ الحفظ...' : submitLabel}
                </PrimaryButton>
            </div>
        </form>
    );
}
