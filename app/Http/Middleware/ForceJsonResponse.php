<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ForceJsonResponse
{
    public function handle(Request $request, Closure $next)
    {
        // Forzar que la petición acepte JSON
        $request->headers->set('Accept', 'application/json');

        // Obtener la respuesta
        $response = $next($request);

        // Si la respuesta no es JSON, convertirla
        if (!$response->headers->has('Content-Type')) {
            $response->headers->set('Content-Type', 'application/json');
        }

        return $response;
    }
}
