import { useState } from 'react';
import { useForm, useHttp } from '@inertiajs/react';
import FormModal from '@/Components/FormModal';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import { plainDigits } from '@/lib/formValidation';

function Field({ id, label, required, error, children }) {
    return (
        <div>
            <InputLabel htmlFor={id}>
                {label}
                {required && <span className="text-red-500"> *</span>}
            </InputLabel>
            <div className="mt-1">{children}</div>
            <InputError message={error} className="mt-1" />
        </div>
    );
}

/** An empty value of a field, as it reads in the history. */
const EMPTY = '—';

/**
 * Who changed this person's details, when, and what each was before; it
 * loads when first opened. The details are shared by all their
 * subscriptions, so a change made from another branch is listed here too.
 */
function ChangeHistory({ subscriptionId }) {
    const http = useHttp();
    const [failed, setFailed] = useState(false);

    function load(event) {
        if (event.currentTarget.open && !http.response && !http.processing) {
            setFailed(false);
            http.get(`/subscriptions/${subscriptionId}/personal-details/history`).catch(() => setFailed(true));
        }
    }

    const changes = http.response?.data;

    return (
        <details onToggle={load} className="rounded-control border border-gray-100 bg-surface px-3 py-2 text-sm">
            <summary className="cursor-pointer font-semibold text-gray-700">سجل تعديل البيانات</summary>
            <div className="mt-2" aria-busy={http.processing}>
                {failed ? (
                    <p role="alert" className="text-red-600">تعذّر تحميل السجل، أغلقه وافتحه مرة أخرى.</p>
                ) : changes === undefined ? (
                    <p className="text-gray-500">جارٍ التحميل...</p>
                ) : changes.length === 0 ? (
                    <p className="text-gray-500">لم تُعدَّل هذه البيانات منذ تسجيلها.</p>
                ) : (
                    <ol className="max-h-64 space-y-3 overflow-y-auto">
                        {changes.map((change) => (
                            <li key={change.id} className="border-b border-gray-100 pb-2 last:border-0">
                                <p className="text-xs text-gray-500">
                                    <span dir="ltr" className="font-display">{change.at}</span>
                                    {' · '}
                                    {change.userName ?? 'النظام'}
                                    {change.branchName ? ` (${change.branchName})` : ''}
                                </p>
                                <ul className="mt-1 space-y-0.5">
                                    {change.changes.map((field) => (
                                        <li key={field.field}>
                                            <span className="text-gray-500">{field.label}: </span>
                                            {/* Isolated, so names in another script do not turn the from → to order around. */}
                                            <bdi className="text-gray-500 line-through">{field.from || EMPTY}</bdi>
                                            {' ← '}
                                            <bdi className="font-bold text-gray-900">{field.to || EMPTY}</bdi>
                                        </li>
                                    ))}
                                </ul>
                            </li>
                        ))}
                    </ol>
                )}
            </div>
        </details>
    );
}

/**
 * The quick "personal details" form from a subscription's row menu: name,
 * identity number, mobile number and address only. They belong to the
 * person, so the change reaches every subscription of theirs.
 */
export default function PersonalDetailsModal({ subscription, onClose }) {
    const form = useForm({
        full_name: subscription.full_name ?? '',
        national_id: subscription.national_id ?? '',
        phone: subscription.phone ?? '',
        address: subscription.address ?? '',
    });
    const save = (options) => form.patch(`/subscriptions/${subscription.id}/personal-details`, options);
    const subscriptions = subscription.subscriptionCount ?? 1;

    return (
        <FormModal show onClose={onClose} form={{ ...form, isEdit: true, save }} title="تعديل البيانات الشخصية" icon="pencil" maxWidth="lg">
            <div className="space-y-4">
                {subscriptions > 1 && (
                    <p className="rounded-control bg-blue-500/10 px-3 py-2 text-sm text-gray-700">
                        هذه البيانات مشتركة بين اشتراكات هذا الشخص ({subscriptions})، وتتغير فيها كلها.
                    </p>
                )}

                <Field id="personal_full_name" label="الاسم الكامل" required error={form.errors.full_name}>
                    <TextInput
                        id="personal_full_name"
                        required
                        className="w-full"
                        value={form.data.full_name}
                        onChange={(event) => form.setData('full_name', event.target.value)}
                    />
                </Field>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field id="personal_national_id" label="رقم الهوية" error={form.errors.national_id}>
                        <TextInput
                            id="personal_national_id"
                            dir="ltr"
                            inputMode="numeric"
                            maxLength={9}
                            pattern="[0-9]{9}"
                            data-feedback
                            title="رقم الهوية يجب أن يتكون من 9 أرقام"
                            placeholder="9 أرقام"
                            className="w-full"
                            value={form.data.national_id}
                            onChange={(event) => form.setData('national_id', plainDigits(event.target.value))}
                        />
                    </Field>

                    <Field id="personal_phone" label="رقم الجوال" error={form.errors.phone}>
                        <TextInput
                            id="personal_phone"
                            type="tel"
                            dir="ltr"
                            inputMode="numeric"
                            maxLength={10}
                            pattern="05[69][0-9]{7}"
                            data-feedback
                            title="رقم الجوال يجب أن يتكون من 10 أرقام ويبدأ بـ 059 أو 056"
                            placeholder="059XXXXXXX"
                            className="w-full"
                            value={form.data.phone}
                            onChange={(event) => form.setData('phone', plainDigits(event.target.value))}
                        />
                    </Field>
                </div>

                <Field id="personal_address" label="العنوان" error={form.errors.address}>
                    <TextInput
                        id="personal_address"
                        className="w-full"
                        value={form.data.address}
                        onChange={(event) => form.setData('address', event.target.value)}
                    />
                </Field>

                <ChangeHistory subscriptionId={subscription.id} />
            </div>
        </FormModal>
    );
}
