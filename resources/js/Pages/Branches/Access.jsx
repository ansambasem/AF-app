import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Icon from '@/Components/Icon';
import PageHeader from '@/Components/PageHeader';

export default function Access({ branches, routingEnabled }) {
    return <AuthenticatedLayout header={<PageHeader title="اختيار فرع العمل" info="تظهر الفروع المسموحة لك فقط. كل رابط يفتح بيانات فرعه، وقد يتطلب تسجيل الدخول مجددًا لأن جلسة كل دومين مستقلة." />}>
        <Head title="فروع العمل" />
        {!routingEnabled && <p className="mb-5 rounded-control bg-amber-500/10 p-4 text-sm text-amber-800">الروابط مجهّزة، وتوجيه الدومينات يحتاج التفعيل عند النشر.</p>}
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">{branches.map((branch) => <div key={branch.id} className="space-y-4 rounded-card border border-gray-200 bg-surface p-5 shadow-card">
            <div className="flex items-center gap-3"><span className="rounded-xl bg-brand-500/10 p-3 text-brand-600"><Icon name="building" /></span><h3 className="text-lg font-bold text-gray-900">{branch.name}</h3></div>
            <p dir="ltr" className="break-all text-start text-sm text-gray-500">{branch.domain}</p>
            {routingEnabled && <a href={branch.url} className="inline-flex items-center gap-2 rounded-control bg-gray-900 px-4 py-2.5 text-sm font-semibold text-surface">دخول الفرع<Icon name="arrow-left" className="h-4 w-4" /></a>}
        </div>)}</div>
        {branches.length === 0 && <p className="rounded-card border border-dashed border-gray-200 p-8 text-center text-gray-500">لا توجد روابط فروع مفعّلة ضمن نطاقك حاليًا. يحدد السوبر أدمن فروع عملك من صفحة الصلاحيات.</p>}
    </AuthenticatedLayout>;
}
