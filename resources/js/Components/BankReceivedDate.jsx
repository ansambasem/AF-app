import { useId } from 'react';
import { usePage } from '@inertiajs/react';
import DateInput from '@/Components/DateInput';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';

export function BankReceivedDateField({ value, onChange, error }) {
    const id = useId();
    const today = usePage().props.paymentOptions?.today;

    return <div className="space-y-2">
        <InputLabel htmlFor={id} value="تاريخ وصول الحوالة إلى البنك (اختياري)" />
        <DateInput id={id} name="bank_received_on" value={value ?? ''} max={today} onChange={onChange} />
        <p className="text-xs leading-6 text-gray-500 dark:text-gray-400">اتركه فارغًا إن لم تتأكد منه. تُسجّل الحركة بتاريخ تسجيلها في النظام.</p>
        <InputError message={error} />
    </div>;
}

export default function BankReceivedDate({ value }) {
    if (!value) return null;

    return <span className="mt-1 block whitespace-normal text-xs leading-6 text-gray-600 dark:text-gray-400">وصول البنك: <bdi dir="ltr" className="font-display">{value.split('-').reverse().join('/')}</bdi></span>;
}
