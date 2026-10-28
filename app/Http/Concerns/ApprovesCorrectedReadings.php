<?php

namespace App\Http\Concerns;

use App\Models\MeterReading;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Approving again a week that editing the last reading sent back to review,
 * shared by the reading's edit and the last reading's adjustment.
 */
trait ApprovesCorrectedReadings
{
    /**
     * Approve again a week sent back to review by editing the last reading,
     * as part of confirming that edit. A week the edit made unusual is held
     * until the editor confirms it, and then nothing of the edit is saved.
     *
     * @throws ValidationException when the week is unusual and not confirmed
     */
    protected function approveCorrectedLastReading(MeterReading $reading, User $actor, bool $confirmUnusual): bool
    {
        if (! $reading->approve($actor, $confirmUnusual)) {
            throw ValidationException::withMessages([
                'confirm_unusual' => 'استهلاك أسبوع '.$reading->week_start->format('d/m').' بعد التعديل ('.rtrim(rtrim(number_format((float) $reading->consumption, 2, '.', ''), '0'), '.').' كيلو) أعلى بكثير من المعتاد؛ أكّد أنه صحيح ليُعتمد.',
            ]);
        }

        return true;
    }
}
