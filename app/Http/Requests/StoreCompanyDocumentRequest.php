<?php

namespace App\Http\Requests;

use App\Models\CompanyDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCompanyDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', CompanyDocument::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'operation_id' => ['required', 'uuid'],
            'mode' => ['required', Rule::in(['blank', 'filled', 'archive'])],
            'company_form_template_id' => ['required_unless:mode,archive', 'nullable', 'integer'],
            'template_version' => ['required_unless:mode,archive', 'nullable', 'integer', 'min:1'],
            'subscription_id' => ['nullable', 'integer'],
            'title' => ['required_if:mode,archive', 'nullable', 'string', 'max:150'],
            'paper_reference' => ['required_if:mode,archive', 'nullable', 'string', 'max:100'],
            'paper_date' => ['required_if:mode,archive', 'nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'values' => ['nullable', 'array', 'max:100'],
            'values.*' => ['nullable', 'string', 'max:3000'],
            'attachment' => ['required_if:mode,archive', 'nullable', 'file', 'mimes:pdf,png,jpg,jpeg,webp', 'max:10240'],
        ];
    }
}
