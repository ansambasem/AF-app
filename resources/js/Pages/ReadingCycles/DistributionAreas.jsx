import { useState } from 'react';
import Icon from '@/Components/Icon';
import { formatNumber } from '@/lib/format';
import { readingSearchMatches } from '@/lib/readingBoard';

export default function DistributionAreas({ areas, chosen, onChange, disabled, filter, counts, onFilterChange }) {
    const [search, setSearch] = useState('');
    const matching = areas.filter((area) => readingSearchMatches(area.name, search));
    const activeStatus = filter === 'available' ? 'available' : filter ? 'assigned' : '';

    function toggle(id) {
        onChange(chosen.includes(id) ? chosen.filter((value) => value !== id) : [...chosen, id]);
    }

    return (
        <aside className="overflow-hidden rounded-card border border-gray-100 bg-surface shadow-card" aria-label="اختيار مناطق التوزيع">
            <div className="flex flex-col gap-3 border-b border-gray-100 p-4">
                <div className="flex items-center justify-between gap-2">
                    <h2 className="flex items-center gap-2 font-bold text-gray-900"><Icon name="pin" className="h-4 w-4 text-brand-600" />المناطق</h2>
                    {chosen.length > 0 && <button type="button" disabled={disabled} onClick={() => onChange([])} className="text-xs font-semibold text-brand-600 hover:underline">عرض الكل</button>}
                </div>
                <p className="text-xs leading-6 text-gray-500">اختر منطقة أو أكثر، ثم حدّد الطبلونات التي تريد توزيعها.</p>
                <div role="group" aria-label="تصفية المناطق حسب حالة التوزيع" className="grid grid-cols-3 gap-1 rounded-control border border-gray-100 bg-gray-50 p-1">
                    {[
                        { value: '', label: 'الكل', count: counts.all },
                        { value: 'assigned', label: 'موزع', count: counts.assigned },
                        { value: 'available', label: 'غير موزع', count: counts.available },
                    ].map((status) => <button key={status.value} type="button" disabled={disabled} aria-pressed={activeStatus === status.value} onClick={() => onFilterChange(status.value)} className={`flex flex-col items-center gap-1 rounded-lg px-1 py-2 text-xs font-semibold transition ${activeStatus === status.value ? 'bg-surface text-brand-600 shadow-sm' : 'text-gray-500 hover:text-gray-900'}`}>
                        <span>{status.label}</span><span className="font-display text-sm">{formatNumber(status.count)}</span>
                    </button>)}
                </div>
                <label className="sr-only" htmlFor="distribution-area-search">البحث عن منطقة</label>
                <input id="distribution-area-search" type="search" value={search} disabled={disabled} onChange={(event) => setSearch(event.target.value)} placeholder="ابحث عن منطقة..." className="block w-full text-sm" />
                <div className="flex items-center justify-between gap-2 text-xs">
                    <span className="text-gray-500">{formatNumber(matching.length)} منطقة{chosen.length > 0 && ` · اختيرت ${formatNumber(chosen.length)}`}</span>
                    {matching.length > 0 && <button type="button" disabled={disabled} onClick={() => onChange([...new Set([...chosen, ...matching.map((area) => area.id)])])} className="font-semibold text-brand-600 hover:underline">اختيار كل نتائج المناطق</button>}
                </div>
            </div>
            <div className="max-h-60 overflow-y-auto p-2 lg:max-h-[30rem]">
                {matching.length === 0 && <p className="px-3 py-6 text-center text-sm text-gray-500">لا توجد مناطق مطابقة للبحث وحالة التوزيع. غيّر البحث أو اختر «الكل».</p>}
                {matching.map((area) => <label key={area.id} className={`flex cursor-pointer items-start gap-3 rounded-control px-3 py-3 hover:bg-gray-50 ${chosen.includes(area.id) ? 'bg-brand-500/5' : ''}`}>
                    <input type="checkbox" checked={chosen.includes(area.id)} disabled={disabled} onChange={() => toggle(area.id)} aria-label={`عرض منطقة ${area.name}`} className="mt-1 rounded border-gray-300 text-brand-600" />
                    <span className="flex min-w-0 flex-1 flex-col gap-1">
                        <span className="block text-sm font-semibold text-gray-900">{area.name}</span>
                        <span className="text-xs text-gray-500">{formatNumber(area.matchingBoxes)} طبلون{filter ? ' مطابق' : ''} · {formatNumber(area.matchingSubscribers)} مشترك</span>
                        <span className="flex flex-wrap gap-x-2 gap-y-1 text-xs" aria-label={`إجمالي المنطقة: ${area.assigned} موزع، ${area.unassigned} غير موزع`}>
                            <span className="text-emerald-700 dark:text-emerald-400">{formatNumber(area.assigned)} موزع</span>
                            <span className="text-amber-700 dark:text-amber-400">{formatNumber(area.unassigned)} متبقٍ</span>
                        </span>
                    </span>
                </label>)}
            </div>
        </aside>
    );
}
