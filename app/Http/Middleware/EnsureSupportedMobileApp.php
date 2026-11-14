<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSupportedMobileApp
{
    /**
     * The header in which the field app sends its build number (the number
     * after "+" in mobile/pubspec.yaml's version). Builds made before the
     * header existed send none, and count as build 0.
     */
    public const BUILD_HEADER = 'X-App-Build';

    /**
     * Turn away a field app older than `powercollect.mobile_app_min_build`,
     * with a 426 that says to update and where to download the app, so an
     * old app is never sent replies it no longer understands. Signing out
     * stays open to any build. With no minimum set, every build is served.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $minimumBuild = (int) config('powercollect.mobile_app_min_build');

        if ($minimumBuild > 0 && ! $request->routeIs('mobile.logout') && $this->build($request) < $minimumBuild) {
            return response()->json([
                'message' => 'نسخة التطبيق على هذا الجهاز قديمة. حدّث التطبيق إلى آخر نسخة ثم أعد المحاولة؛ القراءات والمسودات المحفوظة على الجهاز تبقى كما هي.',
                'download_url' => config('powercollect.mobile_app_url'),
            ], Response::HTTP_UPGRADE_REQUIRED);
        }

        return $next($request);
    }

    private function build(Request $request): int
    {
        $build = (string) $request->header(self::BUILD_HEADER, '');

        return ctype_digit($build) ? (int) $build : 0;
    }
}
