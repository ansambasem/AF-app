import { useEffect, useId, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import ConfirmDialog from '@/Components/ConfirmDialog';
import Icon from '@/Components/Icon';

const FOCUS_RING = 'focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-900';

/**
 * A subscription's mobile number in the list, changed in place: the pencil
 * turns it into a field — Enter (or ✓) asks to confirm the change, old
 * number beside the new, before saving; Esc (or ✕) cancels — without
 * opening their form. Without the right to edit them it is plain text.
 */
export default function PhoneQuickEdit({ subscription }) {
    const id = useId();
    const inputRef = useRef(null);
    const [editing, setEditing] = useState(false);
    const [phone, setPhone] = useState(subscription.contact_phone ?? '');
    const [error, setError] = useState(null);
    const [saving, setSaving] = useState(false);
    const [confirming, setConfirming] = useState(false);

    useEffect(() => {
        if (editing) {
            inputRef.current?.select();
        }
    }, [editing]);

    // No number is flagged in amber: messages cannot reach this subscriber.
    if (!subscription.canUpdate) {
        return subscription.contact_phone ? <span dir="ltr">{subscription.contact_phone}</span> : <span className="font-semibold text-amber-700 dark:text-amber-400">بلا رقم</span>;
    }

    function start() {
        setPhone(subscription.contact_phone ?? '');
        setError(null);
        setEditing(true);
    }

    /** Asks before saving a changed number; an unchanged one just closes the field. */
    function requestSave() {
        if (phone.trim() === (subscription.contact_phone ?? '')) {
            setEditing(false);
            return;
        }

        setConfirming(true);
    }

    function save() {
        setConfirming(false);
        setSaving(true);
        router.patch(
            `/subscriptions/${subscription.id}/phone`,
            { phone },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => setEditing(false),
                onError: (errors) => setError(errors.phone ?? 'تعذّر حفظ الرقم.'),
                onFinish: () => setSaving(false),
            },
        );
    }

    if (!editing) {
        return (
            <button
                type="button"
                onClick={start}
                aria-label={`تعديل رقم جوال ${subscription.display_name}: ${subscription.contact_phone || 'لا يوجد'}`}
                title="تعديل رقم الجوال"
                className={`group -mx-1 inline-flex items-center gap-1.5 rounded-md px-1 text-gray-500 hover:bg-gray-100 hover:text-gray-900 ${FOCUS_RING}`}
            >
                {subscription.contact_phone ? (
                    <span dir="ltr">{subscription.contact_phone}</span>
                ) : (
                    <span className="font-semibold text-amber-700 dark:text-amber-400" title="لا تصله الرسائل بلا رقم">
                        إضافة رقم
                    </span>
                )}
                <Icon name="pencil" className="h-3.5 w-3.5 text-gray-400 group-hover:text-gray-700" />
            </button>
        );
    }

    return (
        <span className="block whitespace-normal" onClick={(event) => event.stopPropagation()}>
            <span className="flex items-center gap-1">
                <label htmlFor={id} className="sr-only">
                    رقم جوال {subscription.display_name}
                </label>
                <input
                    ref={inputRef}
                    id={id}
                    type="tel"
                    dir="ltr"
                    inputMode="tel"
                    value={phone}
                    onChange={(e) => setPhone(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            requestSave();
                        } else if (e.key === 'Escape') {
                            e.stopPropagation();
                            setEditing(false);
                        }
                    }}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={error ? `${id}-error` : undefined}
                    maxLength={14}
                    placeholder="05XXXXXXXX"
                    className="block w-32 !rounded-lg !px-2 !py-1 text-sm"
                />
                <button
                    type="button"
                    onClick={requestSave}
                    disabled={saving}
                    aria-label="حفظ الرقم"
                    title="حفظ (Enter)"
                    className={`flex h-7 w-7 items-center justify-center rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 disabled:opacity-50 ${FOCUS_RING}`}
                >
                    <Icon name="check" className="h-4 w-4" strokeWidth={2.5} />
                </button>
                <button
                    type="button"
                    onClick={() => setEditing(false)}
                    aria-label="إلغاء"
                    title="إلغاء (Esc)"
                    className={`flex h-7 w-7 items-center justify-center rounded-lg border border-gray-200 text-gray-600 hover:text-gray-900 ${FOCUS_RING}`}
                >
                    <Icon name="close" className="h-4 w-4" />
                </button>
            </span>
            {error && (
                <span id={`${id}-error`} role="alert" className="mt-1 block text-xs text-brand-600">
                    {error}
                </span>
            )}
            <ConfirmDialog
                show={confirming}
                title="تأكيد تغيير رقم الجوال"
                icon="phone"
                confirmLabel="تأكيد التغيير"
                onConfirm={save}
                onCancel={() => setConfirming(false)}
                message={
                    <>
                        يتغيّر رقم جوال <b>{subscription.display_name}</b> من{' '}
                        <bdi dir="ltr" className="font-display font-semibold">
                            {subscription.contact_phone || 'بلا رقم'}
                        </bdi>{' '}
                        إلى{' '}
                        <bdi dir="ltr" className="font-display font-semibold text-gray-900">
                            {phone.trim() || 'بلا رقم'}
                        </bdi>
                        . تصله الرسائل على الرقم الجديد.
                    </>
                }
            />
        </span>
    );
}
