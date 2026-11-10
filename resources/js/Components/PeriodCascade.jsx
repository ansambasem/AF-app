import SearchableSelect from '@/Components/SearchableSelect';
import { formatMonthName } from '@/lib/format';
import { monthsOf, newestOfMonth, newestOfYear, periodsOf } from '@/lib/periodChoices';

/**
 * The year, the month and the period, side by side, from the server's
 * period choices: changing the year or the month opens its newest period,
 * and `onChange` is given the period's value. `extraPeriods` lead the
 * period list (e.g. "the current open period").
 */
export default function PeriodCascade({ choices, onChange, extraPeriods = [], className = '' }) {
    const months = monthsOf(choices, choices.year);
    const periods = [...extraPeriods, ...periodsOf(choices, choices.month)];
    const label = 'grid gap-1.5 text-sm font-semibold text-gray-700';

    function pick(value) {
        if (value !== null && value !== choices.value) {
            onChange(value);
        }
    }

    return (
        <div className={`flex flex-wrap items-end gap-3 ${className}`}>
            <label className={`${label} w-28`}>
                السنة
                <SearchableSelect clearable={false} searchable={false} placeholder="السنة" value={choices.year ?? ''}
                    options={choices.years.map((year) => ({ value: year.value, label: year.value }))}
                    onChange={(year) => pick(newestOfYear(choices, year))} />
            </label>
            <label className={`${label} w-36`}>
                الشهر
                <SearchableSelect clearable={false} searchable={false} placeholder="الشهر" value={choices.month ?? ''}
                    options={months.map((month) => ({ value: month.value, label: formatMonthName(`${month.value}-01`) }))}
                    onChange={(month) => pick(newestOfMonth(choices, month))} />
            </label>
            <label className={`${label} w-full sm:w-72`}>
                الفترة
                <SearchableSelect clearable={false} searchable={periods.length > 8} placeholder="اختر الفترة" value={choices.month ? choices.value : ''}
                    options={periods} onChange={pick} />
            </label>
        </div>
    );
}
