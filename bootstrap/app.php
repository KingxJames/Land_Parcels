<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->statefulApi();
        $middleware->api(append: [
            \App\Http\Middleware\SetPermissionTeam::class,
            \Modules\Auth\Http\Middleware\EnsureActive::class,
        ]);
        $middleware->alias([
            'staff' => \Modules\Auth\Http\Middleware\EnsureStaff::class,
            'org' => \Modules\Auth\Http\Middleware\EnsureSameOrg::class,
            'platform' => \Modules\Auth\Http\Middleware\EnsurePlatformAdmin::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
