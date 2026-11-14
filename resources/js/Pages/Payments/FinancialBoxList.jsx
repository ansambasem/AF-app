import Icon from '@/Components/Icon';
import { useRowClick } from '@/hooks/useRowClick';
import { BoxStage } from './CollectorCashBox';
import { FinancialBoxAmount, FinancialBoxButton, FinancialBoxDates, FinancialBoxEntries, FinancialBoxStatus, FinancialBoxTimes, FinancialBoxType, financialAmountsLine } from './FinancialBoxComponents';
import './financial-boxes.css';

/**
 * The same aligned box rows for the branch and the collector's history: the
 * box's number and week, its type, then whose it is, its entries, total,
 * dates and state. The branch sees when each box was opened and closed,
 * and who confirmed its closure.
 */
export default function FinancialBoxList({ boxes, onOpen, periodFor, sortState, onSort, collector = false, renderActions }) {
    const rowClick = useRowClick();

    function heading(column, label) {
        if (!onSort) {
            return label;
        }

        const active = sortState?.sort === column;

        return <button type="button" onClick={() => onSort(column)} className="inline-flex items-center gap-1 rounded-md hover:text-gray-900" aria-label={`${label}${active ? sortState.direction === 'asc' ? '، ترتيب تصاعدي' : '، ترتيب تنازلي' : '، ترتيب'}`}>
            {label}<Icon name="chevron-down" className={`h-3.5 w-3.5 ${active ? 'text-brand-500' : 'opacity-40'} ${active && sortState.direction === 'asc' ? 'rotate-180' : ''}`} />
        </button>;
    }

    if (!boxes.length) {
        return <p className="px-4 py-14 text-center text-sm text-gray-500">لا توجد صناديق بهذه الفلاتر.</p>;
    }

    return <div className="financial-box-list">
        <table className="financial-box-list-table"><caption className="sr-only">الصناديق المالية</caption><thead><tr className="financial-box-list-heading">
            <th><span className="inline-flex items-center gap-2">{heading('number', 'رقم الصندوق')}</span></th><th>النوع</th><th>{collector ? 'أغلقه / المستلم' : 'الموظف'}</th><th>الحركات</th><th>{heading('total', 'الإجمالي')}</th><th>{collector ? heading('dates', 'فُتح ← أُغلق') : heading('dates', 'الأوقات')}</th><th>{heading('status', 'الحالة')}</th><th data-actions=""><span className="sr-only">الإجراءات</span></th>
        </tr></thead>
        <tbody className="financial-box-list-rows">
            {boxes.map((box) => <tr key={box.id} className="financial-box-list-row" {...rowClick(() => onOpen(box))}>
                <td className="financial-box-list-number flex items-center gap-3"><button type="button" className="min-w-0 text-start" onClick={() => onOpen(box)} aria-label={`عرض صندوق ${box.number}`}><b dir="ltr" className="block font-display text-lg text-gray-900">{box.number}</b>{periodFor && periodFor(box) !== '—' && <span className="block text-[13px] text-gray-600">{periodFor(box)}</span>}</button></td>
                <td className="financial-box-list-type"><FinancialBoxType box={box} /></td>
                <td className="financial-box-list-person">
                    {collector ? <div><span className="block text-[15px] font-semibold text-gray-900">{box.closedBy ?? '—'}</span>{box.receivedAt && <small className="block text-[13px] text-gray-600">المستلم: {financialAmountsLine(box.receivedAmounts ?? {})}</small>}</div> : <span className="min-w-0 break-words text-[15px] font-semibold text-gray-900">{box.user?.name ?? '—'}{box.isOwn && <span className="text-sm font-normal text-gray-600"> (أنت)</span>}{box.branchName && <small className="block text-xs font-normal text-gray-500">{box.branchName}</small>}</span>}
                </td>
                <td className="financial-box-list-entries"><FinancialBoxEntries box={box} /></td>
                <td className="financial-box-list-amount"><FinancialBoxAmount box={box} /></td>
                <td className="financial-box-list-dates">{collector ? <FinancialBoxDates box={box} /> : <FinancialBoxTimes box={box} />}</td>
                <td className="financial-box-list-status">{collector ? <BoxStage box={box} /> : <FinancialBoxStatus box={box} />}</td>
                <td className="financial-box-list-actions">{renderActions ? renderActions(box) : <FinancialBoxButton box={box} onOpen={onOpen} />}</td>
            </tr>)}
        </tbody></table>
    </div>;
}
