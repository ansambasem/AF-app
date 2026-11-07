import SegmentedTabs from '@/Components/SegmentedTabs';

/** The periods a page can cover, shortest first. */
export const PERIODS = [
    { value: 'today', label: 'اليوم', caption: 'اليوم' },
    { value: '7', label: '7 أيام', caption: 'آخر 7 أيام' },
    { value: '30', label: '30 يوم', caption: 'آخر 30 يوم' },
    { value: '90', label: '90 يوم', caption: 'آخر 90 يوم' },
    { value: 'all', label: 'الكل', caption: 'منذ البداية' },
];

/** How a period is written under a figure: "آخر 30 يوم". */
export function periodCaption(period) {
    return PERIODS.find((option) => option.value === period)?.caption ?? '';
}

/**
 * The period switch at the top of a report: today, the last 7, 30 or 90
 * days, or everything. Every figure on the page follows it.
 */
export default function PeriodTabs({ period, onChange }) {
    return <SegmentedTabs options={PERIODS} value={period} onChange={onChange} label="الفترة" />;
}
