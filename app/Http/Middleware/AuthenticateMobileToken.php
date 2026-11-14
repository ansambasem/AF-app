<?php

namespace App\Http\Middleware;

use App\Models\MobileAccessToken;
use App\Support\UserDeviceAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMobileToken
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $plainTextToken = self::plainTextToken($request);
        $token = $plainTextToken
            ? MobileAccessToken::query()->with('user')->where('token_hash', hash('sha256', $plainTextToken))->first()
            : null;

        if (! $token || $token->expires_at->isPast() || ! $token->user?->is_active) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        abort_if($token->user->isAuditOnly(), 403);
        if (! $request->routeIs('mobile.logout') && ! app(UserDeviceAccess::class)->allowed($token->user, 'mobile:'.$token->id)) {
            $token->delete();

            return response()->json(['message' => UserDeviceAccess::LIMIT_MESSAGE], 401);
        }

        Auth::setUser($token->user);
        $request->attributes->set('mobile_access_token', $token);
        $request->setUserResolver(fn () => $token->user);

        $token->forceFill(['last_used_at' => now()])->saveQuietly();

        return $next($request);
    }

    /**
     * The token the app sent: the standard bearer header, or, where the
     * host's CGI handler withholds that header from PHP, the same
     * "Bearer <token>" value in X-Mobile-Authorization.
     */
    public static function plainTextToken(Request $request): ?string
    {
        $token = $request->bearerToken();
        if ($token) {
            return $token;
        }
        $header = (string) $request->headers->get('X-Mobile-Authorization', '');

        return str_starts_with($header, 'Bearer ') && trim(substr($header, 7)) !== '' ? trim(substr($header, 7)) : null;
    }
}
