<?php

namespace App\Http\Concerns;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * After a save made from one of the dashboard's windows, go back to the
 * dashboard (keeping its query, such as an open statement) instead of the
 * resource's own list page. The dashboard sends where to return in the
 * `X-Return-To` header; only a dashboard address is accepted, so the header
 * can't send anyone elsewhere.
 */
trait ReturnsToDashboard
{
    protected function redirectAfterSave(Request $request, string $route, string $status): RedirectResponse
    {
        return redirect($this->dashboardReturnUrl($request) ?? route($route))->with('status', $status);
    }

    private function dashboardReturnUrl(Request $request): ?string
    {
        $target = $request->header('X-Return-To');

        if (! is_string($target) || ! str_starts_with($target, '/')) {
            return null;
        }

        $parts = parse_url($target);

        if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || ($parts['path'] ?? null) !== '/dashboard') {
            return null;
        }

        return route('dashboard').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
