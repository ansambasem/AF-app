<?php

namespace App\Support;

use App\Models\BillingPeriod;
use Closure;
use Illuminate\Support\Collection;

/**
 * The billing periods to pick from, filed by the year, then the month,
 * they were created in (not the days they cover): a period from 30 October
 * to 5 November created on 30 October is under October. Only the years and
 * months that have periods appear, newest first, and inside a month its
 * periods newest first. Every page picking a period builds its choices here.
 */
class PeriodPicker
{
    /**
     * @param  Collection<int, BillingPeriod>  $periods  the periods the viewer may pick from
     * @param  Closure(BillingPeriod): string  $value  what picking a period sends
     * @param  string  $selected  the value picked now ('' for none)
     * @param  BillingPeriod|null  $shown  the period the page shows when nothing is picked (its open one)
     * @return array{years: list<array{value: string, months: list<array{value: string, periods: list<array{value: string, label: string}>}>}>, year: ?string, month: ?string, value: string}
     */
    public static function choices(Collection $periods, Closure $value, string $selected = '', ?BillingPeriod $shown = null): array
    {
        $periods = $periods->sortByDesc(fn (BillingPeriod $period): string => $period->created_at->format('YmdHis').str_pad((string) $period->id, 12, '0', STR_PAD_LEFT))->values();
        $picked = $selected !== '' ? $periods->first(fn (BillingPeriod $period): bool => $value($period) === $selected) : null;
        $current = $picked ?? $shown;

        $years = $periods->groupBy(fn (BillingPeriod $period): string => substr($period->createdMonth(), 0, 4))
            ->map(fn (Collection $ofYear, string $year): array => [
                'value' => $year,
                'months' => $ofYear->groupBy(fn (BillingPeriod $period): string => $period->createdMonth())
                    ->map(fn (Collection $ofMonth, string $month): array => [
                        'value' => $month,
                        'periods' => $ofMonth->map(fn (BillingPeriod $period): array => [
                            'value' => $value($period),
                            'label' => $period->label().' · '.$period->statusLabel(),
                        ])->values()->all(),
                    ])->values()->all(),
            ])->values()->all();

        return [
            'years' => $years,
            'year' => $current ? substr($current->createdMonth(), 0, 4) : null,
            'month' => $current?->createdMonth(),
            'value' => $picked ? $selected : ($shown ? $value($shown) : $selected),
        ];
    }
}
