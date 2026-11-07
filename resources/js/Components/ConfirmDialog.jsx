import { useId } from 'react';
import DialogFrame from '@/Components/DialogFrame';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import DangerButton from '@/Components/DangerButton';

/**
 * A yes/no question in the app's own modal style, used instead of the
 * browser's confirm(). Closing it (Escape, the backdrop, or the cancel
 * button) counts as "no".
 *
 * `tone="danger"` is for throwing work away: the confirm button turns
 * red and the safe choice (cancel) gets the focus. Otherwise confirm is
 * focused, so Enter answers straight away.
 */
export default function ConfirmDialog({
    show,
    onConfirm,
    onCancel,
    title,
    message,
    confirmLabel = 'تأكيد',
    cancelLabel = 'إلغاء',
    icon = 'check',
    tone = 'primary',
    children,
}) {
    const id = useId();
    const isDanger = tone === 'danger';
    const ConfirmButton = isDanger ? DangerButton : PrimaryButton;

    return (
        <DialogFrame show={show} onClose={onCancel} title={title} icon={icon} tone={tone} role="alertdialog" maxWidth="xl" footer={<>
            <SecondaryButton onClick={onCancel} autoFocus={isDanger}>
                {cancelLabel}
            </SecondaryButton>
            <ConfirmButton type="button" onClick={onConfirm} autoFocus={!isDanger}>
                {confirmLabel}
            </ConfirmButton>
        </>}>
            <div id={`${id}-message`} className="whitespace-pre-line">{message}</div>
            {children}
        </DialogFrame>
    );
}

/**
 * The "are you sure?" shown before a create/edit form is sent. `isEdit`
 * picks the wording for changing an existing record over adding a new one;
 * `message` replaces the explanation when a save needs a specific warning.
 */
export function SaveConfirmDialog({ show, isEdit, onConfirm, onCancel, message = null, action = null }) {
    if (action) {
        return (
            <ConfirmDialog
                show={show}
                onConfirm={onConfirm}
                onCancel={onCancel}
                title={action.title}
                message={message}
                confirmLabel={action.confirmLabel}
                cancelLabel="مراجعة البيانات"
                icon={action.icon}
                tone={action.tone}
            />
        );
    }

    return (
        <ConfirmDialog
            show={show}
            onConfirm={onConfirm}
            onCancel={onCancel}
            title={isEdit ? 'حفظ التعديلات؟' : 'إضافة السجل؟'}
            message={
                message ??
                (isEdit
                    ? 'سيتم حفظ التعديلات التي أجريتها على هذا السجل. هل تريد المتابعة؟'
                    : 'سيتم إضافة سجل جديد بالبيانات التي أدخلتها. هل تريد المتابعة؟')
            }
            confirmLabel={isEdit ? 'نعم، احفظ التعديلات' : 'نعم، أضف'}
            cancelLabel="مراجعة البيانات"
        />
    );
}
