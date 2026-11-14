import { Head } from '@inertiajs/react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import PageHeader from '@/Components/PageHeader';
import Icon from '@/Components/Icon';
import { primaryButtonClass } from '@/Components/PrimaryButton';

export default function MobileApp({ release }) {
    return <SettingsLayout header={<PageHeader title="تحميل تطبيق الهاتف" meta="PowerCollect للمحصّلين وقرّاء العدادات" />}>
        <Head title="تحميل تطبيق الهاتف" />
        <div className="mx-auto max-w-3xl space-y-6">
            <section className="rounded-card border border-gray-100 bg-white p-6 shadow-card sm:p-8">
                <div className="flex items-start gap-4">
                    <span className="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-brand-50 text-brand-700"><Icon name="phone" className="h-7 w-7" /></span>
                    <div className="min-w-0 space-y-2">
                        <h3 className="text-xl font-bold text-gray-900">تطبيق PowerCollect</h3>
                        <p className="text-sm leading-7 text-gray-600">اطّلع على الطبلونات المخصصة لك، وسجّل القراءات والتحصيل حسب صلاحيات حسابك. يتصل التطبيق بنفس بيانات الموقع عند توفر الإنترنت.</p>
                        <p className="text-sm text-gray-500">Android 7.0 أو أحدث</p>
                    </div>
                </div>
                {release ? <div className="mt-6 space-y-4 border-t border-gray-100 pt-6">
                    <div className="flex flex-wrap gap-x-6 gap-y-2 text-sm text-gray-600"><span>الإصدار <b dir="ltr">{release.version}</b></span><span>حجم الملف <b dir="ltr">{release.sizeMb} MB</b></span></div>
                    <a href={release.downloadUrl} className={`${primaryButtonClass} w-full sm:w-auto`}><Icon name="arrow-down-tray" className="h-5 w-5" />تحميل تطبيق Android</a>
                    <p className="text-xs leading-6 text-gray-500">ملف APK موقّع. يُنزَّل مباشرة من GitHub، ولا يحتاج إلى حساب Google Play. يتصل التطبيق ببيانات الموقع كالمعتاد.</p>
                </div> : <p role="status" className="mt-6 rounded-control bg-amber-50 p-4 text-sm text-amber-900">نسخة التطبيق غير متاحة للتحميل حاليًا. يرجى المحاولة لاحقًا.</p>}
            </section>
            <section className="rounded-card border border-gray-100 bg-white p-6 shadow-card sm:p-8">
                <h3 className="mb-4 text-lg font-bold text-gray-900">التثبيت والبدء</h3>
                <ol className="list-inside list-decimal space-y-4 text-sm leading-7 text-gray-600">
                    <li>افتح هذه الصفحة من هاتف Android، ثم اضغط تحميل التطبيق.</li>
                    <li>افتح الملف المحمّل. إذا طلب الهاتف إذن التثبيت لهذا المتصفح، اسمح به لإتمام التثبيت، ويمكنك إلغاؤه بعد ذلك.</li>
                    <li>افتح PowerCollect وسجّل الدخول باسم المستخدم وكلمة المرور الخاصين بالموقع.</li>
                </ol>
                <p className="mt-4 text-sm leading-7 text-gray-600">إذا بلغ الحساب حد الأجهزة المسموح، يُسجّل خروج الجهاز الأقدم تلقائيًا عند الدخول من جهاز جديد.</p>
                <p className="mt-5 rounded-control bg-gray-50 p-4 text-sm leading-7 text-gray-600">إدخال القراءات متاح في يوم القراءة المحدد فقط. وتبقى القراءات المحفوظة دون اتصال بانتظار مراجعتك وتأكيد رفعها.</p>
            </section>
        </div>
    </SettingsLayout>;
}
