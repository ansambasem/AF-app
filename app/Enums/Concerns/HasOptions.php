<?php

namespace App\Enums\Concerns;

/**
 * For string-backed enums with a `label()` method: turns cases into the
 * `{value, label}` pairs every select and filter dropdown expects.
 */
trait HasOptions
{
    /**
     * The given cases (every case by default) as select options, with
     * translated labels.
     *
     * @param  array<int, self>|null  $cases
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(?array $cases = null): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => __($case->label())],
            $cases ?? self::cases(),
        );
    }
}
