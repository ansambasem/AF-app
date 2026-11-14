<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * What became of a reading the field app uploaded.
 */
enum ReadingSubmissionStatus: string
{
    use HasOptions;

    /** Saved as the week's reading, waiting for the usual approval. */
    case Accepted = 'accepted';

    /** Kept, but held for a supervisor: out of its day, after a reassignment, or in conflict. */
    case NeedsReview = 'needs_review';

    /** Refused: it cannot be a reading of this assignment. */
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Accepted => 'Accepted',
            self::NeedsReview => 'Needs Review',
            self::Rejected => 'Rejected',
        };
    }
}
