import ExcelButton, { tableExportButtonClass } from '@/Components/ExcelButton';
import Icon from '@/Components/Icon';
import { printUrl } from '@/lib/print';

/** Every table keeps its export actions together, in the same RTL order and button size. */
export default function TableExportActions({ title, base, showExcel = true, showPrint = true, excelProps = {}, onExcel = null, onPrint = null, printHref = null, disabled = false, onDark = false }) {
    if (!showExcel && !showPrint) {
        return null;
    }

    const print = onPrint ?? (() => window.open(printUrl(title ?? document.title, base), '_blank', 'noopener'));
    const buttonClass = `${tableExportButtonClass}${onDark ? ' tone-on-dark' : ''}`;

    return (
        <div data-table-exports role="group" aria-label="تصدير وطباعة الجدول" dir="rtl" className="ms-auto flex shrink-0 items-center gap-2 print:hidden">
            {showExcel && (onExcel ? (
                <button data-excel-button type="button" disabled={disabled} onClick={onExcel} className={`tone-excel ${buttonClass}`} title="تخصيص وتنزيل ملف Excel منسّق">
                    <Icon name="excel" className="h-4 w-4" />Excel
                </button>
            ) : <ExcelButton title={title} base={base} disabled={disabled} {...excelProps} className={buttonClass} />)}
            {showPrint && (printHref ? (
                <a href={printHref} target="_blank" rel="noopener noreferrer" className={`tone-print ${buttonClass}`} title="تخصيص وطباعة الجدول في نافذة جديدة">
                    <Icon name="printer" className="h-4 w-4" />طباعة
                </a>
            ) : (
                <button type="button" disabled={disabled} onClick={print} className={`tone-print ${buttonClass}`} title="تخصيص وطباعة الجدول في نافذة جديدة">
                    <Icon name="printer" className="h-4 w-4" />طباعة
                </button>
            ))}
        </div>
    );
}
