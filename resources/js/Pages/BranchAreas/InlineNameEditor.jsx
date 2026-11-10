import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';

/**
 * A name typed in place of a list row — adding a new record or renaming
 * one. `request` is `{ method, url, data }` without the name; the name is
 * merged in on save. Enter saves, Escape cancels; the server's validation
 * message (e.g. the name is taken) shows inline. `onMore`, when given,
 * opens the full form for the fields this row can't edit.
 */
export default function InlineNameEditor({ initialName = '', placeholder, request, onDone, onMore }) {
    const [name, setName] = useState(initialName);
    const [error, setError] = useState(null);
    const [processing, setProcessing] = useState(false);
    const inputRef = useRef(null);

    useEffect(() => {
        inputRef.current?.focus();
        inputRef.current?.select();
    }, []);

    function save() {
        const trimmed = name.trim();

        if (!trimmed) {
            setError('اكتب الاسم');
            inputRef.current?.focus();
            return;
        }

        if (trimmed === initialName) {
            onDone();
            return;
        }

        router.visit(request.url, {
            method: request.method,
            data: { ...request.data, name: trimmed },
            preserveState: true,
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => onDone(),
            onError: (errors) => {
                setError(errors.name ?? Object.values(errors)[0] ?? 'تعذّر الحفظ');
                inputRef.current?.focus();
            },
        });
    }

    function onKeyDown(event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            save();
        }
        if (event.key === 'Escape') {
            onDone();
        }
    }

    return (
        <div className="my-0.5 rounded-control border-[1.5px] border-gray-900 bg-surface p-1.5">
            <div className="flex items-center gap-1.5">
                <input
                    ref={inputRef}
                    value={name}
                    onChange={(event) => {
                        setName(event.target.value);
                        setError(null);
                    }}
                    onKeyDown={onKeyDown}
                    placeholder={placeholder}
                    aria-label={placeholder}
                    aria-invalid={Boolean(error)}
                    className="min-w-0 flex-1 border-0 bg-transparent px-1.5 py-1 text-sm font-semibold text-gray-900 focus:ring-0"
                />
                <button
                    type="button"
                    onClick={save}
                    disabled={processing}
                    className="h-8 rounded-lg bg-gray-900 px-2.5 text-xs font-bold text-surface transition hover:bg-gray-800 disabled:opacity-60"
                >
                    حفظ
                </button>
                <button
                    type="button"
                    onClick={onDone}
                    className="h-8 rounded-lg bg-gray-100 px-2.5 text-xs font-bold text-gray-700 transition hover:bg-gray-200"
                >
                    إلغاء
                </button>
            </div>
            {(error || onMore) && (
                <div className="flex items-center gap-2 px-1.5 pt-1">
                    {error && <p className="text-xs text-brand-600">{error}</p>}
                    {onMore && (
                        <button type="button" onClick={onMore} className="ms-auto text-xs font-semibold text-gray-500 hover:text-brand-600">
                            تعديل كامل…
                        </button>
                    )}
                </div>
            )}
        </div>
    );
}
