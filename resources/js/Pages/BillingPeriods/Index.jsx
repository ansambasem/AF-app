import { useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import DateInput from '@/Components/DateInput';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import ConfirmDialog from '@/Components/ConfirmDialog';
import StatusPill from '@/Components/DataTable/StatusPill';
import { formatNumber } from '@/lib/format';

const STATUS_TONES = { scheduled: 'blue', open: 'green', closed: 'amber', approved: 'gray' };

/** The next period's form: its first and last day, set ahead; it opens on its first day once the open period is closed. */
function NewPeriodForm({ branch, suggestion }) {
    const form = useForm({ branch_id: branch.id, starts_on: suggestion.startsOn, ends_on: suggestion.endsOn, notes: '' });

    function submit(event) {
        event.preventDefault();
        form.post('/billing-periods', { preserveScroll: true });
    }

    return (
        <form onSubmit={submit} className="rise-in rounded-panel border border-gray-100 bg-surface p-6 shadow-card">
            <h3 className="text-lg font-bold text-gray-900">فترة جديدة</h3>
            <p className="mt-1 text-sm text-gray-500">تُفتح الفترة في تاريخ بدايتها إذا لم تكن هناك فترة مفتوحة، أو فور إغلاق الفترة المفتوحة. لا تُغلق أي فترة تلقائيًا.</p>
            <div className="mt-5 grid gap-4 sm:grid-cols-3">
                <div>
                    <InputLabel htmlFor="starts_on" value="تاريخ البداية" />
                    <DateInput id="starts_on" name="starts_on" value={form.data.starts_on} onChange={(value) => form.setData('starts_on', value)} required className="mt-1 w-full" />
                    <InputError message={form.errors.starts_on} className="mt-2" />
                </div>
                <div>
                    <InputLabel htmlFor="ends_on" value="تاريخ النهاية" />
                    <DateInput id="ends_on" name="ends_on" value={form.data.ends_on} onChange={(value) => form.setData('ends_on', value)} min={form.data.starts_on} required className="mt-1 w-full" />
                    <InputError message={form.errors.ends_on} className="mt-2" />
                </div>
                <div>
                    <InputLabel htmlFor="notes" value="ملاحظات (اختياري)" />
                    <input id="notes" value={form.data.notes} onChange={(event) => form.setData('notes', event.target.value)} maxLength={1000}
                        className="mt-1 block w-full rounded-control border-gray-300 bg-surface text-gray-900" />
                </div>
            </div>
            <div className="mt-5 flex justify-end">
                <PrimaryButton disabled={form.processing}>إنشاء الفترة</PrimaryButton>
            </div>
        </form>
    );
}

/** A period not started yet, with its dates editable in place. */
function RescheduleForm({ period, onDone }) {
    const form = useForm({ starts_on: period.startsOn, ends_on: period.endsOn });

    function submit(event) {
        event.preventDefault();
        form.patch(`/billing-periods/${period.id}`, { preserveScroll: true, onSuccess: onDone });
    }

    return (
        <form onSubmit={submit} className="flex flex-wrap items-end gap-2">
            <DateInput aria-label="تاريخ البداية" value={form.data.starts_on} onChange={(value) => form.setData('starts_on', value)} />
            <DateInput aria-label="تاريخ النهاية" value={form.data.ends_on} onChange={(value) => form.setData('ends_on', value)} min={form.data.starts_on} />
            <PrimaryButton disabled={form.processing}>حفظ</PrimaryButton>
            <SecondaryButton onClick={onDone}>إلغاء</SecondaryButton>
            <InputError message={form.errors.starts_on ?? form.errors.ends_on} className="w-full" />
        </form>
    );
}

/**
 * A branch's billing periods: newest first, each with its days, its state
 * (scheduled, open, closed awaiting approval, approved), and what it holds;
 * the manager sets up the next one, closes the open one by hand (payments
 * are taken only while a period is open) and approves each closed one.
 */
export default function Index({ branches, branch, periods, suggestion, canManage }) {
    const [editing, setEditing] = useState(null);
    const [approving, setApproving] = useState(null);
    const [closing, setClosing] = useState(null);
    const waiting = periods.some((period) => period.status === 'scheduled');

    function close() {
        router.post(`/billing-periods/${closing.id}/close`, {}, { preserveScroll: true, onFinish: () => setClosing(null) });
    }

    function approve() {
        router.post(`/billing-periods/${approving.id}/approve`, {}, { preserveScroll: true, onFinish: () => setApproving(null) });
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    eyebrow={branch.name}
                    title="فترات الفوترة"
                    info="الفترة تحدّد القراءات والفواتير والدفعات وصناديق المحصّلين. تبقى مفتوحة مهما طالت حتى يغلقها مدير الفرع يدويًا فتُغلق صناديقها معها، وكل ما يُسجَّل أثناء فتحها يُحسب فيها. لا تُسجَّل الدفعات إلا في فترة مفتوحة. بعد الإغلاق يعتمدها مدير الفرع لتُقفل نهائيًا ويُحفظ رصيد كل مشترك فيها."
                    actions={branches.length > 1 && (
                        <select aria-label="الفرع" value={branch.id} onChange={(event) => router.get('/billing-periods', { branch_id: event.target.value })}
                            className="rounded-control border-gray-300 bg-surface text-sm text-gray-900">
                            {branches.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
                        </select>
                    )}
                />
            }
        >
            <Head title="فترات الفوترة" />

            <div className="space-y-6">
                {canManage && !waiting && <NewPeriodForm key={`${branch.id}-${suggestion.startsOn}`} branch={branch} suggestion={suggestion} />}

                <section className="rise-in overflow-hidden rounded-panel border border-gray-100 bg-surface shadow-card">
                    <table className="w-full text-start text-sm" aria-label="فترات الفوترة">
                        <thead className="bg-gray-50 text-gray-600">
                            <tr>
                                <th className="px-4 py-3 text-start font-semibold">الفترة</th>
                                <th className="px-4 py-3 text-start font-semibold">الحالة</th>
                                <th className="px-4 py-3 text-start font-semibold">فُتحت</th>
                                <th className="px-4 py-3 text-start font-semibold">أُغلقت</th>
                                <th className="px-4 py-3 text-start font-semibold">القراءات</th>
                                <th className="px-4 py-3 text-start font-semibold">الحركات</th>
                                <th className="px-4 py-3 text-start font-semibold">الاعتماد</th>
                                <th className="px-4 py-3" />
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {periods.length === 0 && (
                                <tr><td colSpan={8} className="px-4 py-12 text-center text-gray-500">لا توجد فترات بعد. أنشئ أول فترة لتبدأ إدخال قراءاتها.</td></tr>
                            )}
                            {periods.map((period) => (
                                <tr key={period.id}>
                                    <td className="px-4 py-3 font-semibold text-gray-900" dir="ltr">
                                        {editing === period.id ? <RescheduleForm period={period} onDone={() => setEditing(null)} /> : <span>{period.label}</span>}
                                        {period.notes && editing !== period.id && <p className="mt-0.5 text-xs font-normal text-gray-500" dir="rtl">{period.notes}</p>}
                                    </td>
                                    <td className="px-4 py-3"><StatusPill tone={STATUS_TONES[period.status]} label={period.statusLabel} /></td>
                                    <td className="px-4 py-3 text-gray-600">{period.openedAt ?? `يبدأ ${period.startsOn}`}</td>
                                    <td className="px-4 py-3 text-gray-600">{period.closedAt ?? '—'}</td>
                                    <td className="px-4 py-3 tabular-nums">{formatNumber(period.readingsCount)}</td>
                                    <td className="px-4 py-3 tabular-nums">{formatNumber(period.linesCount)}</td>
                                    <td className="px-4 py-3 text-gray-600">{period.approvedAt ? `${period.approvedAt}${period.approvedBy ? ` · ${period.approvedBy}` : ''}` : '—'}</td>
                                    <td className="px-4 py-3 text-end">
                                        {canManage && period.status === 'scheduled' && editing !== period.id && <SecondaryButton onClick={() => setEditing(period.id)}>تعديل التاريخين</SecondaryButton>}
                                        {canManage && period.status === 'open' && <SecondaryButton onClick={() => setClosing(period)}>إغلاق الفترة</SecondaryButton>}
                                        {canManage && period.status === 'closed' && <PrimaryButton type="button" onClick={() => setApproving(period)}>اعتماد وإقفال</PrimaryButton>}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </section>
            </div>

            <ConfirmDialog
                show={closing !== null}
                onCancel={() => setClosing(null)}
                onConfirm={close}
                title="إغلاق الفترة؟"
                message={closing && `ستُغلق الفترة ${closing.label} ولن تُسجَّل فيها دفعات جديدة، وتُغلق معها صناديق المحصّلين المفتوحة لتظهر للفرع والتدقيق. تبقى حركاتها كما هي، ويستطيع مدير الفرع تعديلها حتى يعتمدها. تُسجَّل الدفعات بعدها في الفترة التالية وصناديقها الجديدة.`}
                confirmLabel="إغلاق الفترة"
            />

            <ConfirmDialog
                show={approving !== null}
                onCancel={() => setApproving(null)}
                onConfirm={approve}
                title="اعتماد الفترة وإقفالها؟"
                message={approving && `ستُقفل الفترة ${approving.label} نهائيًا، ويُحفظ رصيد كل مشترك فيها كما هو. لا يمكن بعدها تعديل قراءاتها أو حركاتها، وأي تصحيح يُسجَّل في الفترة المفتوحة.`}
                confirmLabel="اعتماد وإقفال"
            />
        </AuthenticatedLayout>
    );
}
