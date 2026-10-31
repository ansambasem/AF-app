<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use App\Support\BranchDomainScope;
use App\Support\BranchViewContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

class ResolveBranchView
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('view_branch', null);
        if (! BranchViewContext::available($request)) {
            return $next($request);
        }

        $key = BranchViewContext::sessionKey($request->user());
        $clearBranch = false;
        if ($request->isMethod('GET') && $request->header('Purpose') !== 'prefetch') {
            if ($request->filled('governorate') && ! Arr::hasAny($request->query(), ['filter.branch_id', 'branch_id', 'branch'])) {
                $request->session()->forget($key);
            }
            foreach (['filter.branch_id', 'branch_id', 'branch'] as $parameter) {
                if (! Arr::has($request->query(), $parameter)) {
                    continue;
                }
                $choice = Arr::get($request->query(), $parameter);
                if ($choice === null || $choice === '' || $choice === 'all') {
                    $request->session()->forget($key);
                    $clearBranch = true;
                } elseif (is_scalar($choice) && ctype_digit((string) $choice) && (int) $choice > 0) {
                    $branch = Branch::withoutGlobalScope(BranchDomainScope::class)->findOrFail((int) $choice);
                    $request->session()->put($key, $branch->id);
                }
                break;
            }
        }

        $branchId = $request->session()->get($key);
        $branch = $branchId ? Branch::withoutGlobalScope(BranchDomainScope::class)->find($branchId) : null;
        if ($branch === null) {
            $request->session()->forget($key);
        }
        $request->attributes->set('view_branch', $branch);

        if ($request->isMethod('GET')) {
            $query = $request->query();
            if ($clearBranch) {
                Arr::forget($query, ['filter.branch_id', 'branch_id', 'branch']);
            } elseif ($branch !== null) {
                foreach (['filter.branch_id', 'branch_id', 'branch'] as $parameter) {
                    Arr::set($query, $parameter, (string) $branch->id);
                }
            }
            $request->query->replace($query);
        }

        return $next($request);
    }
}
