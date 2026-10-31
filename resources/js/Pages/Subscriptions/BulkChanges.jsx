import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Icon from '@/Components/Icon';
import Modal from '@/Components/Modal';
import SecondaryButton from '@/Components/SecondaryButton';
import ConfirmDialog from '@/Components/ConfirmDialog';
import DataTableToolbar from '@/Components/DataTable/DataTableToolbar';
import Pagination from '@/Components/DataTable/Pagination';
import ActionsTh from '@/Components/DataTable/ActionsTh';
import StatusPill from '@/Components/DataTable/StatusPill';
import { useDataTable } from '@/hooks/useDataTable';
import { timeAgo } from '@/lib/format';
import PageHeader, { BackLink } from '@/Components/PageHeader';

const FIELD_ICONS = { minimum_charge: 'wallet', status: 'flag', meter_box_id: 'grid' };

const DATE_TIME_FORMAT = new Intl.DateTimeFormat('ar-EG-u-nu-latn', { dateStyle: 'medium', timeStyle: 'short' });

const FOCUS_RING = 'focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-900';

/** One change's subscriptions: each one's value before, after, and now. */
function DetailsModal({ change, details, onClose }) {
    const loaded = details && details.id === change.id;

    return (
        <Modal show onClose={onClose} maxWidth="3xl" centered>
            <div role="dialog" aria-modal="true" aria-labelledby="bulk-details-title">
                <div className="flex items-start justify-between gap-4 px-7 pb-3 pt-7">
                    <div>
                        <h3 id="bulk-details-title" className="flex items-center gap-2 text-lg font-bold text-gray-900">
                            <Icon name={FIELD_ICONS[change.field]} className="h-5 w-5" />
                            {change.description}
                        </h3>
                        <p className="mt-1 text-sm text-gray-500">
                            {change.changedCount.toLocaleString('en')} مشترك — {DATE_TIME_FORMAT.format(new Date(change.createdAt))}
                        </p>
                    </div>
                    <button type="button" onClick={onClose} aria-label="إغلاق" className={`rounded-lg p-1.5 text-gray-500 hover:bg-gray-100 ${FOCUS_RING}`}>
                        <Icon name="close" className="h-5 w-5" />
                    </button>
                </div>
                <div className="max-h-[60vh] overflow-y-auto px-7 pb-7">
                    {!loaded ? (
                        <p className="py-8 text-center text-sm text-gray-500">جارٍ التحميل...</p>
                    ) : (
                        <table className="w-full text-start text-sm">
                            <thead className="sticky top-0 bg-surface">
                                <tr className="border-b border-gray-100 text-xs text-gray-600">
                                    <th className="py-2 text-start font-semibold">المشترك</th>
                                    <th className="px-3 py-2 text-start font-semibold">قبل</th>
                                    <th className="px-3 py-2 text-start font-semibold">بعد</th>
                                    <th className="px-3 py-2 text-start font-semibold">الآن</th>
                                </tr>
                            </thead>
                            <tbody>
                                {details.items.map((item) => (
                                    <tr key={item.id} className="border-b border-gray-100 last:border-0">
                                        <td className="py-2">
                                            <span className="font-semibold text-gray-900">{item.name}</span>
                                            {item.accountNumber && <span className="block text-xs text-gray-500">{item.accountNumber}</span>}
                                        </td>
                                        <td className="px-3 py-2 text-gray-600">{item.old}</td>
                                        <td className="px-3 py-2 text-gray-900">{item.new}</td>
                                        <td className={`px-3 py-2 ${item.now === item.new ? 'text-gray-600' : 'font-semibold text-amber-700'}`}>{item.now}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            </div>
        </Modal>
    );
}

/**
 * Every change made to many subscriptions at once from the subscriptions list,
 * newest first: what was set, for how many, by whom — and an undo, which
 * puts back each subscription's old value unless it was changed again since.
 */
export default function BulkChanges({ changes, filters, details }) {
    const { setPerPage } = useDataTable('/subscriptions/bulk-changes', filters);
    const [viewing, setViewing] = useState(null);
    const [undoing, setUndoing] = useState(null);

    function view(change) {
        setViewing(change);
        router.reload({ only: ['details'], data: { change: change.id } });
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader eyebrow={<BackLink href="/subscriptions">المشتركون</BackLink>} title="سجل التعديلات الجماعية" info="كل تعديل طُبّق على عدة مشتركين معًا، مع إمكانية التراجع عنه." />
            }
        >
            <Head title="سجل التعديلات الجماعية" />

            <DataTableToolbar showSearch={false} perPage={filters.per_page} onPerPageChange={setPerPage} total={changes.total} />

            <div className="data-table-container">
                <table className="data-table w-full text-sm text-start">
                    <thead>
                        <tr>
                            <th>الوقت</th>
                            <th>التعديل</th>
                            <th>المشتركون</th>
                            <th>الفرع</th>
                            <th>بواسطة</th>
                            <th>الحالة</th>
                            <ActionsTh />
                        </tr>
                    </thead>
                    <tbody>
                        {changes.data.length === 0 ? (
                            <tr>
                                <td className="text-gray-500" colSpan={7}>
                                    لا توجد تعديلات جماعية بعد. حدّد مشتركين من قائمة المشتركين لتطبيق تعديل عليهم.
                                </td>
                            </tr>
                        ) : (
                            changes.data.map((change) => (
                                <tr key={change.id}>
                                    <td className="text-gray-600" title={DATE_TIME_FORMAT.format(new Date(change.createdAt))}>
                                        {timeAgo(change.createdAt)}
                                    </td>
                                    <td>
                                        <span className="flex items-center gap-2 font-semibold text-gray-900">
                                            <Icon name={FIELD_ICONS[change.field]} className="h-4 w-4 shrink-0 text-gray-500" />
                                            {change.description}
                                        </span>
                                    </td>
                                    <td className="text-gray-700">{change.changedCount.toLocaleString('en')}</td>
                                    <td className="text-gray-600">{change.branchName}</td>
                                    <td className="text-gray-600">{change.userName ?? '—'}</td>
                                    <td>
                                        {change.undoneAt ? (
                                            <span>
                                                <StatusPill tone="gray" label="تم التراجع" />
                                                <span className="mt-1 block text-xs text-gray-500">
                                                    أُعيد {change.restoredCount?.toLocaleString('en')} من {change.changedCount.toLocaleString('en')} — {change.undoneBy}
                                                </span>
                                            </span>
                                        ) : (
                                            <StatusPill tone="green" label="مطبّق" />
                                        )}
                                    </td>
                                    <td className="text-end">
                                        <div className="flex justify-end gap-1.5">
                                            <SecondaryButton onClick={() => view(change)} className="!px-3 !py-1.5 text-xs" aria-label={`عرض مشتركي «${change.description}»`}>
                                                <Icon name="eye" className="h-4 w-4" />
                                                المشتركون
                                            </SecondaryButton>
                                            {change.canUndo && (
                                                <SecondaryButton
                                                    onClick={() => setUndoing(change)}
                                                    className="!px-3 !py-1.5 text-xs text-brand-600"
                                                    aria-label={`التراجع عن «${change.description}»`}
                                                >
                                                    <Icon name="undo" className="h-4 w-4" />
                                                    تراجع
                                                </SecondaryButton>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>

            <Pagination meta={changes} filters={filters} baseUrl="/subscriptions/bulk-changes" />

            {viewing && <DetailsModal change={viewing} details={details} onClose={() => setViewing(null)} />}

            <ConfirmDialog
                show={undoing !== null}
                onConfirm={() => {
                    router.post(`/subscriptions/bulk-changes/${undoing.id}/undo`, {}, { preserveScroll: true });
                    setUndoing(null);
                }}
                onCancel={() => setUndoing(null)}
                title="التراجع عن التعديل؟"
                message={`تعود قيمة كل مشترك إلى ما كانت عليه قبل «${undoing?.description ?? ''}». من تغيّرت قيمته بعدها يبقى كما هو.`}
                confirmLabel="نعم، تراجع"
                icon="undo"
                tone="danger"
            />
        </AuthenticatedLayout>
    );
}
