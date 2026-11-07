import { useEffect, useId, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import Icon from '@/Components/Icon';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import ConfirmDialog from '@/Components/ConfirmDialog';
import InputError from '@/Components/InputError';
import ActionsTh from '@/Components/DataTable/ActionsTh';
import { printUrl } from '@/lib/print';
import { PAPER_SIZES } from '@/lib/printLayout';
import { timeAgo } from '@/lib/format';
import PageHeader from '@/Components/PageHeader';
import AddButton from '@/Components/AddButton';

const FOCUS_RING = 'focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-900';

/** Open the print designer on a list, with one of its templates (an id) or a new one. */
function openDesigner(page, template) {
    window.open(`${printUrl(page.label, page.path)}&print_template=${template}`, '_blank');
}

/** A small labelled action button with its icon. */
function ActionButton({ icon, label, onClick, tone = 'gray', srLabel }) {
    const tones = {
        gray: 'border-gray-200 text-gray-700 hover:border-gray-300 hover:text-gray-900',
        brand: 'border-brand-500/30 text-brand-600 hover:bg-brand-500/5',
        green: 'border-emerald-500/30 text-emerald-700 hover:bg-emerald-500/5 dark:text-emerald-400',
    };

    return (
        <button
            type="button"
            onClick={onClick}
            aria-label={srLabel}
            title={srLabel}
            className={`inline-flex items-center gap-1.5 whitespace-nowrap rounded-control border bg-surface px-2.5 py-1.5 text-xs font-semibold transition ${tones[tone]} ${FOCUS_RING}`}
        >
            <Icon name={icon} className="h-4 w-4" />
            {label}
        </button>
    );
}

function RenameModal({ template, onClose }) {
    const id = useId();
    const { errors } = usePage().props;
    const [name, setName] = useState(template.name);
    const [saving, setSaving] = useState(false);

    function save(event) {
        event.preventDefault();
        setSaving(true);
        router.put(`/print-templates/${template.id}`, { name: name.trim() }, { preserveScroll: true, onSuccess: onClose, onFinish: () => setSaving(false) });
    }

    return (
        <Modal show onClose={onClose} maxWidth="md" centered>
            <form onSubmit={save} role="dialog" aria-modal="true" aria-labelledby={`${id}-title`}>
                <div className="px-7 pb-4 pt-7">
                    <h3 id={`${id}-title`} className="flex items-center gap-2 text-lg font-bold text-gray-900">
                        <Icon name="pencil" className="h-5 w-5" />
                        إعادة تسمية القالب
                    </h3>
                    <label htmlFor={`${id}-name`} className="mb-1.5 mt-5 block text-sm font-semibold text-gray-700">
                        الاسم الجديد
                    </label>
                    <input id={`${id}-name`} type="text" value={name} onChange={(e) => setName(e.target.value)} className="block w-full text-sm" autoFocus />
                    <InputError message={errors.name} className="mt-1" />
                </div>
                <div className="flex justify-end gap-3 border-t border-gray-100 bg-gray-50 px-7 py-4">
                    <SecondaryButton onClick={onClose}>إلغاء</SecondaryButton>
                    <PrimaryButton type="submit" disabled={saving || name.trim() === ''}>
                        <Icon name="check" className="h-4 w-4" />
                        حفظ الاسم
                    </PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}

function NewTemplateModal({ pages, onClose }) {
    const id = useId();
    const [path, setPath] = useState(pages[0]?.path ?? '');

    return (
        <Modal show onClose={onClose} maxWidth="lg" centered>
            <div role="dialog" aria-modal="true" aria-labelledby={`${id}-title`}>
                <div className="px-7 pb-4 pt-7">
                    <h3 id={`${id}-title`} className="flex items-center gap-2 text-lg font-bold text-gray-900">
                        <Icon name="plus" className="h-5 w-5" />
                        قالب طباعة جديد
                    </h3>
                    <p className="mt-1.5 text-sm text-gray-600">اختر القائمة، فيُفتح مصمم الطباعة على بياناتها. صمّم كما تريد ثم احفظه كقالب.</p>
                    <fieldset className="mt-5">
                        <legend className="mb-2 text-sm font-semibold text-gray-700">القائمة</legend>
                        <div className="grid grid-cols-2 gap-2">
                            {pages.map((page) => (
                                <label
                                    key={page.path}
                                    className={`flex cursor-pointer items-center gap-2 rounded-control border px-3 py-2.5 text-sm font-semibold transition ${
                                        path === page.path ? 'border-gray-900 bg-gray-900 text-surface' : 'border-gray-200 text-gray-700 hover:border-gray-300'
                                    }`}
                                >
                                    <input type="radio" name={`${id}-page`} value={page.path} checked={path === page.path} onChange={() => setPath(page.path)} className="sr-only" />
                                    <Icon name={page.icon} className="h-4 w-4" />
                                    {page.label}
                                </label>
                            ))}
                        </div>
                    </fieldset>
                </div>
                <div className="flex justify-end gap-3 border-t border-gray-100 bg-gray-50 px-7 py-4">
                    <SecondaryButton onClick={onClose}>إلغاء</SecondaryButton>
                    <PrimaryButton
                        type="button"
                        onClick={() => {
                            openDesigner(pages.find((page) => page.path === path), 'new');
                            onClose();
                        }}
                    >
                        <Icon name="external" className="h-4 w-4" />
                        فتح المصمم
                    </PrimaryButton>
                </div>
            </div>
        </Modal>
    );
}

/** A template's paper, orientation and number of printed columns, in words. */
function templateSummary(template) {
    const paper = PAPER_SIZES[template.paper]?.label ?? template.paper;

    return `${paper} ${template.orientation === 'landscape' ? 'عرضي' : 'طولي'} · ${template.columnCount} أعمدة`;
}

/**
 * Every print template of the company in one place, under the list each
 * one prints: open one in the print designer to change it (on the list's
 * real rows), copy, rename, delete, or make it the list's default — the
 * design its print button starts from.
 */
export default function PrintTemplates({ pages }) {
    const [creating, setCreating] = useState(false);
    const [renaming, setRenaming] = useState(null);
    const [deleting, setDeleting] = useState(null);
    const total = pages.reduce((count, page) => count + page.templates.length, 0);

    // A template saved in the designer's tab shows here on coming back.
    useEffect(() => {
        function refresh() {
            if (document.visibilityState === 'visible') {
                router.reload({ only: ['pages'] });
            }
        }

        document.addEventListener('visibilitychange', refresh);
        return () => document.removeEventListener('visibilitychange', refresh);
    }, []);

    function update(template, data) {
        router.put(`/print-templates/${template.id}`, data, { preserveScroll: true });
    }

    return (
        <SettingsLayout
            header={
                <PageHeader
                    title="قوالب الطباعة"
                    meta={`${total.toLocaleString('en')} قالب محفوظ لكل الشركة`}
                    actions={<AddButton onClick={() => setCreating(true)}>قالب جديد</AddButton>}
                />
            }
        >
            <Head title="قوالب الطباعة" />

            <div className="space-y-6">
                {pages.map((page) => (
                    <section key={page.path} aria-labelledby={`list-${page.path}`} className="rounded-card border border-gray-100 bg-surface p-5 shadow-card sm:p-6">
                        <div className="mb-4 flex flex-wrap items-center gap-3">
                            <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-graphite-gradient text-white dark:ring-1 dark:ring-white/10">
                                <Icon name={page.icon} className="h-5 w-5" />
                            </span>
                            <div className="min-w-0 flex-1">
                                <h3 id={`list-${page.path}`} className="font-bold text-gray-900">
                                    {page.label}
                                </h3>
                                <p className="text-xs text-gray-500">{page.templates.length === 0 ? 'لا توجد قوالب بعد' : `${page.templates.length} قالب`}</p>
                            </div>
                            <ActionButton icon="plus" label="قالب جديد لها" srLabel={`قالب طباعة جديد لـ«${page.label}»`} onClick={() => openDesigner(page, 'new')} />
                        </div>

                        {page.templates.length > 0 && (
                            <div className="overflow-x-auto">
                                <table className="w-full text-start text-sm">
                                    <thead>
                                        <tr className="border-b border-gray-100 text-xs text-gray-600">
                                            <th className="py-2 pe-3 text-start font-semibold">القالب</th>
                                            <th className="px-3 py-2 text-start font-semibold">الورق والأعمدة</th>
                                            <th className="px-3 py-2 text-start font-semibold">آخر تعديل</th>
                                            <ActionsTh />
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {page.templates.map((template) => (
                                            <tr key={template.id} className="border-b border-gray-100 last:border-0">
                                                <td className="py-3 pe-3">
                                                    <span className="flex flex-wrap items-center gap-2 font-semibold text-gray-900">
                                                        {template.name}
                                                        {template.isDefault && (
                                                            <span className="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2 py-0.5 text-[11px] font-semibold text-emerald-800 dark:text-emerald-300">
                                                                <Icon name="check" className="h-3 w-3" strokeWidth={2.5} />
                                                                الافتراضي
                                                            </span>
                                                        )}
                                                    </span>
                                                </td>
                                                <td className="px-3 py-3 text-gray-600">{templateSummary(template)}</td>
                                                <td className="px-3 py-3 text-gray-600">
                                                    {timeAgo(template.updatedAt)}
                                                    {template.updatedBy && <span className="block text-xs text-gray-500">{template.updatedBy}</span>}
                                                </td>
                                                <td className="py-3 ps-3">
                                                    <div className="flex flex-wrap justify-end gap-1.5">
                                                        <ActionButton
                                                            icon="pencil"
                                                            label="تصميم"
                                                            tone="brand"
                                                            srLabel={`فتح «${template.name}» في مصمم الطباعة`}
                                                            onClick={() => openDesigner(page, template.id)}
                                                        />
                                                        <ActionButton
                                                            icon={template.isDefault ? 'close' : 'check'}
                                                            label={template.isDefault ? 'إلغاء الافتراضي' : 'جعله الافتراضي'}
                                                            tone={template.isDefault ? 'gray' : 'green'}
                                                            srLabel={template.isDefault ? `إلغاء كون «${template.name}» الافتراضي` : `جعل «${template.name}» الافتراضي لـ«${page.label}»`}
                                                            onClick={() => update(template, { is_default: !template.isDefault })}
                                                        />
                                                        <ActionButton
                                                            icon="document-plus"
                                                            label="نسخ"
                                                            srLabel={`نسخ «${template.name}»`}
                                                            onClick={() => router.post(`/print-templates/${template.id}/duplicate`, {}, { preserveScroll: true })}
                                                        />
                                                        <ActionButton icon="tag" label="تسمية" srLabel={`إعادة تسمية «${template.name}»`} onClick={() => setRenaming(template)} />
                                                        <ActionButton icon="trash" label="حذف" tone="brand" srLabel={`حذف «${template.name}»`} onClick={() => setDeleting(template)} />
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </section>
                ))}
            </div>

            {creating && <NewTemplateModal pages={pages} onClose={() => setCreating(false)} />}
            {renaming && <RenameModal key={renaming.id} template={renaming} onClose={() => setRenaming(null)} />}
            <ConfirmDialog
                show={deleting !== null}
                onConfirm={() => {
                    router.delete(`/print-templates/${deleting.id}`, { preserveScroll: true });
                    setDeleting(null);
                }}
                onCancel={() => setDeleting(null)}
                title="حذف القالب؟"
                message={`سيُحذف «${deleting?.name ?? ''}» من قوالب الشركة لكل المستخدمين.`}
                confirmLabel="نعم، احذف"
                icon="trash"
                tone="danger"
            />
        </SettingsLayout>
    );
}
