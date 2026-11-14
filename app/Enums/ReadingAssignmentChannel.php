<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * How a meter box is read in a reading cycle.
 */
enum ReadingAssignmentChannel: string
{
    use HasOptions;

    /** By one field reader, in the field app. */
    case Mobile = 'mobile';

    /** On a printed sheet, entered later from the reading sheet. */
    case Paper = 'paper';

    public function label(): string
    {
        return match ($this) {
            self::Mobile => 'Field Reader',
            self::Paper => 'Paper Sheet',
        };
    }
}
