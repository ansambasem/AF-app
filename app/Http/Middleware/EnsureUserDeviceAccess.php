<?php

namespace App\Http\Middleware;

use App\Support\UserDeviceAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserDeviceAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() !== null && $request->hasSession() && $request->session()->has(Auth::guard('web')->getName()) && ! $request->routeIs('logout')) {
            try {
                app(UserDeviceAccess::class)->claimWeb($request, $request->user());
            } catch (ValidationException $error) {
                app(UserDeviceAccess::class)->releaseWeb($request);
                Auth::guard('web')->logoutCurrentDevice();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return $request->expectsJson() ? response()->json(['message' => $error->errors()['username'][0]], 401)
                    : redirect()->route('login')->withErrors($error->errors());
            }
        }

        return $next($request);
    }
}
