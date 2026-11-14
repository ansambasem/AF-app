<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveBranchDomain
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('powercollect.branch_domains_enabled') || $request->is('up')) {
            return $next($request);
        }
        $host = strtolower($request->getHost());
        $centralHost = strtolower((string) parse_url(config('app.url'), PHP_URL_HOST));
        $loopbackHosts = ['localhost', '127.0.0.1', '[::1]', '::1'];
        $localCentralAlias = app()->isLocal() && in_array($centralHost, $loopbackHosts, true) && in_array($host, $loopbackHosts, true);
        if ($host !== $centralHost && ! $localCentralAlias) {
            $request->attributes->set('unavailable_branch_domain', true);
            $branch = Branch::query()->where('domain', $host)->where('domain_enabled', true)->where('is_active', true)->firstOrFail();
            abort_if($request->is('api/*'), 404);
            $request->attributes->set('unavailable_branch_domain', false);
            $request->attributes->set('domain_branch', $branch);
        }
        $cookieDomain = config('session.domain');
        config(['session.domain' => null]);
        try {
            return $next($request);
        } finally {
            config(['session.domain' => $cookieDomain]);
        }
    }
}
