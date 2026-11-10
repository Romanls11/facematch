<?php

namespace App\FaceEngines;

use App\Support\ActivityLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Motor local gratuito: face-api + TensorFlow.js (WASM) ejecutado en Node.
 *
 * Primero intenta el servicio persistente (php artisan face:serve); si no hay
 * conexión ejecuta scripts/face-engine.cjs como proceso.  El resultado tiene
 * un formato compatible con el de Rekognition.
 */
class LocalFaceEngine implements FaceEngine
{
    /** Tramo de distancia, por encima de D, en el que la similitud baja de 80 % a 0 %. */
    private const DISTANCE_SPAN = 0.4;

    /**
     * @param  array<string, mixed>  $config  config('facematch.local')
     */
    public function __construct(private readonly array $config) {}

    public function key(): string
    {
        return 'local';
    }

    public function label(): string
    {
        return 'Local · face-api';
    }

    public function isSimulated(): bool
    {
        return false;
    }

    public function analyze(string $sourcePath, string $targetPath, float $threshold, bool $verify): FaceAnalysis
    {
        $detector = $verify ? 'ssd' : ($this->config['detector'] ?? 'tiny');

        [$raw, $transport] = $this->run($sourcePath, $targetPath, $detector);

        return $this->buildAnalysis($raw, $transport, $threshold, $verify);
    }

    /**
     * Estado del servicio persistente para la interfaz.
     *
     * @return array{available: bool, url: ?string, workers?: int, models_load_ms?: int, handled?: int}
     */
    public function serviceStatus(): array
    {
        $url = $this->serviceUrl();

        if ($url === null) {
            return ['available' => false, 'url' => null];
        }

        try {
            $response = Http::connectTimeout(0.5)->timeout(1)->acceptJson()->get($url.'/health');

            if ($response->successful() && $response->json('ok')) {
                return [
                    'available' => true,
                    'url' => $url,
                    'workers' => (int) $response->json('workers'),
                    'models_load_ms' => (int) $response->json('models_load_ms'),
                    'handled' => (int) $response->json('handled'),
                ];
            }
        } catch (Throwable) {
            // El servicio no está corriendo.
        }

        return ['available' => false, 'url' => $url];
    }

    /** Distancia euclidiana → similitud (0–100). D equivale al 80 %. */
    public function similarityFromDistance(?float $distance): float
    {
        if ($distance === null) {
            return 0.0;
        }

        $d = max(0.0, $distance);
        $cut = (float) ($this->config['match_distance'] ?? 0.6);

        $similarity = $d <= $cut
            ? 100 - ($d / $cut) * 20
            : 80 - (($d - $cut) / self::DISTANCE_SPAN) * 80;

        return round(max(0.0, min(100.0, $similarity)), 2);
    }

    // ------------------------------------------------------------------
    // Transporte: servicio HTTP o proceso
    // ------------------------------------------------------------------

    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function run(string $source, string $target, string $detector): array
    {
        $url = $this->serviceUrl();

        if ($url !== null) {
            try {
                $response = Http::connectTimeout(1)
                    ->timeout((int) ($this->config['service_timeout'] ?? 60))
                    ->acceptJson()
                    ->withHeaders(['X-Face-Token' => (string) $this->config['service_token']])
                    ->post($url.'/compare', [
                        'source' => $source,
                        'target' => $target,
                        'detector' => $detector,
                    ]);
            } catch (ConnectionException $e) {
                $response = null; // El servicio no está corriendo: se usa el proceso.

                app(ActivityLog::class)->warning(
                    'engine.fallback',
                    'El servicio facial no responde; se usa el modo lento (proceso). Ejecuta php artisan face:serve.',
                    ['service_url' => $url, 'error' => mb_strimwidth($e->getMessage(), 0, 200, '…')],
                );
            }

            if ($response !== null) {
                $json = $response->json();

                if ($response->failed() || ! is_array($json) || ! ($json['ok'] ?? false)) {
                    $error = is_array($json) ? ($json['error'] ?? null) : null;

                    throw new FaceEngineException(
                        'El servicio facial respondió con un error: '.($error ?? 'HTTP '.$response->status())
                    );
                }

                return [$json, 'service'];
            }
        }

        return [$this->runProcess($source, $target, $detector), 'process'];
    }

