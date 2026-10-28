import Icon from '@/Components/Icon';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { permissionsWord } from '@/lib/permissions';
import { RiskBadge } from './PermissionMatrix';

/** «+3 −1»: what a change would grant and take away. */
export function Delta({ added, removed }) {
    return (
        <bdi dir="ltr" className="pm-delta">
            {added > 0 && <span className="is-added">+{added}</span>}
            {removed > 0 && <span className="is-removed">−{removed}</span>}
            {added === 0 && removed === 0 && <span>=</span>}
        </bdi>
    );
}

/**
 * Every change before it is saved, grouped by section: each permission
 * that will be granted or taken away, with what it opens and its risk.
 */
export function PermissionChangeReview({ employee, changes }) {
    const bySection = changes.reduce((groups, change) => {
        (groups[change.section] ??= []).push(change);
        return groups;
    }, {});

    return (
        <div className="px-5 pb-5 sm:px-7">
            <p className="mb-4 rounded-control border border-gray-100 bg-gray-50 p-3 text-sm leading-6 text-gray-600">
                <b className="text-gray-900">{employee.name}</b> · {employee.roleLabel} · {employee.branchName ?? 'بلا فرع'}
                <br />
                لا تتغيّر الصلاحيات خارج نطاق إدارتك.
            </p>
            <div className="max-h-[50vh] space-y-3 overflow-y-auto" tabIndex={0} aria-label="جميع تغييرات الصلاحيات">
                {Object.entries(bySection).map(([section, items]) => (
                    <section key={section} className="overflow-hidden rounded-control border border-gray-100">
                        <h4 className="bg-gray-50 px-3 py-1.5 text-sm font-bold text-gray-700">{section}</h4>
                        <ul className="space-y-1 p-1.5">
                            {items.map((change) => (
                                <li
                                    key={change.id}
                                    className={`flex items-start gap-2.5 rounded-[10px] px-2.5 py-2 ${change.added ? 'bg-emerald-500/[0.07]' : 'bg-gray-50'}`}
                                >
                                    <b
                                        className={`mt-px w-3 text-center ${change.added ? 'text-emerald-700 dark:text-emerald-400' : 'text-gray-400'}`}
                                    >
                                        {change.added ? '+' : '−'}
                                    </b>
                                    <div className="min-w-0 flex-1">
                                        <p
                                            className={`flex flex-wrap items-center gap-1.5 text-sm font-semibold ${change.added ? 'text-gray-900' : 'text-gray-500 line-through'}`}
                                        >
                                            {change.label}
                                            {change.added && <RiskBadge risk={change.risk} />}
                                        </p>
                                        {change.hint && <p className="text-xs text-gray-500">{change.hint}</p>}
                                    </div>
                                    <span
                                        className={`shrink-0 text-xs font-bold ${change.added ? 'text-emerald-700 dark:text-emerald-400' : 'text-gray-500'}`}
                                    >
                                        {change.added ? 'سيُمنح' : 'سيُلغى'}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                ))}
            </div>
        </div>
    );
}

/** The bar at the bottom while there are unsaved changes: how many, what they grant and take away, and undo or review. */
export function PermissionSaveBar({ dirty, errors, changes, auditChanges, firstName, added, removed, dangerousGrants, processing, onDiscard, onSave }) {
    return (
        <div role="region" aria-label="تغييرات لم تُحفظ" className={`pm-savebar ${dirty ? 'is-visible' : ''}`} aria-hidden={!dirty} inert={!dirty}>
            {Object.keys(errors).length > 0 && (
                <p className="w-full text-sm font-semibold text-red-300" role="alert">
                    تعذّر حفظ الصلاحيات: {Object.values(errors)[0]}
                </p>
            )}
            <p className="flex min-w-0 flex-1 flex-wrap items-center gap-2 text-sm" role="status">
                <span className="flex h-7 min-w-[1.75rem] items-center justify-center rounded-full bg-white px-2 font-display text-sm font-bold text-gray-900">
                    {changes.length + auditChanges.length}
                </span>
                <span>تغييرات لم تُحفظ لـ {firstName}</span>
                {changes.length > 0 && <Delta added={added} removed={removed} />}
                {dangerousGrants.length > 0 && (
                    <span className="inline-flex items-center gap-1 rounded-full bg-red-500/20 px-2 py-0.5 text-xs font-bold text-red-200">
                        <Icon name="alert" className="h-3.5 w-3.5" />
                        {dangerousGrants.length} خطيرة
                    </span>
                )}
            </p>
            <button
                type="button"
                onClick={onDiscard}
                disabled={processing || !dirty}
                className="inline-flex items-center gap-1.5 rounded-control bg-white/10 px-3 py-2 text-sm font-semibold text-white transition hover:bg-white/20 focus-visible:outline focus-visible:outline-2 focus-visible:outline-white disabled:opacity-40"
            >
                <Icon name="undo" className="h-4 w-4" />
                تراجع
            </button>
            <PrimaryButton type="button" onClick={onSave} disabled={processing || !dirty} title="Ctrl + S">
                <Icon name="check" className="h-4 w-4" strokeWidth={2} />
                {processing ? 'جارٍ الحفظ...' : 'مراجعة وحفظ'}
            </PrimaryButton>
        </div>
    );
}

/**
 * The review before saving: before and after counts, every change, what is
 * taken back from those the employee granted it to, the audit work's changes,
 * and the acknowledgement dangerous grants need.
 */
export function PermissionReviewDialog({
    show,
    employee,
    changes,
    auditChanges,
    keyChanges,
    takenFromOthers,
    dangerousGrants,
    added,
    removed,
    before,
    after,
    heroRisk,
    firstName,
    acknowledged,
    onAcknowledgedChange,
    dirty,
    processing,
    onCancel,
    onConfirm,
}) {
    return (
        <Modal
            show={show}
            onClose={() => {
                if (!processing) {
                    onCancel();
                }
            }}
            maxWidth="2xl"
            centered
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby={`permission-review-${employee.id}`}
                dir="rtl"
                className="flex max-h-[90dvh] min-h-0 flex-col"
            >
                <div className="shrink-0 px-5 pb-4 pt-6 sm:px-7">
                    <h3 id={`permission-review-${employee.id}`} className="text-lg font-bold text-gray-900">
                        مراجعة تغييرات الصلاحيات
                    </h3>
                    <p className="mt-1 text-sm text-gray-500">
                        {added} تُمنح · {removed} تُلغى
                        {auditChanges.length > 0 && ` · ${auditChanges.length} تغييرات في عمل التدقيق`}
                        {keyChanges.added + keyChanges.removed > 0 && ` · حق المنح: ${keyChanges.added} يُعطى، ${keyChanges.removed} يُسحب`}
                    </p>
                    <div className="pm-review-strip">
                        <span>
                            قبل: <b className="font-display">{before.total}</b> {permissionsWord(before.total)}، منها{' '}
                            <b className="font-display">{before.danger}</b> خطيرة
                        </span>
                        <Icon name="arrow-left" className="h-4 w-4 shrink-0 text-gray-400" />
                        <span>
                            بعد: <b className="font-display">{after.total}</b> {permissionsWord(after.total)}، منها{' '}
                            <b className={`font-display ${after.danger > before.danger ? 'text-red-700 dark:text-red-400' : ''}`}>
                                {after.danger}
                            </b>{' '}
                            خطيرة
                        </span>
                        <span className={`pm-review-level is-${heroRisk.level}`}>خطورة {heroRisk.label}</span>
                    </div>
                </div>
                <div className="min-h-0 flex-1 overflow-y-auto">
                    <PermissionChangeReview employee={employee} changes={changes} />
                    {takenFromOthers.length > 0 && (
                        <p className="mx-5 mb-5 rounded-control bg-amber-500/10 px-3.5 py-3 text-sm leading-7 text-amber-800 dark:text-amber-300 sm:mx-7">
                            تُسحب أيضًا من الموظفين الذين منحهم {firstName} إياها:{' '}
                            <b>{takenFromOthers.map((change) => `${change.text} (${employee.grantsGiven[change.id]})`).join('، ')}</b>
                        </p>
                    )}
                    {auditChanges.length > 0 && (
                        <div className="mx-5 mb-5 space-y-3 rounded-control border border-gray-100 p-4 sm:mx-7">
                            <h4 className="font-bold text-gray-900">تغييرات عمل التدقيق</h4>
                            {auditChanges.map((change) => (
                                <div key={change.key} className="text-sm leading-7">
                                    <b className="text-gray-900">{change.label}</b>
                                    <p className="text-gray-500">قبل: {change.before}</p>
                                    <p className="font-semibold text-gray-900">بعد: {change.after}</p>
                                </div>
                            ))}
                        </div>
                    )}
                    {dangerousGrants.length > 0 && (
                        <label className="mx-5 mb-5 flex cursor-pointer items-start gap-2.5 rounded-control bg-red-500/[0.07] px-3.5 py-3 text-sm text-red-800 dark:text-red-300 sm:mx-7">
                            <input
                                type="checkbox"
                                checked={acknowledged}
                                onChange={(event) => onAcknowledgedChange(event.target.checked)}
                                className="mt-0.5 rounded border-gray-300 text-brand-600"
                            />
                            <span>
                                يحصل {firstName} على صلاحيات خطيرة: <b>{dangerousGrants.map((item) => item.label).join('، ')}</b>. أؤكد أنه
                                يحتاجها لعمله.
                            </span>
                        </label>
                    )}
                </div>
                <div className="flex shrink-0 flex-wrap justify-end gap-3 border-t border-gray-100 bg-gray-50 px-5 py-4 sm:px-7">
                    <SecondaryButton onClick={onCancel} disabled={processing} autoFocus>
                        العودة للتعديل
                    </SecondaryButton>
                    <PrimaryButton
                        type="button"
                        onClick={onConfirm}
                        disabled={processing || !dirty || (dangerousGrants.length > 0 && !acknowledged)}
                    >
                        {processing ? 'جارٍ الحفظ...' : 'تأكيد وحفظ الصلاحيات'}
                    </PrimaryButton>
                </div>
            </div>
        </Modal>
    );
}
