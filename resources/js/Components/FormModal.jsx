import { useId, useState } from 'react';
import Modal from '@/Components/Modal';
import ConfirmDialog, { SaveConfirmDialog } from '@/Components/ConfirmDialog';
import { DialogHeader } from '@/Components/DialogFrame';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { clearErrorOnInput, submitOnCtrlEnter, validateFormFields } from '@/lib/formValidation';

/**
 * The create/edit modal every resource uses: a header with an icon and
 * title, the form fields as children, and Cancel/Save buttons.
 *
 * `form` comes from useResourceForm(). Saving asks for confirmation first
 * (unless `confirmBeforeSave` is false; `saveConfirmMessage` replaces the
 * question's explanation), and closing with unsaved input
 * asks whether to discard it. Closing clears the form, and a successful
 * save closes the modal. `visitOptions` are passed on to the save request.
 * Fields are checked in the page first, with their errors shown under
 * them rather than in the browser's pop-ups. Ctrl + Enter (⌘ + Enter on a
 * Mac) saves from any field (listed with the other keyboard shortcuts). `action` renames what saving does — its
 * button, and the question before it: `{ submitLabel, title,
 * confirmLabel, icon, tone }`.
 */
export default function FormModal({
    show,
    onClose,
    form,
    title,
    icon,
    headerTone = 'brand',
    maxWidth = 'lg',
    visitOptions = {},
    bodyClassName = '',
    confirmBeforeSave = true,
    saveConfirmMessage = null,
    action = null,
    children,
}) {
    const id = useId();
    // The question shown over the form: 'save' before sending it, 'discard' before throwing away unsaved input.
    const [pendingConfirmation, setPendingConfirmation] = useState(null);

    function close() {
        setPendingConfirmation(null);
        form.resetAndClearErrors();
        onClose();
    }

    function requestClose() {
        if (form.isDirty) {
            setPendingConfirmation('discard');
        } else {
            close();
        }
    }

    function save() {
        setPendingConfirmation(null);
        form.save({ preserveScroll: true, onSuccess: close, ...visitOptions });
    }

    function submit(e) {
        e.preventDefault();

        if (!validateFormFields(e.currentTarget, form)) {
            return;
        }

        if (confirmBeforeSave) {
            setPendingConfirmation('save');
        } else {
            save();
        }
    }

    return (
        <>
            <Modal show={show} onClose={requestClose} maxWidth={maxWidth}>
                <form
                    aria-labelledby={`${id}-title`}
                    noValidate
                    onSubmit={submit}
                    onInput={(e) => clearErrorOnInput(e, form)}
                    onKeyDown={submitOnCtrlEnter}
                    className="flex max-h-[90vh] flex-col"
                >
                    <DialogHeader titleId={`${id}-title`} title={title} icon={icon} tone={headerTone} onClose={requestClose} />

                    <div className={`min-h-0 flex-1 overflow-y-auto px-5 py-6 sm:px-7 ${bodyClassName}`}>{children}</div>

                    <div className="flex shrink-0 flex-wrap items-center justify-end gap-3 border-t border-gray-100 bg-gray-50 px-5 py-4 sm:px-7">
                        <SecondaryButton onClick={requestClose}>إلغاء</SecondaryButton>
                        <PrimaryButton disabled={form.processing}>{form.processing ? 'جارٍ الحفظ...' : (action?.submitLabel ?? 'حفظ')}</PrimaryButton>
                    </div>
                </form>
            </Modal>

            <SaveConfirmDialog
                show={show && pendingConfirmation === 'save'}
                isEdit={form.isEdit}
                message={saveConfirmMessage}
                action={action}
                onConfirm={save}
                onCancel={() => setPendingConfirmation(null)}
            />

            <ConfirmDialog
                show={show && pendingConfirmation === 'discard'}
                onConfirm={close}
                onCancel={() => setPendingConfirmation(null)}
                title="تجاهل التغييرات؟"
                message="أدخلت بيانات لم تُحفظ بعد. إذا أغلقت النافذة الآن فستفقدها."
                confirmLabel="تجاهل التغييرات"
                cancelLabel="البقاء ومتابعة التعديل"
                icon="alert"
                tone="danger"
            />
        </>
    );
}
