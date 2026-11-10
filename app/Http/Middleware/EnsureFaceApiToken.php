<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Protege la API con Authorization: Bearer FACE_API_TOKEN. */
class EnsureFaceApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('facematch.api_token');

        if ($expected === '') {
            return response()->json([
                'message' => 'La API no está habilitada: configura FACE_API_TOKEN en el archivo .env.',
            ], 503);
        }

        $given = (string) $request->bearerToken();

        if ($given === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Token inválido o ausente.'], 401);
        }

        return $next($request);
    }
}
