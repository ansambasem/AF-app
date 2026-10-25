<?php

use App\Http\Middleware\AuthenticateMobileToken;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureBranchDomainAccess;
use App\Http\Middleware\EnsureFinancialAuditorAccess;
use App\Http\Middleware\EnsureUserDeviceAccess;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveBranchDomain;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Inertia\ExceptionResponse;
use Inertia\Inertia;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'mobile.auth' => AuthenticateMobileToken::class,
        ]);
        // Every response, the API's and the error pages' too, carries the security headers.
        $middleware->append(SecurityHeaders::class);
        $middleware->prepend(ResolveBranchDomain::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsureBranchDomainAccess::class);
        $middleware->web(append: [
            EnsureBranchDomainAccess::class,
            EnsureAccountIsActive::class,
            EnsureUserDeviceAccess::class,
            EnsureFinancialAuditorAccess::class,
            HandleInertiaRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // No permission, not found, expired and the like are Arabic pages in the app's own layout, with a way back.
        Inertia::handleExceptionsUsing(function (ExceptionResponse $response) {
            $showsPage = in_array($response->statusCode(), [403, 404, 405, 419, 429], true)
                || (in_array($response->statusCode(), [500, 503], true) && ! config('app.debug'));

            if ($showsPage && ! $response->request->is('api/*') && ! $response->request->expectsJson()) {
                $page = $response->render('Error', ['status' => $response->statusCode()])->withSharedData()->toResponse($response->request);

                // The headers that tell the caller what to do next (the methods allowed, when to retry) stay.
                foreach (['Allow', 'Retry-After'] as $name) {
                    if ($response->response->headers->has($name)) {
                        $page->headers->set($name, $response->response->headers->get($name));
                    }
                }

                return $page;
            }
        });
    })->create();
