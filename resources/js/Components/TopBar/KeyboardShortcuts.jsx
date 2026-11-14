import { useEffect, useRef } from 'react';
import Icon from '@/Components/Icon';

/** The keys that work from any page, in a small window. */
export default function KeyboardShortcuts({ open, onClose, canRecordPayments, canSendMessages, searchesSubscribers }) {
    const closeRef = useRef(null);

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        const returnTo = document.activeElement;
        document.documentElement.classList.add('tn-locked');
        closeRef.current?.focus();

        function onKeyDown(event) {
            if (event.key === 'Escape' || event.key === 'Tab') {
                event.preventDefault();

                if (event.key === 'Escape') {
                    onClose();
                }
            }
        }

        document.addEventListener('keydown', onKeyDown);

        return () => {
            document.removeEventListener('keydown', onKeyDown);
            document.documentElement.classList.remove('tn-locked');

            (returnTo?.isConnected && returnTo !== document.body ? returnTo : document.getElementById('main-content'))?.focus({ preventScroll: true });
        };
    }, [open, onClose]);

    if (!open) {
        return null;
    }

    const keys = [
        ['Ctrl K أو /', searchesSubscribers ? 'بحث في الصفحات والمشتركين والأوامر' : 'بحث في الصفحات والأوامر'],
        ['Alt + 1…6', 'فتح قسم من الشريط'],
        ['← →', 'التنقل بين الأقسام'],
        ['↓', 'فتح قائمة القسم'],
        ...(canRecordPayments ? [['N', 'تسجيل دفعة من أي صفحة']] : []),
        ...(canSendMessages ? [['M', 'رسالة سريعة لمشترك أو أكثر']] : []),
        ['Esc', 'إغلاق أي قائمة أو نافذة'],
    ];

    return (
        <div className="tn-scrim flex items-start justify-center px-4 pt-[12vh]" dir="rtl" onClick={(event) => event.target === event.currentTarget && onClose()}>
            <div className="tn-palette tn-lift w-full max-w-[440px] rounded-[24px] bg-surface p-5 text-gray-900" role="dialog" aria-modal="true" data-top-bar-layer aria-label="اختصارات لوحة المفاتيح">
                <div className="mb-3 flex items-center gap-3">
                    <span className="grid h-10 w-10 place-items-center rounded-xl bg-graphite-gradient text-white"><Icon name="keyboard" className="h-5 w-5" /></span>
                    <b className="flex-1 text-[17px]">اختصارات لوحة المفاتيح</b>
                    <button ref={closeRef} type="button" className="tn-tool" onClick={onClose} aria-label="إغلاق"><Icon name="close" /></button>
                </div>
                {keys.map(([key, label]) => (
                    <div key={key} className="flex items-center gap-3 border-b border-gray-200/60 py-2.5 last:border-0">
                        <span className="tn-kbd min-w-[92px] justify-center" dir="ltr">{key}</span>
                        <span className="text-[14.5px]">{label}</span>
                    </div>
                ))}
            </div>
        </div>
    );
}
