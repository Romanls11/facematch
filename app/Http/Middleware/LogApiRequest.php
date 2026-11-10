<?php

namespace App\Http\Middleware;

use App\Support\ActivityLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Registra cada llamada a la API: método, ruta, estado, duración e IP. */
class LogApiRequest
{
    public function __construct(private readonly ActivityLog $log) {}

    public function handle(Request $request, Closure $next): Response
    {
        $start = hrtime(true);

        $response = $next($request);

        $status = $response->getStatusCode();
        $level = match (true) {
            $status >= 500 => 'error',
            $status >= 400 => 'warning',
            default => 'info',
        };

        $context = [
            'method' => $request->method(),
            'path' => '/'.$request->path(),
            'status' => $status,
            'duration_ms' => (int) round((hrtime(true) - $start) / 1e6),
            'ip' => $request->ip(),
            'user_agent' => mb_strimwidth((string) $request->userAgent(), 0, 160, '…'),
        ];

        $body = json_decode((string) $response->getContent(), true);

        if (is_array($body)) {
            if ($status >= 400 && is_string($body['message'] ?? null)) {
                $context['error'] = mb_strimwidth($body['message'], 0, 300, '…');
            }

            if (is_string($body['data']['uuid'] ?? null)) {
                $context['uuid'] = $body['data']['uuid'];
            }
        }

        $this->log->write($level, 'api.request', "{$request->method()} /{$request->path()} → {$status}", $context);

        return $response;
    }
}
