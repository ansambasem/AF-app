<?php

namespace App\Http\Requests;

use App\Models\PrintTemplate;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adding a print template, or changing one (its name, its design, or
 * whether it is its list's default). On a change every field is optional.
 */
class SavePrintTemplateRequest extends FormRequest
{
    /**
     * The largest design kept, in bytes of JSON — far more than any real one.
     */
    private const MAX_LAYOUT_BYTES = 60000;

    public function authorize(): bool
    {
        $template = $this->route('print_template');

        return $template instanceof PrintTemplate
            ? $this->user()->can('update', $template)
            : $this->user()->can('create', PrintTemplate::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $template = $this->route('print_template');
        $isNew = ! $template instanceof PrintTemplate;
        $page = $isNew ? $this->input('page') : $template->page;
        $sometimes = $isNew ? 'required' : 'sometimes';

        return [
            'page' => [$isNew ? 'required' : 'prohibited', Rule::in(array_keys(PrintTemplate::PAGES))],
            'name' => [$sometimes, 'string', 'max:100', Rule::unique('print_templates', 'name')->where('page', $page)->ignore($template)],
            'is_default' => ['sometimes', 'boolean'],
            'layout' => [$sometimes, 'array', function (string $attribute, mixed $value, Closure $fail): void {
                if (strlen((string) json_encode($value)) > self::MAX_LAYOUT_BYTES) {
                    $fail('التصميم أكبر من المسموح.');
                }
            }],
            'layout.paper' => ['required_with:layout', Rule::in(['A4', 'A3', 'A5', 'letter', 'legal'])],
            'layout.orientation' => ['required_with:layout', Rule::in(['portrait', 'landscape'])],
            'layout.margin' => ['nullable', 'numeric', 'between:0,40'],
            'layout.fontSize' => ['nullable', 'numeric', 'between:6,24'],
            'layout.columns' => ['required_with:layout', 'array', 'max:100'],
            'layout.columns.*.key' => ['required', 'string', 'max:200', 'distinct'],
            'layout.columns.*.label' => ['nullable', 'string', 'max:200'],
            'layout.columns.*.width' => ['nullable', 'numeric', 'between:0,120'],
            'layout.columns.*.blank' => ['nullable', 'boolean'],
            'layout.columns.*.secondaryKey' => ['nullable', 'string', 'max:200'],
            'layout.group.then' => ['nullable', 'array', 'max:2'],
            'layout.group.then.*' => ['string', 'max:200', 'distinct'],
            'layout.table.accent' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }

    /**
     * The whole design as sent, once validated. validated() would keep only
     * the parts that have their own rule and drop the rest (headings,
     * sorting, each column's choices…).
     *
     * @return array<string, mixed>
     */
    public function layout(): array
    {
        return (array) $this->input('layout');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'اكتب اسمًا للقالب.',
            'name.unique' => 'يوجد قالب بهذا الاسم لهذه القائمة، اختر اسمًا آخر.',
            'page.in' => 'هذه الصفحة لا تدعم قوالب الطباعة.',
        ];
    }
}
