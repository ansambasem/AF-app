import { formatNumber } from '@/lib/format';

export default function DistributionAreaBreakdown({ areas }) {
    return <div className="divide-y divide-gray-100">{areas.map((area) => (
        <div key={area.id} className="py-3">
            <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                <span className="font-semibold text-gray-900">{area.name} {area.complete
                    ? <span className="text-xs text-emerald-700 dark:text-emerald-400">كاملة</span>
                    : <span className="text-xs font-normal text-gray-500">· {formatNumber(area.boxes)} من {formatNumber(area.total)} طبلون</span>}</span>
                <span className="text-xs text-gray-500">{formatNumber(area.subscribers)} مشترك</span>
            </div>
            <details className="mt-2 text-xs text-gray-500">
                <summary className="cursor-pointer font-semibold text-brand-600">عرض الطبلونات ({formatNumber(area.boxes)})</summary>
                <ul className="mt-2 flex max-h-48 flex-wrap gap-2 overflow-y-auto rounded-control bg-gray-50 p-2" aria-label={`طبلونات ${area.name}`}>
                    {area.items.map((box) => <li key={box.id} className="rounded-md border border-gray-100 bg-surface px-2 py-1.5 text-gray-700">{box.name || 'طبلون'} <bdi dir="ltr" className="whitespace-nowrap font-display font-bold">({box.number})</bdi></li>)}
                </ul>
            </details>
        </div>
    ))}</div>;
}
