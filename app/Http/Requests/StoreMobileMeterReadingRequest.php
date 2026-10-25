<?php

namespace App\Http\Requests;

use App\Models\MeterReading;
use App\Models\ReadingAssignment;
use App\Models\Subscription;
use App\Support\DailySeries;
use Illuminate\Validation\Validator;

class StoreMobileMeterReadingRequest extends StoreMeterReadingRequest
{
    protected function creationAbility(): string
    {
        return 'create';
    }

    public function authorize(): bool
    {
        $existingReading = $this->existingReading();

        return $existingReading
            ? $existingReading->recorded_by === $this->user()->id
            : parent::authorize();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mobile_operation_id' => ['required', 'uuid'],
            ...($this->existingReading() ? [] : parent::rules()),
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        if ($this->existingReading()) {
            return [];
        }

        return [...parent::after(), function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $subscription = Subscription::findOrFail($this->integer('subscription_id'));
            $assigned = ReadingAssignment::query()->forMobileReader($this->user())
                ->where('meter_box_id', $subscription->meter_box_id)
                ->whereHas('cycle', fn ($query) => $query->whereDate('week_start', $this->weekStart()->toDateString())
                    ->whereDate('reading_date', DailySeries::today()->toDateString()))->exists();
            if (! $assigned) {
                $validator->errors()->add('subscription_id', 'هذا الطبلون ليس ضمن قراءاتك المعتمدة لهذا اليوم. حدّث توزيع القراءات.');
            }
        }];
    }

    public function existingReading(): ?MeterReading
    {
        $operationId = $this->input('mobile_operation_id');

        return is_string($operationId) && $operationId !== ''
            ? MeterReading::query()->where('mobile_operation_id', $operationId)->first()
            : null;
    }
}
