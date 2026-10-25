import { useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import SecondaryButton, { secondaryButtonClass } from '@/Components/SecondaryButton';
import Icon from '@/Components/Icon';
import InputError from '@/Components/InputError';
import PageHeader from '@/Components/PageHeader';
import SegmentedTabs from '@/Components/SegmentedTabs';
import TextInput from '@/Components/TextInput';
import { formatNumber } from '@/lib/format';
import { readingSearchMatches } from '@/lib/readingBoard';

const fullDate = (date) => date.split('-').reverse().join('/');

function BranchOverview({ branches, canCreate }) {
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState('all');
    const [preparingId, setPreparingId] = useState(null);
    const { errors } = usePage().props;
    const prepared = branches.filter((item) => item.cycleId).length;
    const visible = branches.filter((item) => readingSearchMatches(item.name, search)
        && (status === 'all' || (status === 'prepared' ? Boolean(item.cycleId) : !item.cycleId)));

    function prepare(item) {
        router.post('/reading-cycles/prepare', { branch_id: item.id }, {
            preserveScroll: true,
            onStart: () => setPreparingId(item.id),
            onFinish: () => setPreparingId(null),
        });
    }

    return (
        <div className="space-y-5">
            <section aria-label="ملخص تجهيز القراءات" className="flex flex-col gap-6 rounded-card border border-gray-100 bg-surface p-5 shadow-card lg:flex-row lg:items-center lg:justify-between lg:px-7">
                <div className="flex items-center gap-4">
                    <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-row bg-brand-500/10 text-brand-600">
                        <Icon name="office" className="h-6 w-6" />
                    </span>
                    <div>
                        <h3 className="text-lg font-bold text-gray-900">متابعة قراءات الفروع</h3>
                        <p className="mt-1 text-sm text-gray-500">اختر الفرع لمراجعة توزيعه أو تجهيز قراءته القادمة.</p>
                    </div>
                </div>
                <dl className="grid shrink-0 grid-cols-3 gap-4 border-t border-gray-100 pt-4 lg:min-w-[390px] lg:border-s lg:border-t-0 lg:ps-7 lg:pt-0">
                    {[
                        { label: 'الفروع', value: branches.length, tone: 'text-gray-900' },
                        { label: 'قراءات مجهّزة', value: prepared, tone: 'text-emerald-700 dark:text-emerald-400' },
                        { label: 'بانتظار التجهيز', value: branches.length - prepared, tone: 'text-amber-700 dark:text-amber-400' },
                    ].map((item) => (
                        <div key={item.label} className="flex flex-col gap-1">
                            <dt className="order-2 text-xs text-gray-500">{item.label}</dt>
                            <dd className={`order-1 font-display text-2xl font-bold tabular-nums ${item.tone}`}>{formatNumber(item.value)}</dd>
                        </div>
                    ))}
                </dl>
            </section>

            <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
                <div className="relative w-full sm:max-w-sm">
                    <Icon name="search" className="pointer-events-none absolute inset-y-0 start-3.5 my-auto h-5 w-5 text-gray-400" />
                    <TextInput type="search" aria-label="بحث عن فرع" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="بحث عن فرع..." className="w-full py-3 pe-4 ps-11 text-sm" />
                </div>
                <div className="max-w-full overflow-x-auto pb-1 [&_button]:whitespace-nowrap">
                    <SegmentedTabs label="حالة تجهيز القراءة" value={status} onChange={setStatus} options={[
                        { value: 'all', label: `الكل (${formatNumber(branches.length)})` },
                        { value: 'prepared', label: `مجهّزة (${formatNumber(prepared)})` },
                        { value: 'waiting', label: `بانتظار التجهيز (${formatNumber(branches.length - prepared)})` },
                    ]} />
                </div>
            </div>
            <InputError message={errors?.branch_id} />

            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3" aria-label="الفروع وموعد القراءة القادمة">
                {visible.map((item) => (
                    <section key={item.id} aria-labelledby={`distribution-branch-${item.id}`} aria-busy={preparingId === item.id} className="flex min-w-0 flex-col overflow-hidden rounded-card border border-gray-100 bg-surface shadow-card">
                        <div className="flex flex-1 flex-col gap-5 p-5 sm:p-6">
                            <div className="flex items-start justify-between gap-3">
                                <h3 id={`distribution-branch-${item.id}`} className="min-w-0 text-lg font-bold leading-8 text-gray-900">{item.name}</h3>
                                <span className={`mt-1 inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold ${item.cycleId ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400' : 'bg-amber-500/10 text-amber-700 dark:text-amber-400'}`}>
                                    <span aria-hidden="true" className={`h-1.5 w-1.5 rounded-full ${item.cycleId ? 'bg-emerald-600 dark:bg-emerald-400' : 'bg-amber-600 dark:bg-amber-400'}`} />
                                    {item.cycleId ? 'مجهّزة' : 'بانتظار التجهيز'}
                                </span>
                            </div>
                            <div className="mt-auto flex items-center gap-4 rounded-row bg-gray-50/70 p-4">
                                <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-control border border-gray-200 bg-surface text-brand-600">
                                    <Icon name="calendar" className="h-6 w-6" />
                                </span>
                                <div className="min-w-0 space-y-1">
                                    <p className="text-xs text-gray-500">القراءة القادمة</p>
                                    <p className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                        <span className="font-bold text-gray-800">{item.readingDayName}</span>
                                        <time dateTime={item.readingDate} dir="ltr" className="font-display text-xl font-bold tabular-nums text-gray-900">{fullDate(item.readingDate)}</time>
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div className="border-t border-gray-100 px-5 py-4 sm:px-6">
                            {item.cycleId ? (
                                <Link href={`/reading-cycles/${item.cycleId}`} className={`${secondaryButtonClass} w-full justify-between`} aria-label={`فتح توزيع ${item.name}`}>
                                    فتح التوزيع
                                    <Icon name="chevron-left" className="h-4 w-4 shrink-0" />
                                </Link>
                            ) : canCreate ? (
                                <SecondaryButton disabled={preparingId !== null} onClick={() => prepare(item)} className="w-full justify-between" aria-label={`تجهيز وفتح توزيع ${item.name}`}>
                                    {preparingId === item.id ? 'جارٍ تجهيز القراءة...' : 'تجهيز وفتح التوزيع'}
                                    <Icon name={preparingId === item.id ? 'clock' : 'plus'} className="h-4 w-4 shrink-0" />
                                </SecondaryButton>
                            ) : <p className="py-2.5 text-sm text-gray-500">بانتظار تجهيز مسؤول التوزيع.</p>}
                        </div>
                    </section>
                ))}
            </div>

            {visible.length === 0 && (
                <div className="flex flex-col items-center gap-3 rounded-card border border-dashed border-gray-200 bg-surface px-5 py-12 text-center">
                    <Icon name={branches.length ? 'search' : 'office'} className="h-9 w-9 text-gray-400" />
                    <h3 className="font-bold text-gray-900">{branches.length ? 'لا توجد فروع مطابقة' : 'لا توجد فروع متاحة للتوزيع'}</h3>
                    <p className="text-sm text-gray-500">{branches.length ? 'غيّر اسم الفرع أو حالة التجهيز لعرض نتائج أخرى.' : 'تظهر هنا الفروع المفعّلة عند إضافتها.'}</p>
                    {branches.length > 0 && <SecondaryButton onClick={() => { setSearch(''); setStatus('all'); }}>إظهار كل الفروع</SecondaryButton>}
                </div>
            )}
        </div>
    );
}

/** Only the upcoming reading is presented; branch staff open it directly. */
export default function Index({ distributionBranches = [], canCreate, branch }) {
    return (
        <AuthenticatedLayout header={<PageHeader title="توزيع القراءة القادمة" info="يبقى توزيع الطبلونات على القرّاء كما هو لكل قراءة قادمة، حتى يغيّره مسؤول التوزيع." />}>
            <Head title="توزيع القراءة القادمة" />
            {branch ? (
                <div className="space-y-3 text-gray-700">
                    <h2 className="text-lg font-bold text-gray-900">القراءة القادمة {branch.readingDate}</h2>
                    <p>لم يُجهّز توزيع هذه القراءة بعد. سيظهر هنا بعد تجهيز مسؤول التوزيع له.</p>
                </div>
            ) : (
                <BranchOverview branches={distributionBranches} canCreate={canCreate} />
            )}
        </AuthenticatedLayout>
    );
}
