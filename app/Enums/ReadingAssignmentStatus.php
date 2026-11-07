<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Where a meter box's allocation in a reading cycle stands.
 */
enum ReadingAssignmentStatus: string
{
    use HasOptions;

    /** Allocated but not approved yet: the reader cannot download it. */
    case Draft = 'draft';

    /** Approved: the reader can download it, and the paper sheet print it. */
    case Approved = 'approved';

    /** Back in the available pool. */
    case Unassigned = 'unassigned';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Approved => 'Approved',
            self::Unassigned => 'Not Allocated',
        };
    }
}
