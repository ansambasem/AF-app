<?php

namespace App\Http\Controllers;

use App\Support\BranchViewContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BranchViewController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        abort_unless(BranchViewContext::available($request), 403);
        $validated = $request->validate(['branch_id' => ['nullable', 'integer', 'exists:branches,id']]);
        $key = BranchViewContext::sessionKey($request->user());
        if (isset($validated['branch_id'])) {
            $request->session()->put($key, (int) $validated['branch_id']);
        } else {
            $request->session()->forget($key);
        }

        return redirect()->route('dashboard');
    }
}
