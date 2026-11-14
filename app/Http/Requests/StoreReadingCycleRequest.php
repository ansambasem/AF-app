<?php

namespace App\Http\Requests;

use App\Models\Branch;
use App\Models\MeterReading;
use App\Models\ReadingCycle;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A new reading cycle: a week of the branch's reading schedule and the day
 * it is read — the week's reading day, or a later one before the next week
 * ends, never a day already gone.
 */
class StoreReadingCycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ReadingCycle::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'branch_id' => $this->user()->isSuperAdmin() ? ['required', Rule::exists('branches', 'id')] : ['exclude'],
            'week_start' => ['required', 'date_format:Y-m-d'],
            'reading_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now(config('app.business_timezone'))->toDateString()],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $branchId = $this->branchId();
                $weekStart = Carbon::parse($this->input('week_start'));

                if (! MeterReading::weekStartFor($weekStart, $branchId)->equalTo($weekStart)) {
                    $validator->errors()->add('week_start', 'اختر أسبوعًا من أسابيع القراءة في جدول الفرع.');

                    return;
                }

                $weekEnd = MeterReading::weekEndFor($weekStart, $branchId);
                $readingDate = Carbon::parse($this->input('reading_date'));

                if ($readingDate->lt($weekEnd) || $readingDate->gt($weekEnd->copy()->addDays(6))) {
                    $validator->errors()->add('reading_date', 'يوم القراءة يكون من نهاية الأسبوع ('.$weekEnd->toDateString().') حتى ستة أيام بعدها.');

                    return;
                }

                if (ReadingCycle::query()->where('branch_id', $branchId)->whereDate('week_start', $weekStart->toDateString())->exists()) {
                    $validator->errors()->add('week_start', 'للفرع دورة قراءة لهذا الأسبوع من قبل.');
                }
            },
        ];
    }

    public function branchId(): int
    {
        return $this->user()->isSuperAdmin() ? (int) $this->input('branch_id') : (int) $this->user()->workBranchId();
    }

    public function branch(): Branch
    {
        return Branch::query()->findOrFail($this->branchId());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reading_date.after_or_equal' => 'يوم القراءة لا يكون قبل اليوم.',
        ];
    }
}
