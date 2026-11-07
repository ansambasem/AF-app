<?php

namespace App\Http\Requests;

use App\Enums\ReadingAssignmentChannel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Meter boxes moved, each from the version shown on the board, to a reader,
 * to the paper sheets, or back to the available pool.
 */
class AllocateReadingBoxesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', $this->route('readingCycle'))
            && ((! $this->boolean('recurring') && ! $this->boolean('publish'))
                || $this->user()->can('approve', $this->route('readingCycle')));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'box_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'box_ids.*' => ['integer', 'distinct'],
            'target' => ['required', Rule::in(['available', ...array_column(ReadingAssignmentChannel::cases(), 'value')])],
            'user_id' => ['required_if:target,mobile', 'nullable', 'integer', Rule::exists('users', 'id')],
            'versions' => ['nullable', 'array'],
            'versions.*' => ['integer', 'min:0'],
            'confirm_downloaded' => ['boolean'],
            'recurring' => ['boolean'],
            'publish' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'box_ids.required' => 'اختر طبلونًا واحدًا على الأقل.',
            'user_id.required_if' => 'اختر القارئ.',
        ];
    }
}
