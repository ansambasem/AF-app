import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import AccountStatement, { STATEMENT_COLUMNS } from './AccountStatement';
import PageHeader from '@/Components/PageHeader';

export default function StatementPrint(statement) {
    return <AuthenticatedLayout header={<PageHeader title="طباعة كشف حساب المشترك" />}>
        <Head title="كشف حساب المشترك" />
        {statement.subscription ? <AccountStatement key={JSON.stringify(statement.printOptions)} {...statement} /> : <section className="rounded-panel border border-gray-100 bg-surface p-6">
            <p>لا يوجد مشترك متاح. يمكنك تجهيز قالب الكشف الآن لاستخدامه لاحقًا.</p>
            <table><thead><tr>{STATEMENT_COLUMNS.map((column) => <th key={column}>{column}</th>)}</tr></thead><tbody /></table>
        </section>}
    </AuthenticatedLayout>;
}
