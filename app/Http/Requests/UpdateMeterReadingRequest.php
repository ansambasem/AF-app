<?php

namespace App\Http\Requests;

use App\Models\MeterReading;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateMeterReadingRequest extends FormRequest
{
    private ?MeterReading $nextReading = null;

    /**
     * Determine if the user is authorized to make this request: to correct
     * the reading, or to edit it as the last reading of the week after it.
     */
    public function authorize(): bool
    {
        $meterReading = $this->route('meter_reading');

        if ($meterReading->requires_reentry) {
            return $this->user()->can('update', $meterReading);
        }

        return $this->user()->can('update', $meterReading) || ($meterReading->next_previous_reading === null && $this->user()->can('updateAsLastReading', $meterReading));
    }

    /**
     * Only the reading itself and its notes can be corrected — the
     * subscription and week stay fixed.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'current_reading' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'notes' => ['nullable', 'string', 'max:1000'],
            // Approve it again straight away, when the corrector may (see MeterReadingController::update).
            'approve' => ['sometimes', 'boolean'],
            // Approving an unusual reading again takes the corrector's say-so that it is right.
            'confirm_unusual' => ['sometimes', 'boolean'],
            // Edited as the last reading (its pencil): what was approved is approved again at once (see MeterReadingController::update).
            'as_last_reading' => ['sometimes', 'boolean'],
            // This week's reading, entered again by hand when its last reading is edited (see after()).
            'next_current_reading' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
        ];
    }

    /**
     * A reading can only be corrected while it is the subscription's latest
     * week, since the following week starts from it — or, by someone who may
     * edit it as the last reading, while only the week after it has been
     * entered: that week then starts from it, so it must not pass that
     * week's reading.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var MeterReading $meterReading */
                $meterReading = $this->route('meter_reading');

                $laterReadings = MeterReading::query()
                    ->where('subscription_id', $meterReading->subscription_id)
                    ->where('week_start', '>', $meterReading->week_start)
                    ->orderBy('week_start')
                    ->limit(2)
                    ->get();

                if ($laterReadings->count() > 1 || ($laterReadings->isNotEmpty() && ! $this->user()->can('updateAsLastReading', $meterReading))) {
                    $validator->errors()->add('current_reading', 'لا يمكن تعديل هذه القراءة لوجود قراءة لأسبوع لاحق.');

                    return;
                }

                $this->nextReading = $laterReadings->first();

                if ($this->float('current_reading') < $meterReading->previous_reading) {
                    $validator->errors()->add('current_reading', "القراءة الحالية لا يمكن أن تكون أقل من القراءة السابقة ({$meterReading->previous_reading}).");

                    return;
                }

                $consumption = MeterReading::consumptionBetween($meterReading->previous_reading, $this->float('current_reading'));

                if (MeterReading::isImpossibleConsumption($consumption)) {
                    $validator->errors()->add('current_reading', StoreMeterReadingRequest::impossibleConsumptionMessage($consumption));

                    return;
                }

                if ($this->nextReading === null) {
                    return;
                }

                if ($this->filled('next_current_reading')) {
                    $this->checkNextReading($validator, $this->float('next_current_reading'), 'next_current_reading', 'القراءة الحالية لا يمكن أن تكون أقل من القراءة السابقة');

                    return;
                }

                if ($this->entersNextReadingAgain()) {
                    $validator->errors()->add('next_current_reading', 'أدخل قراءة هذا الأسبوع من جديد بعد تعديل القراءة السابقة.');

                    return;
                }

                if ($this->checksSavedNextReading()) {
                    $this->checkNextReading($validator, $this->nextReading->current_reading, 'current_reading', 'القراءة السابقة لا يمكن أن تكون أكبر من قراءة الأسبوع الذي يليها');
                }
            },
        ];
    }

    /**
     * Whether this week's reading must be entered again with the edit: an
     * edit of the last reading confirmed at once (its pencil) recalculates
     * this week only from a reading typed again by hand, never from the
     * number it had.
     */
    protected function entersNextReadingAgain(): bool
    {
        return $this->boolean('as_last_reading');
    }

    /**
     * Whether, with no reading typed again for this week, the edit is
     * checked against the reading this week has (the week then starts from
     * the edit and keeps its reading).
     */
    protected function checksSavedNextReading(): bool
    {
        return true;
    }

    /**
     * This week, starting from the edited reading, must not go backwards
     * nor use more than any subscription could.
     */
    private function checkNextReading(Validator $validator, float $nextCurrentReading, string $field, string $belowMessage): void
    {
        if ($nextCurrentReading < $this->float('current_reading')) {
            $validator->errors()->add($field, $field === 'next_current_reading'
                ? "{$belowMessage} ({$this->float('current_reading')})."
                : "{$belowMessage} ({$nextCurrentReading}).");

            return;
        }

        $nextConsumption = MeterReading::consumptionBetween($this->float('current_reading'), $nextCurrentReading);

        if (MeterReading::isImpossibleConsumption($nextConsumption)) {
            $validator->errors()->add($field, StoreMeterReadingRequest::impossibleConsumptionMessage($nextConsumption));
        }
    }

    /**
     * This week's reading as typed again with the edit, or null when it was not.
     */
    public function nextCurrentReading(): ?float
    {
        return $this->filled('next_current_reading') ? $this->float('next_current_reading') : null;
    }

    /**
     * The reading of the week after the corrected one, which starts from
     * it, when that week has been entered.
     */
    public function nextReading(): ?MeterReading
    {
        return $this->nextReading;
    }
}
