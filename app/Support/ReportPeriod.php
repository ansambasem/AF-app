<?php

namespace App\Support;

use Carbon\CarbonImmutable;

class ReportPeriod
{
    /**
     * Navigate complete calendar periods; compare elapsed dates for a partial
     * period, and complete months for a completed month. `$today` is the
     * business day the report reaches up to.
     *
     * @return array<string, mixed>
     */
    public static function describe(CarbonImmutable $from, CarbonImmutable $to, ?string $requested, CarbonImmutable $today): array
    {
        [$weekStart, $weekEnd] = ClosingPeriods::week($from);
        $monthEnd = $from->endOfMonth()->startOfDay();
        $isMonth = $from->day === 1 && $to->equalTo($monthEnd->min($today));
        $isWeek = $from->equalTo($weekStart) && $to->equalTo($weekEnd->min($today));
        $mode = match (true) {
            $requested === 'custom' => 'custom',
            $requested === 'monthly' && $isMonth => 'monthly',
            $requested === 'weekly' && $isWeek => 'weekly',
            $from->equalTo($to) => 'daily',
            $isMonth => 'monthly',
            $isWeek => 'weekly',
            default => 'custom',
        };
        $length = (int) $from->diffInDays($to) + 1;
        $previousFrom = match ($mode) {
            'monthly' => $from->subMonthNoOverflow()->startOfMonth(),
            'weekly' => $from->subWeek(),
            default => $from->subDays($length),
        };
        $previousTo = match ($mode) {
            'monthly' => $previousFrom->endOfMonth()->startOfDay(),
            'weekly' => $previousFrom->addDays(6),
            default => $from->subDay(),
        };
        $nextFrom = match ($mode) {
            'monthly' => $from->addMonthNoOverflow()->startOfMonth(),
            'weekly' => $from->addWeek(),
            default => $to->addDay(),
        };
        $nextTo = match ($mode) {
            'monthly' => $nextFrom->endOfMonth()->startOfDay(),
            'weekly' => $nextFrom->addDays(6),
            default => $nextFrom->addDays($length - 1),
        };
        $comparisonTo = $mode === 'monthly' && $to->day === $to->daysInMonth
            ? $previousTo
            : $previousFrom->addDays($length - 1)->min($previousTo);
        $range = fn (CarbonImmutable $first, CarbonImmutable $last): array => ['from' => $first->toDateString(), 'to' => $last->toDateString()];

        return [
            'mode' => $mode,
            'previous' => $range($previousFrom, $previousTo),
            'next' => $nextFrom->greaterThan($today) ? null : $range($nextFrom, $nextTo->min($today)),
            'comparison' => $range($previousFrom, $comparisonTo),
            'isOpen' => $to->equalTo($today),
        ];
    }
}
