<?php

namespace App\Http\Requests;

use App\Models\Branch;
use App\Models\ClosingSetting;
use App\Support\BranchDomainContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBoxPeriodSettingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manageAny', ClosingSetting::class)
            && $this->user()->can('manage', [ClosingSetting::class, $this->branch()]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'week_starts_on' => ['required', 'integer', 'between:0,6'],
        ];
    }

    public function branch(): ?Branch
    {
        $actor = $this->user();
        $branchId = $this->filled('branch_id') ? $this->integer('branch_id') : (BranchDomainContext::id() ?? ($actor->isSuperAdmin() ? null : $actor->workBranchId()));

        return $branchId === null ? null : Branch::query()
            ->when(! $actor->isSuperAdmin(), fn (Builder $query) => $query->whereKey($actor->workBranchId()))
            ->findOrFail($branchId);
    }

    public function attributes(): array
    {
        return ['week_starts_on' => 'يوم بداية فترة الصناديق'];
    }
}
