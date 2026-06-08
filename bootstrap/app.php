<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\EnsureAccessToken;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequestResponseEncryptionMiddleware;
use App\Http\Middleware\TrackLastActiveAt;
use App\Responser\JsonResponser;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
        $middleware->api(append: [
            EnsureAccessToken::class,
            TrackLastActiveAt::class,
        ]);

        // Do not call config() here: the config repository is not bound until
        // after the application boots. Gating lives in RequestResponseEncryptionMiddleware.
        $middleware->api(prepend: [
            RequestResponseEncryptionMiddleware::class,
        ]);
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'last.active' => TrackLastActiveAt::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {

        $exceptions->render(function (ApiException $e, $request) {
            if ($request->expectsJson()) {
                return JsonResponser::send(true, $e->getMessage(), null, $e->status);
            }
        });

        $exceptions->render(function (ValidationException $e, $request) {
            if ($request->expectsJson()) {
                $message = $e->validator->errors()->first() ?: 'Validation error';

                return JsonResponser::send(true, $message, $e->errors(), 422);
            }
        });

        $exceptions->render(function (HttpException $e, $request) {
            if ($request->expectsJson()) {
                return JsonResponser::send(true, $e->getMessage(), null, $e->getStatusCode());
            }
        });

        $exceptions->render(function (AuthenticationException $e, $request) {
            if ($request->expectsJson()) {
                $message = $e->getMessage() !== '' ? $e->getMessage() : 'Unauthenticated.';

                return JsonResponser::send(true, $message, null, 401);
            }
        });
    })->create();
