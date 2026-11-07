<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Calendar days as the business counts them — in its own time zone
 * (config app.business_timezone), while timestamps are stored in UTC —
 * and one figure per day for the charts, with the days that have no rows
 * filled in as zero.
 */
class DailySeries
{
    /**
     * The first moment (in UTC) of the day `$daysAgo` days before today,
     * business time: startOfDay(0) is midnight this morning.
     */
    public static function startOfDay(int $daysAgo = 0): CarbonImmutable
    {
        return self::today()->subDays($daysAgo)->utc();
    }

    /**
     * Today in the business's time zone, at midnight.
     */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.business_timezone'))->startOfDay();
    }

    /**
     * The business-time date (Y-m-d) of a stored UTC timestamp.
     */
    public static function localDate(CarbonInterface|string $timestamp): string
    {
        return CarbonImmutable::parse($timestamp, 'UTC')->setTimezone(config('app.business_timezone'))->toDateString();
    }

    /**
     * The business-time wall clock (H:i) of a stored UTC timestamp.
     */
    public static function localTime(CarbonInterface|string $timestamp): string
    {
        return CarbonImmutable::parse($timestamp, 'UTC')->setTimezone(config('app.business_timezone'))->format('H:i');
    }

    /**
     * The last `$days` dates (Y-m-d), oldest first, ending today.
     *
     * @return array<int, string>
     */
    public static function lastDays(int $days): array
    {
        $today = self::today();

        return array_map(fn (int $daysAgo): string => $today->subDays($daysAgo)->toDateString(), range($days - 1, 0));
    }

    /**
     * One entry per day for the last `$days` days, oldest first: the sum of
     * `$valueColumn` (absolute, so charges and payments both count up) and
     * how many rows fell on that day. `$dateColumn` is the rows' UTC
     * timestamp; the query must not be ordered.
     *
     * @return array<int, array{date: string, value: float, count: int}>
     */
    public static function sums(Builder $query, string $dateColumn, ?string $valueColumn, int $days): array
    {
        $rows = self::rowsSince($query, $dateColumn, $valueColumn, $days)
            ->groupBy(fn (object $row): string => self::localDate($row->moment));

        return array_map(fn (string $day): array => [
            'date' => $day,
            'value' => round((float) ($rows[$day] ?? collect())->sum(fn (object $row): float => abs((float) ($row->value ?? 0))), 2),
            'count' => ($rows[$day] ?? collect())->count(),
        ], self::lastDays($days));
    }

    /**
     * How many rows fell on each of the last `$days` days, oldest first.
     *
     * @return array<int, array{date: string, value: int}>
     */
    public static function counts(Builder $query, string $dateColumn, int $days): array
    {
        return array_map(
            fn (array $day): array => ['date' => $day['date'], 'value' => $day['count']],
            self::sums($query, $dateColumn, null, $days),
        );
    }

    /**
     * @return Collection<int, object{moment: string, value: string|null}>
     */
    private static function rowsSince(Builder $query, string $dateColumn, ?string $valueColumn, int $days): Collection
    {
        return (clone $query)
            ->where($dateColumn, '>=', self::startOfDay($days - 1))
            ->toBase()
            ->get([$dateColumn.' as moment', ...($valueColumn ? [$valueColumn.' as value'] : [])]);
    }
}
