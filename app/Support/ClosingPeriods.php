<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\ClosingSetting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The days, weeks and months closings cover, in the business's time zone, as
 * set on the closing schedule page. A business day closes at its branch's
 * cut-off time: at midnight by default, or earlier in the evening, when later
 * payments count for the next day. Each branch has its own (see `for()`), so
 * its days, and when they have closed, are its own. A week starts on the
 * company's chosen weekday, the same for every branch since the weekly closing
 * approves them together; a month is the calendar month. A week that spans two
 * months is split between them by date, never added whole.
 */
class ClosingPeriods
{
    private function __construct(private ClosingSetting $setting) {}

    /**
     * The days of a branch, by its own cut-off. Without a branch, the
     * company's default.
     */
    public static function for(Branch|int|null $branch = null): self
    {
        return new self(ClosingSetting::forBranch($branch));
    }

    /**
     * The business day that is furthest along among the branches: the day
     * any of them has reached, for a view that spans them.
     *
     * @param  Collection<int, Branch>  $branches
     */
    public static function furthestToday(Collection $branches): CarbonImmutable
    {
        return $branches->isEmpty()
            ? self::for()->today()
            : $branches->map(fn (Branch $branch): CarbonImmutable => self::for($branch)->today())->max();
    }

    /**
     * Whether the business day (or the period ending on it) has closed in
     * every one of the branches.
     *
     * @param  Collection<int, Branch>  $branches
     */
    public static function hasEndedInAll(Collection $branches, CarbonInterface|string $lastDay): bool
    {
        return $branches->isEmpty()
            ? self::for()->hasEnded($lastDay)
            : $branches->every(fn (Branch $branch): bool => self::for($branch)->hasEnded($lastDay));
    }

    /**
     * A business date (Y-m-d) at midnight, business time.
     */
    public static function date(CarbonInterface|string $date): CarbonImmutable
    {
        return CarbonImmutable::parse(
            $date instanceof CarbonInterface ? $date->toDateString() : $date,
            config('app.business_timezone'),
        )->startOfDay();
    }

    /**
     * The cut-off as HH:MM.
     */
    public function cutoff(): string
    {
        return $this->setting->cutoff();
    }

    /**
     * The moment the business day closes, business time.
     */
    public function dayEnd(CarbonInterface|string $day): CarbonImmutable
    {
        return self::date($day)->addMinutes($this->setting->cutoffMinutes());
    }

    /**
     * The UTC moments a business period starts at and ends before: from
     * the cut-off of the day before its first day to its last day's.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function utcRange(CarbonInterface|string $first, CarbonInterface|string $last): array
    {
        return [$this->dayEnd(self::date($first)->subDay())->utc(), $this->dayEnd($last)->utc()];
    }

    /**
     * The business day a moment belongs to (Y-m-d): its calendar date, or
     * the next day once that date's cut-off has passed.
     */
    public function dayOf(CarbonInterface|string $moment): string
    {
        $local = CarbonImmutable::parse($moment, 'UTC')->setTimezone(config('app.business_timezone'));
        $day = self::date($local->toDateString());

        return ($local->greaterThanOrEqualTo($this->dayEnd($day)) ? $day->addDay() : $day)->toDateString();
    }

    /**
     * The business day under way now.
     */
    public function today(): CarbonImmutable
    {
        return self::date($this->dayOf(now()));
    }

    /**
     * The latest business day that has closed.
     */
    public function latestEndedDay(): CarbonImmutable
    {
        return $this->today()->subDay();
    }

    /**
     * Whether the business day (or the period ending on it) has closed.
     */
    public function hasEnded(CarbonInterface|string $lastDay): bool
    {
        return now()->greaterThanOrEqualTo($this->dayEnd($lastDay));
    }

    /**
     * The first and last day of the week containing the date.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function week(CarbonInterface|string $date): array
    {
        $start = self::date($date)->startOfWeek(ClosingSetting::company()->week_starts_on);

        return [$start, $start->addDays(6)];
    }

    /**
     * The first and last day of the calendar month containing the date.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function month(CarbonInterface|string $date): array
    {
        $day = self::date($date);

        return [$day->startOfMonth(), $day->endOfMonth()->startOfDay()];
    }

    /**
     * Every date from the first to the last, as Y-m-d.
     *
     * @return array<int, string>
     */
    public static function days(CarbonInterface|string $first, CarbonInterface|string $last): array
    {
        $days = [];

        for ($day = self::date($first); $day->lessThanOrEqualTo(self::date($last)); $day = $day->addDay()) {
            $days[] = $day->toDateString();
        }

        return $days;
    }
}
