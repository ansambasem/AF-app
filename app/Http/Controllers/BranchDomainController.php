<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateBranchDomainRequest;
use App\Models\Branch;
use Illuminate\Http\RedirectResponse;

class BranchDomainController extends Controller
{
    public function update(UpdateBranchDomainRequest $request, Branch $branch): RedirectResponse
    {
        $branch->forceFill($request->validated())->save();

        return back()->with('status', 'تم حفظ رابط الفرع.');
    }
}