    /** @return array<string, mixed> */
    private function runProcess(string $source, string $target, string $detector): array
    {
        $result = Process::path(base_path())
            ->timeout((int) ($this->config['process_timeout'] ?? 120))
            ->run([
                (string) ($this->config['node_binary'] ?? 'node'),
                (string) $this->config['script'],
                $source,
                $target,
                $detector,
            ]);

        $json = json_decode(trim($result->output()), true);

        if (! $result->successful() || ! is_array($json) || ! ($json['ok'] ?? false)) {
            $error = is_array($json) ? ($json['error'] ?? null) : null;
            $error ??= trim($result->errorOutput()) ?: 'código de salida '.$result->exitCode();

            throw new FaceEngineException('El motor facial local falló: '.mb_strimwidth($error, 0, 500, '…'));
        }

        return $json;
    }

    private function serviceUrl(): ?string
    {
        $url = rtrim((string) ($this->config['service_url'] ?? ''), '/');

        return $url !== '' && ! empty($this->config['service_token']) ? $url : null;
    }

    // ------------------------------------------------------------------
    // Construcción del resultado (formato compatible con Rekognition)
    // ------------------------------------------------------------------

    /** @param  array<string, mixed>  $raw */
    private function buildAnalysis(array $raw, string $transport, float $threshold, bool $verify): FaceAnalysis
    {
        $sourceFaces = $raw['source']['faces'] ?? [];
        $targetFaces = $raw['target']['faces'] ?? [];
        $distance = isset($raw['distance']) ? (float) $raw['distance'] : null;
        $matchIndex = $raw['match_index'] ?? null;

        $similarity = $this->similarityFromDistance($distance);
        $isMatch = $distance !== null && $similarity >= $threshold;

        $sourceFace = $sourceFaces[0] ?? null;
        $matchedFace = $matchIndex !== null ? ($targetFaces[$matchIndex] ?? null) : null;

        $reasons = [];
        if ($sourceFace === null) {
            $reasons[] = 'No se detectó ningún rostro en la imagen de origen.';
        }
        if ($targetFaces === []) {
            $reasons[] = 'No se detectó ningún rostro en la imagen a comparar.';
        }

        $result = [
            'engine' => $this->key(),
            'library' => $raw['engine'] ?? 'face-api',
            'simulated' => false,
            'mode' => $verify ? 'verify' : 'compare',
            'transport' => $transport,
            'elapsed_ms' => (int) ($raw['elapsed_ms'] ?? 0),
            'images' => [
                'source' => $this->imageInfo($raw['source'] ?? []),
                'target' => $this->imageInfo($raw['target'] ?? []),
            ],
            'comparison' => [
                'is_match' => $isMatch,
                'similarity' => $similarity,
                'distance' => $distance,
                'match_distance' => (float) ($this->config['match_distance'] ?? 0.6),
                'confidence' => (float) ($matchedFace['confidence'] ?? 0),
                'threshold' => $threshold,
                'source_face' => $sourceFace,
                'matched_face' => $matchedFace,
                'unmatched_count' => max(0, count($targetFaces) - ($matchedFace ? 1 : 0)),
            ],
        ];

        $passed = $isMatch;

        if ($verify) {
            $result['source'] = $this->realness($sourceFaces);
            $result['target'] = $this->realness($targetFaces);

            foreach (['source' => 'origen', 'target' => 'a comparar'] as $key => $label) {
                if (! $result[$key]['is_real_human_face'] && $result[$key]['face_count'] > 0) {
                    $reasons[] = "La imagen {$label} no superó la verificación: ".implode(' ', $result[$key]['violations']);
                }
            }

            $passed = $isMatch && $result['source']['is_real_human_face'] && $result['target']['is_real_human_face'];
        }

        if (! $isMatch && $distance !== null) {
            $reasons[] = sprintf(
                'Los rostros no coinciden: la similitud de %s %% está por debajo del umbral de %s %%.',
                $this->num($similarity),
                $this->num($threshold),
            );
        }

        $result['passed'] = $passed;
        $result['failure_reasons'] = $reasons;

        return new FaceAnalysis($similarity, $isMatch, $passed, $reasons, $result);
    }

    /** @param  array<string, mixed>  $image */
    private function imageInfo(array $image): array
    {
        return [
            'width' => (int) ($image['width'] ?? 0),
            'height' => (int) ($image['height'] ?? 0),
            'detector' => $image['detector'] ?? null,
            'face_count' => count($image['faces'] ?? []),
        ];
    }

