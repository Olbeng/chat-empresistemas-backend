<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Auth\AuthenticationException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Tymon\JWTAuth\Exceptions\JWTException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // API Middleware group
        $middleware->group('api', [
            \App\Http\Middleware\ForceJsonResponse::class,
            'throttle:60,1',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);

        // JWT Middleware aliases
        $middleware->alias([
            'jwt.auth' => \Tymon\JWTAuth\Http\Middleware\Authenticate::class,
            'jwt.refresh' => \Tymon\JWTAuth\Http\Middleware\RefreshToken::class,
            'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Manejar todos los errores de autenticación
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
                'error' => 'Token is required'
            ], 401);
        });

        // Manejar token expirado
        $exceptions->render(function (TokenExpiredException $e, Request $request) {
            return response()->json([
                'success' => false,
                'message' => 'Token has expired',
                'error' => 'Token expired'
            ], 401);
        });

        // Manejar token inválido
        $exceptions->render(function (TokenInvalidException $e, Request $request) {
            return response()->json([
                'success' => false,
                'message' => 'Token is invalid',
                'error' => 'Invalid token'
            ], 401);
        });

        // Manejar otros errores JWT
        $exceptions->render(function (JWTException $e, Request $request) {
            return response()->json([
                'success' => false,
                'message' => 'Token error',
                'error' => $e->getMessage()
            ], 401);
        });

        // Manejar cualquier otra excepción en rutas API
        $exceptions->render(function (\Exception $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $status = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'error' => class_basename($e)
                ], $status);
            }
        });
    })
    ->create();
