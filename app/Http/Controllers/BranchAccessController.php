<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Support\BranchDomainContext;
use App\Support\BranchDomainScope;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BranchAccessController extends Controller
{
    public function index(Request $request): Response
    {
        $actor = $request->user();
        $branches = Branch::withoutGlobalScope(BranchDomainScope::class)
            ->where('is_active', true)->where('domain_enabled', true)->whereNotNull('domain')->orderBy('name')->get()
            ->filter(fn (Branch $branch): bool => $actor->canEnterBranch($branch))
            ->map(fn (Branch $branch): array => ['id' => $branch->id, 'name' => $branch->name,
                'domain' => $branch->domain, 'url' => BranchDomainContext::branchUrl($branch)])->values();

        return Inertia::render('Branches/Access', ['branches' => $branches,
            'routingEnabled' => (bool) config('powercollect.branch_domains_enabled')]);
    }
}
