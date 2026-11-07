<?php

namespace App\Http\Concerns;

use App\Models\Area;
use App\Models\Branch;
use App\Models\Governorate;
use App\Models\UserType;
use App\Support\BranchDomainContext;
use Illuminate\Support\Collection;

/**
 * The dropdown options the branch and user forms need, shared by their own
 * pages and by the dashboard's quick "new …" buttons.
 */
trait ProvidesFormOptions
{
    /**
     * The governorates and areas a branch can be placed in. Areas are sent
     * unfiltered (each carrying its governorate_id) so the form can narrow
     * the area choices client-side once a governorate is picked.
     *
     * @return array{governorates: Collection, areas: Collection}
     */
    protected function branchFormOptions(): array
    {
        return [
            'governorates' => Governorate::orderBy('name')->get(),
            'areas' => Area::with('governorate')->orderBy('name')->get(),
        ];
    }

    /**
     * The branch options for the user forms, and whether the actor may
     * choose the branch themselves.
     *
     * @return array{branches: Collection, canChooseBranch: bool, canCreateUserType: bool, userTypeOptions: Collection}
     */
    protected function userBranchOptions(): array
    {
        $canChooseBranch = auth()->user()->isSuperAdmin();

        return [
            'branches' => $canChooseBranch ? Branch::orderBy('name')->get() : collect(),
            'canChooseBranch' => $canChooseBranch,
            'canCreateUserType' => BranchDomainContext::id() === null && auth()->user()->can('create', UserType::class),
            'userTypeOptions' => UserType::orderBy('name')->get(['id', 'name'])
                ->map(fn (UserType $type): array => ['value' => $type->id, 'label' => $type->name]),
        ];
    }
}
