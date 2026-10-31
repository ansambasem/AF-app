import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import BranchBanner from '@/Components/BranchBanner';
import Icon from '@/Components/Icon';
import PageHeader from '@/Components/PageHeader';
import { secondaryButtonClass } from '@/Components/SecondaryButton';
import { formatDate } from '@/lib/format';

/** Each branch keeps its own reading week when the company's schedules differ. */
export default function Branches({ branches, branchScope }) {
    return <AuthenticatedLayout scope={branchScope.name} header={<PageHeader title="القراءات الأسبوعية" />}>
        <Head title="القراءات الأسبوعية — جميع الفروع" />
        <BranchBanner scope={branchScope} eyebrow="تعرض الآن قراءات" />
        <p className="mb-5 text-sm leading-7 text-gray-500">تختلف مواعيد القراءة بين الفروع. اختر فرعًا لعرض كشفه حسب موعد القراءة الخاص به.</p>
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            {branches.map((branch) => <article key={branch.id} className="flex flex-col gap-4 rounded-card border border-gray-100 bg-surface p-5 shadow-card">
                <h3 className="flex items-center gap-2 text-lg font-bold text-gray-900"><Icon name="building" className="h-5 w-5 text-gray-500" />{branch.name}</h3>
                <p className="text-sm text-gray-600">أسبوع القراءة: <bdi>{formatDate(branch.weekStart)}</bdi> — <bdi>{formatDate(branch.weekEnd)}</bdi></p>
                <Link href={branch.url} className={`${secondaryButtonClass} self-start`}><Icon name="eye" className="h-4 w-4" />عرض القراءات</Link>
            </article>)}
        </div>
    </AuthenticatedLayout>;
}
