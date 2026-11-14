import { useState } from 'react';
import ConfirmDialog from '@/Components/ConfirmDialog';
import Icon from '@/Components/Icon';
import Modal from '@/Components/Modal';
import SplitPaymentForm from './SplitPaymentForm';

/**
 * The shared payment, in a window of its own: one bank transfer divided
 * between any subscribers. Closing it with something entered asks first, so a
 * long distribution is not lost by a stray click.
 */
export default function SplitPaymentModal({ onClose, transferBanks, senderBanks, initialSubscription = null }) {
    const [dirty, setDirty] = useState(false);
    const [discarding, setDiscarding] = useState(false);

    function requestClose() {
        if (dirty) {
            setDiscarding(true);
        } else {
            onClose();
        }
    }

    return (
        <>
            <Modal show onClose={requestClose} maxWidth="5xl">
                <div role="dialog" aria-modal="true" aria-label="دفعة مقسّمة على عدة مشتركين" className="flex max-h-[90vh] flex-col">
                    <div className="flex items-center justify-between border-b border-gray-100 px-7 py-5">
                        <div className="flex items-center gap-3">
                            <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-gradient text-white shadow-glow">
                                <Icon name="layers" />
                            </span>
                            <div>
                                <h3 className="text-lg font-bold text-gray-900">دفعة مقسّمة على عدة مشتركين</h3>
                                <p className="text-[13px] text-gray-500">تحويل بنكي واحد يُوزَّع على أي مشتركين تختارهم</p>
                            </div>
                        </div>
                        <button type="button" onClick={requestClose} aria-label="إغلاق" className="rounded-xl p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-900">
                            <Icon name="close" />
                        </button>
                    </div>

                    <div className="flex-1 overflow-y-auto px-7 py-6">
                        <SplitPaymentForm bare transferBanks={transferBanks} senderBanks={senderBanks} onDirtyChange={setDirty} initialSubscription={initialSubscription} />
                    </div>
                </div>
            </Modal>

            <ConfirmDialog
                show={discarding}
                onConfirm={onClose}
                onCancel={() => setDiscarding(false)}
                title="تجاهل التغييرات؟"
                message="أدخلت بيانات لم تُحفظ بعد. إذا أغلقت النافذة الآن فستفقدها."
                confirmLabel="تجاهل التغييرات"
                cancelLabel="البقاء ومتابعة التعديل"
                icon="alert"
            />
        </>
    );
}
