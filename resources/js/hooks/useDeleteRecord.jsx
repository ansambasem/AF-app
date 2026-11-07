import { useState } from 'react';
import { router } from '@inertiajs/react';
import ConfirmDialog from '@/Components/ConfirmDialog';

/**
 * Deleting a record from a list, after a confirmation. `requestDelete(url,
 * name, noun?)` asks "delete «name»?"; yes sends DELETE to `url`. The server
 * deletes only what nothing uses any more, and otherwise says what still
 * does. Render `deleteDialog` once on the page. `noun` names the kind of
 * record in the question ("المشترك", "الفرع"…), unless a request names
 * its own.
 */
export function useDeleteRecord(noun) {
    const [pending, setPending] = useState(null);

    function confirm() {
        router.delete(pending.url, { preserveScroll: true });
        setPending(null);
    }

    const deleteDialog = (
        <ConfirmDialog
            show={Boolean(pending)}
            onConfirm={confirm}
            onCancel={() => setPending(null)}
            title={`حذف ${pending?.noun ?? noun}؟`}
            message={pending && `سيُحذف «${pending.name}» نهائيًا ولا يمكن التراجع عن ذلك.`}
            confirmLabel="نعم، احذف"
            cancelLabel="إلغاء"
            icon="trash"
            tone="danger"
        />
    );

    return { requestDelete: (url, name, ownNoun = null) => setPending({ url, name, noun: ownNoun }), deleteDialog };
}
