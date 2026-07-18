<?php

use App\Http\Middleware\EnsureFeatureEnabled;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireAdminAccessKey;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'feature' => EnsureFeatureEnabled::class,
        ]);

        // Скритата врата на /admin трябва да бяга ПРЕДИ auth middleware-а —
        // иначе priority сортирането пуска Authenticate пръв и гостите виждат
        // login redirect вместо 404.
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: RequireAdminAccessKey::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
