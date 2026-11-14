<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The last reading as it would be edited, checked as the edit itself is
 * (UpdateMeterReadingRequest) so its preview shows only what could be saved.
 */
class PreviewLastReadingRequest extends UpdateMeterReadingRequest
{
    /**
     * Only whoever may edit the reading as the last reading sees the preview.
     */
    public function authorize(): bool
    {
        $reading = $this->route('meter_reading');

        return ! $reading->requires_reentry && $reading->next_previous_reading === null && $this->user()->can('updateAsLastReading', $reading);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'current_reading' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'next_current_reading' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
        ];
    }

    /**
     * The preview is first asked for before this week's reading is typed
     * again, to show last week's side.
     */
    protected function entersNextReadingAgain(): bool
    {
        return false;
    }

    /**
     * This week's reading is typed again, so the number it had bounds nothing.
     */
    protected function checksSavedNextReading(): bool
    {
        return false;
    }
}
