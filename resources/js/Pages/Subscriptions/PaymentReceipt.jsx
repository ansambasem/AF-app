import { useEffect, useRef, useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import Icon from '@/Components/Icon';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { COMPANY_NAME } from '@/Layouts/GuestLayout';
import { formatMoney, formatDate } from '@/lib/format';
import { balanceOnPaper } from '@/lib/balance';

// The time printed at the foot of the receipt, in the app's Arabic with Western digits.
const PRINTED_AT_FORMAT = new Intl.DateTimeFormat('ar-EG-u-nu-latn', { dateStyle: 'long', timeStyle: 'short' });

/** One labelled line of the receipt, left out when it has nothing to say. */
function ReceiptLine({ label, children, ltr = false }) {
    if (children === null || children === undefined || children === '') {
        return null;
    }

    return (
        <div className="flex items-baseline justify-between gap-4 border-b border-dashed border-gray-200 py-2 last:border-0">
            <dt className="text-gray-500">{label}</dt>
            <dd className="text-end font-semibold text-gray-900">{ltr ? <bdi dir="ltr">{children}</bdi> : children}</dd>
        </div>
    );
}

/**
 * A payment's receipt (سند قبض), or a clearing's voucher (سند مقاصة),
 * opened in its own tab from the statement or from the form once it is
 * saved, and printed straight away on a sheet of A5. The tab is always light, whatever the app's theme, so the
 * screen shows what the paper will. A cancelled payment prints marked as
 * cancelled.
 */
export default function PaymentReceipt({ subscription, receipt, printedBy }) {
    const { appName } = usePage().props;
    const [logoReady, setLogoReady] = useState(false);
    const printed = useRef(false);
    const transferredFrom = [receipt.senderName, receipt.senderBankName].filter(Boolean).join(' · ');
    const title = receipt.isClearing ? 'سند مقاصة' : 'سند قبض';

    // The receipt is light whatever the app's theme, as the print designer's sheet is.
    useEffect(() => {
        const root = document.documentElement;
        const wasDark = root.classList.contains('dark');
        root.classList.add('print-mode');
        root.classList.remove('dark');

        return () => {
            root.classList.remove('print-mode');

            if (wasDark) {
                root.classList.add('dark');
            }
        };
    }, []);

    // Print once the logo is drawn, so it isn't missing from the paper.
    useEffect(() => {
        if (!logoReady || printed.current) {
            return;
        }

        printed.current = true;
        const timer = setTimeout(() => window.print(), 150);

        return () => clearTimeout(timer);
    }, [logoReady]);

    return (
        <div className="min-h-screen bg-gray-100 px-4 py-6 text-gray-900 sm:py-10 print:min-h-0 print:bg-white print:p-0" dir="rtl">
            <Head title={`${title} ${receipt.voucherNumber ?? ''} — ${subscription.fullName}`} />
            <style>{'@page { size: A5 portrait; margin: 10mm; }'}</style>

            <div className="pd-screen-only mx-auto mb-4 flex max-w-[148mm] flex-wrap items-center justify-between gap-3">
                <p className="text-sm text-gray-600">{title} جاهز للطباعة على ورق A5.</p>
                <div className="flex gap-2">
                    <SecondaryButton type="button" onClick={() => window.close()}>
                        إغلاق
                    </SecondaryButton>
                    <PrimaryButton type="button" className="tone-print tone-solid" onClick={() => window.print()}>
                        <Icon name="printer" className="h-4 w-4" />
                        طباعة
                    </PrimaryButton>
                </div>
            </div>

            <article className="pd-paper relative mx-auto w-full max-w-[148mm] overflow-hidden rounded-xl p-[10mm] text-[13px] print:max-w-none print:rounded-none">
                {receipt.cancellation && (
                    <div
                        className="pointer-events-none absolute inset-x-0 top-1/3 flex -rotate-12 justify-center"
                        aria-hidden="true"
                    >
                        <span className="rounded-xl border-4 border-brand-600/60 px-6 py-1 text-5xl font-bold text-brand-600/60">ملغى</span>
                    </div>
                )}

                <header className="flex items-start justify-between gap-4 border-b-2 border-brand-600 pb-3">
                    <div className="flex items-center gap-3">
                        <img
                            src="/images/logo-af.webp"
                            alt={appName}
                            className="h-12 w-auto"
                            onLoad={() => setLogoReady(true)}
                            onError={() => setLogoReady(true)}
                        />
                        <div>
                            <div className="text-[15px] font-bold leading-snug">{COMPANY_NAME}</div>
                            <div className="text-gray-600">{subscription.branchName}</div>
                        </div>
                    </div>
                    <div className="shrink-0 text-end">
                        <h1 className="text-2xl font-bold text-brand-600">{title}</h1>
                        <div className="mt-1 font-display text-lg font-bold tabular-nums">
                            رقم <bdi dir="ltr">{receipt.voucherNumber ?? '—'}</bdi>
                        </div>
                        <div className="text-gray-600">
                            <bdi dir="ltr">{formatDate(receipt.date)}</bdi> · <bdi dir="ltr">{receipt.time}</bdi>
                        </div>
                    </div>
                </header>

                {receipt.cancellation && (
                    <p className="mt-3 rounded-lg border border-brand-600/30 bg-brand-50 px-3 py-2 text-brand-700">
                        {receipt.isClearing ? 'هذه المقاصة ملغاة' : 'هذه الدفعة ملغاة'}
                        {receipt.cancellation.reasonLabel && <>: {receipt.cancellation.reasonLabel}</>}
                        {receipt.cancellation.byName && <> · {receipt.cancellation.byName}</>}
                        {receipt.cancellation.date && (
                            <>
                                {' '}
                                · <bdi dir="ltr">{formatDate(receipt.cancellation.date)}</bdi>
                            </>
                        )}
                    </p>
                )}

                <p className="mt-4 text-[14px]">
                    {receipt.isClearing ? 'قاصصنا السيد/ة' : 'استلمنا من السيد/ة'} <b className="text-[15px]">{subscription.fullName}</b>
                </p>
                <div className="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-gray-600">
                    <span>
                        حساب <bdi dir="ltr">{subscription.accountNumber}</bdi>
                    </span>
                    {subscription.subscriberNumber && (
                        <span>
                            مشترك <bdi dir="ltr">{subscription.subscriberNumber}</bdi>
                        </span>
                    )}
                    {subscription.meterBoxNumber && <span>طبلون {subscription.meterBoxNumber}</span>}
                    {subscription.phone && (
                        <span>
                            هاتف <bdi dir="ltr">{subscription.phone}</bdi>
                        </span>
                    )}
                </div>

                <div className="mt-4 rounded-xl border-2 border-gray-900 px-4 py-3 text-center">
                    <div className="text-gray-600">{receipt.isClearing ? 'بمبلغ وقدره' : 'مبلغ وقدره'}</div>
                    <div className="mt-1 font-display text-3xl font-bold tabular-nums">
                        {formatMoney(receipt.amount)} <span className="text-lg font-semibold">{receipt.currencyLabel}</span>
                    </div>
                    {!receipt.isShekel && (
                        <div className="mt-1 text-gray-600">
                            يعادل {formatMoney(receipt.inShekels)} شيكل · سعر الصرف <bdi dir="ltr">{receipt.exchangeRate}</bdi>
                        </div>
                    )}
                </div>

                <dl className="mt-4">
                    <ReceiptLine label="طريقة الدفع">{receipt.paymentMethodLabel}</ReceiptLine>
                    <ReceiptLine label="البنك المستلم">{receipt.bankName}</ReceiptLine>
                    <ReceiptLine label="المحوّل منه">{transferredFrom}</ReceiptLine>
                    <ReceiptLine label="الرقم المرجعي" ltr>
                        {receipt.referenceNumber}
                    </ReceiptLine>
                    <ReceiptLine label="دفعة مقسّمة">
                        {receipt.splitPayment && `جزء من تحويل بقيمة ${formatMoney(receipt.splitPayment.total)} شيكل وُزّع على ${receipt.splitPayment.partsCount} مشتركين`}
                    </ReceiptLine>
                    <ReceiptLine label="رقم الصندوق" ltr>
                        {receipt.cashBox}
                    </ReceiptLine>
                    {receipt.manualVoucherNumber && receipt.systemVoucherNumber && (
                        <ReceiptLine label="رقم السند في النظام" ltr>
                            {receipt.systemVoucherNumber}
                        </ReceiptLine>
                    )}
                    <ReceiptLine label={receipt.isClearing ? 'مقابل خدمة' : 'ملاحظات'}>{receipt.notes}</ReceiptLine>
                    <ReceiptLine label="تاريخ وصول الحوالة إلى البنك" ltr>{formatDate(receipt.bankReceivedOn)}</ReceiptLine>
                    <ReceiptLine label="تاريخ الدفع الفعلي — تسجيل متأخر" ltr>{formatDate(receipt.paidOn)}</ReceiptLine>
                    <ReceiptLine label="سبب تأخر التسجيل">{receipt.lateReason}</ReceiptLine>
                    {receipt.balanceAfter != null && <ReceiptLine label={receipt.isClearing ? 'الرصيد بعد المقاصة' : 'الرصيد بعد الدفعة'}>{balanceOnPaper(receipt.balanceAfter)}</ReceiptLine>}
                </dl>

                <div className="pd-signatures mt-10 grid grid-cols-2 gap-8 text-center">
                    <div>
                        <div className="mb-1 h-10 border-b border-gray-500" />
                        <div className="font-semibold">المستلم{receipt.recordedByName && `: ${receipt.recordedByName}`}</div>
                    </div>
                    <div>
                        <div className="mb-1 h-10 border-b border-gray-500" />
                        <div className="font-semibold">توقيع المشترك</div>
                    </div>
                </div>

                <footer className="mt-6 border-t border-dashed border-gray-300 pt-2 text-[11px] text-gray-500">
                    طُبع بواسطة {printedBy} · {PRINTED_AT_FORMAT.format(new Date())}
                </footer>
            </article>
        </div>
    );
}
