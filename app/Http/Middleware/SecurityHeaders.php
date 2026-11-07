<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * The headers every response carries to keep the browser from being
 * tricked: no framing by other sites, no guessing of file types, a
 * stricter referrer, no camera or location, and HSTS over HTTPS. In
 * production a Content-Security-Policy lets only the app's own scripts run
 * (the one inline script of the page carries a per-request nonce), and
 * `X-Powered-By` (and the Inertia DevTools headers) are not given away. The policy is not sent in development,
 * where the Vite dev server needs more; `CSP_MODE` is `enforce` (default),
 * `report-only` to try it without blocking anything, or `off`.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $mode = $this->policyMode();
        $nonce = $mode === 'off' ? null : Vite::useCspNonce();

        $response = $next($request);
        if ($request->is('api/mobile/*')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        header_remove('X-Powered-By');
        $response->headers->remove('X-Powered-By');

        // The DevTools extension's bookkeeping headers are for development, whatever the environment says.
        if (app()->environment('production')) {
            foreach (array_keys($response->headers->all()) as $name) {
                if (str_starts_with($name, 'x-inertia-devtools')) {
                    $response->headers->remove($name);
                }
            }
        }

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
        ];

        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        if ($nonce !== null) {
            $headers[$mode === 'report-only' ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy'] = $this->policy($nonce);
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    /** What to do with the Content-Security-Policy: 'enforce', 'report-only' or 'off' (always off outside production). */
    private function policyMode(): string
    {
        if (! app()->environment('production')) {
            return 'off';
        }

        $mode = config('powercollect.security.csp');

        return in_array($mode, ['report-only', 'off'], true) ? $mode : 'enforce';
    }

    /**
     * Scripts only from the app (and the nonce'd one), styles from the app
     * and inline (the interface sets styles in its markup), fonts from
     * Google Fonts, and nothing from plugins or other sites' frames.
     */
    private function policy(string $nonce): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' https://fonts.gstatic.com data:",
            "img-src 'self' data: blob:",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ]);
    }
}
