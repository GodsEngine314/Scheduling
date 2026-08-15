<?php

use App\Http\Middleware\AuthenticateWithAuthService;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth.service' => AuthenticateWithAuthService::class,
        ]);

        // The API deliberately gets NO session. One middleware serves both
        // surfaces and falls back to a session token for the console, so
        // starting a session on the API would let a logged-in browser
        // authenticate a state-changing API call with a cookie and no bearer
        // token — CSRF, by the back door. With no session, hasSession() is false
        // there and the Authorization header is the only way in.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
