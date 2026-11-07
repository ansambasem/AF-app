import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DataTableToolbar from '@/Components/DataTable/DataTableToolbar';
import DataTableFilterMenu from '@/Components/DataTable/DataTableFilterMenu';
import SortableTh from '@/Components/DataTable/SortableTh';
import StatusPill from '@/Components/DataTable/StatusPill';
import Pagination from '@/Components/DataTable/Pagination';
import { useDataTable } from '@/hooks/useDataTable';
import PageHeader from '@/Components/PageHeader';

const TONES = { accepted: 'green', needs_review: 'amber', rejected: 'red' };

/**
 * The readings a cycle's field readers uploaded, with what became of each.
 * Those held for review — taken outside the reading day, after a box was
 * reassigned, or in conflict with a reading already there — are saved or
 * refused here by a supervisor.
 */
export default function Submissions({ cycle, submissions, canReview, filters, filterOptions }) {
    const { search, setSearch, sort, setPerPage, filterValues, setFilter, setFilters, clearFilters } = useDataTable(`/reading-cycles/${cycle.id}/submissions`, filters);
    const [notes, setNotes] = useState({});

    function review(submission, decision) {
        router.post(`/reading-submissions/${submission.id}/review`, { decision, note: notes[submission.id] ?? '' }, { preserveScroll: true });
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    eyebrow={<><Link href="/reading-cycles" className="hover:underline">توزيع القراءات</Link> / <Link href={`/reading-cycles/${cycle.id}`} className="hover:underline">{cycle.branchName} · {cycle.readingDate}</Link></>}
                    title="القراءات الميدانية"
                    info="كل قراءة رفعها التطبيق ومصيرها. المحفوظة للمراجعة لا تُحتسب حتى يقبلها مشرف."
                />
            }
        >
            <Head title="القراءات الميدانية" />

            <DataTableToolbar
                search={search}
                onSearchChange={setSearch}
                showSearch={false}
                perPage={filters.per_page}
                onPerPageChange={setPerPage}
                total={submissions.total}
                printable={false}
                filterMenu={<DataTableFilterMenu tableKey="reading_submissions" groups={filterOptions} values={filterValues} onChange={setFilter} onChangeMany={setFilters} onClear={clearFilters} />}
            />

            <div className="data-table-container">
                <table className="data-table w-full text-sm text-start" aria-label="القراءات الميدانية">
                    <thead>
                        <tr>
                            <th>المشترك</th>
                            <th>الطبلون</th>
                            <th>القارئ</th>
                            <th>القراءة</th>
                            <SortableTh column="captured_at" label="وقت التسجيل" sortState={filters} onSort={sort} />
                            <SortableTh column="received_at" label="وقت الوصول" sortState={filters} onSort={sort} />
                            <th>الحالة</th>
                            {canReview && <th>المراجعة</th>}
                        </tr>
                    </thead>
                    <tbody>
                        {submissions.data.length === 0 ? (
                            <tr>
                                <td colSpan={canReview ? 8 : 7} className="text-gray-500">لا توجد قراءات مرفوعة.</td>
                            </tr>
                        ) : (
                            submissions.data.map((submission) => (
                                <tr key={submission.id}>
                                    <td data-label="المشترك">
                                        <b className="text-gray-900">{submission.name}</b>
                                        <span className="block text-xs text-gray-500" dir="ltr">{submission.accountNumber}</span>
                                    </td>
                                    <td data-label="الطبلون" className="text-gray-600">{submission.boxLabel}</td>
                                    <td data-label="القارئ" className="text-gray-600">{submission.readerName}</td>
                                    <td data-label="القراءة" className="font-display font-semibold">{submission.currentReading}</td>
                                    <td data-label="وقت التسجيل" className="whitespace-nowrap text-xs text-gray-600" dir="ltr">{submission.capturedAt}</td>
                                    <td data-label="وقت الوصول" className="whitespace-nowrap text-xs text-gray-600" dir="ltr">{submission.receivedAt}</td>
                                    <td data-label="الحالة">
                                        <StatusPill tone={TONES[submission.status]} label={submission.statusLabel} />
                                        {submission.issue && <span className="mt-1 block max-w-[16rem] text-xs text-gray-600">{submission.issue}</span>}
                                        {submission.reviewedBy && <span className="mt-1 block text-xs text-gray-500">راجعها {submission.reviewedBy}{submission.reviewNote ? `: ${submission.reviewNote}` : ''}</span>}
                                    </td>
                                    {canReview && (
                                        <td data-label="المراجعة">
                                            {submission.status === 'needs_review' ? (
                                                <div className="flex min-w-[14rem] flex-col gap-2">
                                                    <input type="text" placeholder="ملاحظة (اختياري)" value={notes[submission.id] ?? ''} onChange={(event) => setNotes((current) => ({ ...current, [submission.id]: event.target.value }))} className="block w-full text-xs" />
                                                    <div className="flex gap-2">
                                                        <button type="button" onClick={() => review(submission, 'accept')} className="rounded-control bg-emerald-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-emerald-700">قبول كقراءة الأسبوع</button>
                                                        <button type="button" onClick={() => review(submission, 'reject')} className="rounded-control border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50">رفض</button>
                                                    </div>
                                                </div>
                                            ) : (
                                                <span className="text-gray-400">—</span>
                                            )}
                                        </td>
                                    )}
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>

            <Pagination meta={submissions} filters={filters} baseUrl={`/reading-cycles/${cycle.id}/submissions`} />
        </AuthenticatedLayout>
    );
}
