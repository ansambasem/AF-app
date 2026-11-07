<?php

namespace App\Http\Requests;

use App\Enums\ReadingEntryMode;
use App\Models\Branch;
use App\Models\ReadingEntrySetting;
use App\Support\BranchDomainContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateReadingScheduleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('manage', [ReadingEntrySetting::class, $this->branch()]);
    }

    /**
     * The reading day and open days are Carbon day-of-week numbers
     * (0 = Sunday … 6 = Saturday).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'reading_day' => ['required', 'integer', 'between:0,6'],
            'assignment_lead_days' => ['sometimes', 'integer', 'between:0,6'],
            'open_days' => ['required', 'array', 'min:1'],
            'open_days.*' => ['integer', 'between:0,6', 'distinct'],
            'opens_at' => ['required_with:closes_at', 'filled', 'date_format:H:i'],
            'closes_at' => ['required_with:opens_at', 'filled', 'date_format:H:i', 'after:opens_at'],
            'mode' => ['required', Rule::enum(ReadingEntryMode::class)],
        ];
    }

    /**
     * The branch whose schedule this is, or null for the company's.
     */
    public function branch(): ?Branch
    {
        $branchId = $this->filled('branch_id') ? $this->integer('branch_id') : BranchDomainContext::id();

        return $branchId === null ? null : Branch::query()->findOrFail($branchId);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reading_day.required' => 'اختر يوم القراءة الأسبوعي.',
            'open_days.required' => 'اختر يومًا واحدًا على الأقل لفتح الإدخال.',
            'open_days.min' => 'اختر يومًا واحدًا على الأقل لفتح الإدخال.',
            'opens_at.required_with' => 'حدد ساعة بداية الإدخال.',
            'opens_at.filled' => 'حدد ساعة بداية الإدخال.',
            'opens_at.date_format' => 'أدخل ساعة بداية صحيحة.',
            'closes_at.required_with' => 'حدد ساعة نهاية الإدخال.',
            'closes_at.filled' => 'حدد ساعة نهاية الإدخال.',
            'closes_at.date_format' => 'أدخل ساعة نهاية صحيحة.',
            'closes_at.after' => 'يجب أن تكون ساعة النهاية بعد البداية في اليوم نفسه.',
        ];
    }
}