    /**
     * Verificación de rostro real y calidad (equivalente a verifyRealHumanFace de Rekognition).
     *
     * @param  list<array<string, mixed>>  $faces
     */
    private function realness(array $faces): array
    {
        $limits = $this->config['limits'] ?? [];
        $face = $faces[0] ?? null;
        $count = count($faces);

        $confidence = (float) ($face['confidence'] ?? 0);
        $sharpness = (float) ($face['quality']['sharpness'] ?? 0);
        $brightness = (float) ($face['quality']['brightness'] ?? 0);
        $yaw = (float) ($face['pose']['yaw'] ?? 0);
        $pitch = (float) ($face['pose']['pitch'] ?? 0);
        $roll = (float) ($face['pose']['roll'] ?? 0);
        $ear = (float) ($face['eyes_open']['ear'] ?? 0);
        $area = (float) ($face['bounding_box']['area_percentage'] ?? 0);

        $min = fn (string $key, float $default) => (float) ($limits[$key] ?? $default);

        $checks = [
            'human_detected' => $face !== null && $confidence >= $min('min_confidence', 90),
            'single_face' => ($limits['strict_single_face'] ?? true) ? $count === 1 : $count >= 1,
            'sharpness_ok' => $face !== null && $sharpness >= $min('min_sharpness', 8),
            'brightness_ok' => $face !== null && $brightness >= $min('min_brightness', 20) && $brightness <= $min('max_brightness', 90),
            'pose_frontal' => $face !== null && abs($yaw) <= $min('max_yaw', 35) && abs($pitch) <= $min('max_pitch', 35) && abs($roll) <= $min('max_roll', 25),
            'eyes_open' => $face !== null && (! ($limits['require_eyes_open'] ?? true) || (bool) ($face['eyes_open']['value'] ?? false)),
            // Aproximación: con lentes oscuros o cubrebocas los puntos de los ojos dejan de ser fiables.
            'no_occlusion' => $face !== null && $ear >= 0.12 && $confidence >= 70,
            'min_face_size_ok' => $face !== null && $area >= $min('min_face_area', 1.5),
        ];

        $violations = [];
        if ($face === null) {
            $violations[] = 'No se detectó ningún rostro humano.';
        } else {
            if (! $checks['human_detected']) {
                $violations[] = "Confianza de detección baja ({$this->num($confidence)} % < {$this->num($min('min_confidence', 90))} %).";
            }
            if (! $checks['single_face']) {
                $violations[] = "Se detectaron {$count} rostros; se requiere exactamente uno.";
            }
            if (! $checks['sharpness_ok']) {
                $violations[] = "La imagen está borrosa (nitidez {$this->num($sharpness)} < {$this->num($min('min_sharpness', 8))}).";
            }
            if (! $checks['brightness_ok']) {
                $violations[] = "Brillo fuera de rango ({$this->num($brightness)}; permitido {$this->num($min('min_brightness', 20))}–{$this->num($min('max_brightness', 90))}).";
            }
            if (! $checks['pose_frontal']) {
                $violations[] = "El rostro no está de frente (yaw {$this->num($yaw)}°, pitch {$this->num($pitch)}°, roll {$this->num($roll)}°).";
            }
            if (! $checks['eyes_open']) {
                $violations[] = "Los ojos parecen cerrados (EAR {$this->num($ear, 3)}).";
            }
            if (! $checks['no_occlusion']) {
                $violations[] = 'El rostro parece cubierto (lentes oscuros, cubrebocas u otro objeto).';
            }
            if (! $checks['min_face_size_ok']) {
                $violations[] = "El rostro es demasiado pequeño ({$this->num($area)} % de la imagen < {$this->num($min('min_face_area', 1.5))} %).";
            }
        }

        $passedChecks = count(array_filter($checks));
        $qualityScore = $face === null ? 0.0 : round(0.4 * $confidence + 0.6 * ($passedChecks / count($checks) * 100), 1);

        return [
            'is_real_human_face' => $violations === [],
            'confidence' => $confidence,
            'quality_score' => $qualityScore,
            'face_count' => $count,
            'primary_face' => $face,
            'checks' => $checks,
            'violations' => $violations,
            'passed_all_checks' => $violations === [],
        ];
    }

    private function num(float $value, int $decimals = 1): string
    {
        return rtrim(rtrim(number_format($value, $decimals, '.', ''), '0'), '.');
    }
}
