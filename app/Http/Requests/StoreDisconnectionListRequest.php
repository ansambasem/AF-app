<?php

namespace App\Http\Requests;

use App\Models\DisconnectionList;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDisconnectionListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', DisconnectionList::class);
    }

    /**
     * A list belongs to the user's own branch; the Super Admin chooses it.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'branch_id' => $this->user()->isSuperAdmin() ? ['required', Rule::exists('branches', 'id')] : ['exclude'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['title' => 'اسم القائمة', 'notes' => 'الملاحظات', 'branch_id' => 'الفرع'];
    }
}
