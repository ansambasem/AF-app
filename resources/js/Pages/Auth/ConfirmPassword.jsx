import { Head, useForm } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PasswordInput from '@/Components/PasswordInput';
import PrimaryButton from '@/Components/PrimaryButton';

export default function ConfirmPassword() {
    const { data, setData, post, processing, errors, reset } = useForm({ password: '' });

    function submit(e) {
        e.preventDefault();
        post('/confirm-password', {
            onFinish: () => reset('password'),
        });
    }

    return (
        <GuestLayout>
            <Head title="تأكيد كلمة المرور" />

            <h2 className="text-3xl font-bold text-gray-900">تأكيد كلمة المرور</h2>
            <p className="mb-8 mt-2 text-sm text-gray-500">هذه منطقة آمنة من التطبيق. الرجاء تأكيد كلمة المرور قبل المتابعة.</p>

            <form onSubmit={submit}>
                <div>
                    <InputLabel htmlFor="password" value="كلمة المرور" />
                    <PasswordInput
                        id="password"
                        className="mt-1 block w-full"
                        value={data.password}
                        autoFocus
                        autoComplete="current-password"
                        onChange={(e) => setData('password', e.target.value)}
                    />
                    <InputError message={errors.password} className="mt-2" />
                </div>

                <PrimaryButton disabled={processing} className="mt-7 w-full py-3.5 text-base">
                    تأكيد
                </PrimaryButton>
            </form>
        </GuestLayout>
    );
}
