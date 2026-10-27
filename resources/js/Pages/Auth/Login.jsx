import { Head, useForm, usePage } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import PasswordInput from '@/Components/PasswordInput';
import PrimaryButton from '@/Components/PrimaryButton';

export default function Login() {
    const { branchContext } = usePage().props;
    const { data, setData, post, processing, errors, reset } = useForm({
        username: '',
        password: '',
        remember: false,
    });

    function submit(e) {
        e.preventDefault();
        post('/login', {
            onFinish: () => reset('password'),
        });
    }

    return (
        <GuestLayout>
            <Head title="تسجيل الدخول" />

            <h2 className="text-3xl font-bold text-gray-900">مرحبًا بعودتك</h2>
            <p className="mt-2 text-sm text-gray-500">سجّل دخولك للمتابعة إلى لوحة التحكم.</p>
            {branchContext?.current && <p className="mt-4 rounded-control border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-900">تسجيل الدخول إلى <b>{branchContext.current.name}</b></p>}

            <form onSubmit={submit} className="mt-10 space-y-5">
                <div>
                    <InputLabel htmlFor="username" value="اسم المستخدم" />
                    <TextInput
                        id="username"
                        className="mt-2 block w-full py-3 text-right"
                        dir="auto"
                        value={data.username}
                        autoFocus
                        autoComplete="username"
                        onChange={(e) => setData('username', e.target.value)}
                    />
                    <InputError message={errors.username} className="mt-2" />
                </div>

                <div>
                    <InputLabel htmlFor="password" value="كلمة المرور" />
                    <PasswordInput
                        id="password"
                        className="mt-2 block w-full py-3 text-right"
                        dir="auto"
                        value={data.password}
                        autoComplete="current-password"
                        onChange={(e) => setData('password', e.target.value)}
                    />
                    <InputError message={errors.password} className="mt-2" />
                </div>

                <label className="flex items-center gap-2.5">
                    <input
                        type="checkbox"
                        className="h-[18px] w-[18px]"
                        checked={data.remember}
                        onChange={(e) => setData('remember', e.target.checked)}
                    />
                    <span className="text-sm text-gray-600">تذكرني على هذا الجهاز</span>
                </label>

                <PrimaryButton disabled={processing} className="!mt-7 w-full py-3.5 text-base">
                    تسجيل الدخول
                </PrimaryButton>
            </form>
        </GuestLayout>
    );
}
