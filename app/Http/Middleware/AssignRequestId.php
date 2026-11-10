<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Asigna un identificador a cada petición (o respeta el X-Request-Id recibido)
 * para relacionar las líneas del registro y devolverlo en la respuesta.
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $given = (string) $request->header('X-Request-Id');
        $id = preg_match('/^[A-Za-z0-9._-]{8,64}$/', $given) ? $given : (string) Str::uuid();

        Context::addHidden('request_id', $id);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
