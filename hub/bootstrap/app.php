<?php

use App\Http\Middleware\ResolveWorkspace;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::group([], base_path('routes/ingest.php'));
            // Endpoints públicos de cada módulo (enlaces rastreados, bajas, preferencias,
            // webhooks de proveedores): sin sesión ni CSRF, protegidos por token o firma.
            foreach (glob(base_path('routes/public/*.php')) ?: [] as $file) {
                Route::group([], $file);
            }
        },
    )
    // Tiempo real (Reverb, hub/CLAUDE.md §13): la SPA autoriza los canales privados con su
    // sesión de Sanctum en /api/broadcasting/auth (statefulApi pone sesión y CSRF).
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->alias(['workspace' => ResolveWorkspace::class]);
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('v1/*') || $request->is('auth/*') || $request->expectsJson(),
        );
    })->create();
