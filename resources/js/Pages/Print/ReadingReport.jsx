import { Head, useHttp } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';

/** Print data is prepared by the authorized source page; no reading table or editing tools mount here. */
export default function ReadingReport({ printData }) {
    const http = useHttp();
    const context = printData?.context ?? {};
    const beforePrint = context.sheetId ? () => http.post(`/paper-sheets/${context.sheetId}/printed`) : undefined;

    return <AuthenticatedLayout beforePrint={beforePrint} header={<PageHeader title={context.title ?? 'طباعة القراءات'} meta={context.branchName} />}>
        <Head title={context.title ?? 'طباعة القراءات'} />
        <p className="text-sm text-gray-500">اختر الأعمدة والقالب من إعدادات الطباعة.</p>
    </AuthenticatedLayout>;
}
