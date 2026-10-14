<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureCandidate;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(null);
        $middleware->alias([
            'admin' => EnsureAdmin::class,
            'candidate' => EnsureCandidate::class,
        ]);

        // Check the role before route model binding, so the wrong account type gets 403
        // rather than learning which record IDs exist from 404s.
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureAdmin::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureCandidate::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
