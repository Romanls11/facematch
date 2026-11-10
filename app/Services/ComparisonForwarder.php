<?php

namespace App\Services;

use App\Models\Comparison;
use App\Support\ActivityLog;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Envía el resultado y ambas imágenes al otro proyecto (POST multipart).
 *
 * Campos: source_image, target_image (archivos) y payload (JSON).
 * Cabeceras: Authorization: Bearer FACE_FORWARD_TOKEN y X-FaceMatch-Uuid.
 */
class ComparisonForwarder
{
    public function __construct(private readonly ActivityLog $log) {}

    public function isConfigured(): bool
    {
        return filled(config('facematch.forward.url'));
    }

    public function send(Comparison $comparison): Comparison
    {
        if (! $this->isConfigured()) {
            return $this->mark($comparison, 'skipped', null, 'FACE_FORWARD_URL no está configurada.');
        }

        $url = (string) config('facematch.forward.url');
        $disk = $comparison->disk();
        $start = hrtime(true);

        try {
            $request = Http::timeout((int) config('facematch.forward.timeout', 15))
                ->acceptJson()
                ->withHeaders(['X-FaceMatch-Uuid' => $comparison->uuid])
                ->attach('source_image', $disk->get($comparison->source_path), 'source.'.pathinfo($comparison->source_path, PATHINFO_EXTENSION))
                ->attach('target_image', $disk->get($comparison->target_path), 'target.'.pathinfo($comparison->target_path, PATHINFO_EXTENSION));

            if ($token = config('facematch.forward.token')) {
                $request = $request->withToken($token);
            }

            $response = $request->post($url, [
                'payload' => json_encode($this->payload($comparison), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            $status = $response->successful() ? 'sent' : 'failed';
            $context = [
                'uuid' => $comparison->uuid,
                'url' => $url,
                'http_code' => $response->status(),
                'duration_ms' => $this->elapsed($start),
                'response' => mb_strimwidth($response->body(), 0, 500, '…'),
            ];

            $status === 'sent'
                ? $this->log->info('forward.sent', "Resultado enviado al otro proyecto (HTTP {$response->status()})", $context)
                : $this->log->warning('forward.failed', "El otro proyecto respondió HTTP {$response->status()}", $context);

            return $this->mark($comparison, $status, $response->status(), $response->body());
        } catch (Throwable $e) {
            $this->log->error('forward.failed', 'No se pudo conectar con el otro proyecto: '.$e->getMessage(), [
                'uuid' => $comparison->uuid,
                'url' => $url,
                'duration_ms' => $this->elapsed($start),
                'error' => $e->getMessage(),
            ]);

            return $this->mark($comparison, 'failed', null, $e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    public function payload(Comparison $comparison): array
    {
        return [
            'uuid' => $comparison->uuid,
            'mode' => $comparison->mode,
            'origin' => $comparison->origin,
            'engine' => $comparison->engine,
            'is_match' => $comparison->is_match,
            'passed' => $comparison->passed,
            'similarity' => $comparison->similarity,
            'threshold' => $comparison->threshold,
            'failure_reasons' => $comparison->failure_reasons ?? [],
            'result' => $comparison->result,
            'compared_at' => $comparison->created_at?->toIso8601String(),
            // Las imágenes ya van adjuntas; estas URLs firmadas caducan (FACE_IMAGE_URL_TTL).
            'source_url' => $comparison->signedImageUrl('origen'),
            'target_url' => $comparison->signedImageUrl('comparada'),
            'image_urls_expire_at' => $comparison->imageUrlExpiresAt()->toIso8601String(),
        ];
    }

    private function mark(Comparison $comparison, string $status, ?int $code, ?string $response): Comparison
    {
        $comparison->forceFill([
            'forward_status' => $status,
            'forward_http_code' => $code,
            'forward_response' => $response !== null ? mb_strimwidth($response, 0, 2000, '…') : null,
            'forwarded_at' => $status === 'skipped' ? $comparison->forwarded_at : now(),
        ])->save();

        return $comparison;
    }

    private function elapsed(int $start): int
    {
        return (int) round((hrtime(true) - $start) / 1e6);
    }
}
