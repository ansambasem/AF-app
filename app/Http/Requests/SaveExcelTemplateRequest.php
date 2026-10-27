<?php

namespace App\Http\Requests;

use App\Models\ExcelTemplate;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * Adding an Excel template, or changing one (its name, its layout, or
 * whether it is its kind's default). On a change every field is optional.
 */
class SaveExcelTemplateRequest extends FormRequest
{
    /**
     * The largest layout kept, in bytes of JSON — far more than any real one.
     */
    private const MAX_SETTINGS_BYTES = 30000;

    /**
     * The editor's switches, each on or off.
     */
    private const SWITCHES = ['header', 'totals', 'formulas', 'freeze', 'autofilter', 'rtl', 'zebra', 'currency', 'pageSetup', 'criteria'];

    /**
     * The editor's choices and the values each may take (lib/excelSheet.js).
     *
     * @var array<string, list<string>>
     */
    private const CHOICES = [
        'theme' => ['graphite', 'brand', 'blue', 'green', 'plain'],
        'fontSize' => ['small', 'normal', 'large'],
        'rowHeight' => ['compact', 'normal', 'roomy'],
        'orientation' => ['auto', 'portrait', 'landscape'],
    ];

    public function authorize(): bool
    {
        $template = $this->route('excel_template');

        return $template instanceof ExcelTemplate
            ? $this->user()->can('update', $template)
            : $this->user()->can('create', ExcelTemplate::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $template = $this->route('excel_template');
        $isNew = ! $template instanceof ExcelTemplate;
        $kind = $isNew ? $this->input('kind') : $template->kind;
        $sometimes = $isNew ? 'required' : 'sometimes';

        return [
            'kind' => [$isNew ? 'required' : 'prohibited', Rule::in(array_keys(ExcelTemplate::KINDS))],
            'name' => [$sometimes, 'string', 'max:60', Rule::unique('excel_templates', 'name')->where('kind', $kind)->ignore($template)],
            'is_default' => ['sometimes', 'boolean'],
            'settings' => [$sometimes, 'array', function (string $attribute, mixed $value, Closure $fail): void {
                if (strlen((string) json_encode($value)) > self::MAX_SETTINGS_BYTES) {
                    $fail('القالب أكبر من المسموح.');
                }
            }],
            ...collect(self::SWITCHES)->mapWithKeys(fn (string $key): array => ["settings.{$key}" => ['nullable', 'boolean']])->all(),
            ...collect(self::CHOICES)->mapWithKeys(fn (array $values, string $key): array => ["settings.{$key}" => ['nullable', Rule::in($values)]])->all(),
            'settings.parts' => ['nullable', 'array', 'max:30'],
            'settings.parts.*' => ['array'],
            'settings.parts.*.on' => ['nullable', 'boolean'],
            'settings.parts.*.groupBy' => ['nullable', 'string', 'max:60'],
            'settings.parts.*.columns' => ['nullable', 'array', 'max:80'],
            'settings.parts.*.columns.*.key' => ['required', 'string', 'max:60'],
            'settings.parts.*.columns.*.on' => ['nullable', 'boolean'],
            'settings.parts.*.columns.*.width' => ['nullable', 'integer', 'between:4,80'],
        ];
    }

    /**
     * The layout as kept: the known switches and choices and each part's
     * columns, never the file's title or name or anything else sent.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $settings = $this->validated('settings');

        return [
            ...Arr::only($settings, [...self::SWITCHES, ...array_keys(self::CHOICES)]),
            'parts' => collect($settings['parts'] ?? [])->map(fn (array $part): array => [
                'on' => (bool) ($part['on'] ?? true),
                'groupBy' => (string) ($part['groupBy'] ?? ''),
                'columns' => collect($part['columns'] ?? [])->map(fn (array $column): array => array_filter([
                    'key' => $column['key'],
                    'on' => (bool) ($column['on'] ?? true),
                    'width' => isset($column['width']) ? (int) $column['width'] : null,
                ], fn (mixed $value): bool => $value !== null))->values()->all(),
            ])->all(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['name.unique' => 'يوجد قالب بهذا الاسم، اختر اسمًا آخر.'];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => 'اسم القالب'];
    }
}
