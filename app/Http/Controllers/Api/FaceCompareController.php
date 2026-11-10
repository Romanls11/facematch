<?php

namespace App\Http\Controllers\Api;

use App\FaceEngines\FaceEngine;
use App\FaceEngines\FaceEngineException;
use App\FaceEngines\LocalFaceEngine;
use App\Http\Controllers\Controller;
use App\Http\Requests\CompareFacesRequest;
use App\Models\Comparison;
use App\Services\ComparisonForwarder;
use App\Services\FaceMatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FaceCompareController extends Controller
{
    /** GET /api/face/health — estado del motor y del envío. */
    public function health(FaceEngine $engine, ComparisonForwarder $forwarder): JsonResponse
    {
        $service = $engine instanceof LocalFaceEngine ? $engine->serviceStatus() : null;

        return response()->json([
            'ok' => true,
            'engine' => [
                'key' => $engine->key(),
                'label' => $engine->label(),
                'simulated' => $engine->isSimulated(),
                // fast = servicio persistente activo; slow = se lanza un proceso por análisis.
                'speed' => $service === null ? 'cloud' : ($service['available'] ? 'fast' : 'slow'),
            ],
            'forward' => [
                'configured' => $forwarder->isConfigured(),
                'only_matches' => (bool) config('facematch.forward.only_matches', true),
            ],
            'defaults' => [
                'threshold' => (float) config('facematch.default_threshold', 80),
                'max_upload_kb' => 5120,
            ],
            'rate_limit_per_minute' => (int) config('facematch.api_rate_limit', 60),
            'time' => now()->toIso8601String(),
        ]);
    }

    /** POST /api/face/compare */
    public function store(CompareFacesRequest $request, FaceMatchService $service): JsonResponse
    {
        try {
            $comparison = $service->compare(
                $request->file('source_image'),
                $request->file('target_image'),
                $request->mode(),
                $request->threshold(),
                'api',
                $request->forward(),
            );
        } catch (FaceEngineException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json(['data' => $this->present($comparison)], 201);
    }

    /** GET /api/face/comparisons — listado paginado con filtros opcionales. */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'mode' => ['nullable', 'in:compare,verify'],
            'origin' => ['nullable', 'in:web,api'],
            'passed' => ['nullable', 'boolean'],
            'forward_status' => ['nullable', 'in:pending,sent,failed,skipped'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ], [
            'mode.in' => 'El modo debe ser «compare» o «verify».',
            'origin.in' => 'El origen debe ser «web» o «api».',
            'passed.boolean' => 'passed debe ser 1/0 o true/false.',
            'forward_status.in' => 'forward_status debe ser pending, sent, failed o skipped.',
            'per_page.max' => 'per_page no puede ser mayor a 100.',
            'per_page.*' => 'per_page debe ser un número entre 1 y 100.',
        ]);

        $page = Comparison::query()
            ->when($filters['mode'] ?? null, fn ($q, $v) => $q->where('mode', $v))
            ->when($filters['origin'] ?? null, fn ($q, $v) => $q->where('origin', $v))
            ->when(isset($filters['passed']), fn ($q) => $q->where('passed', $request->boolean('passed')))
            ->when($filters['forward_status'] ?? null, fn ($q, $v) => $q->where('forward_status', $v))
            ->latest()
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();

        return response()->json([
            'data' => $page->getCollection()->map(fn (Comparison $c) => $this->present($c, withResult: false))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
            'links' => [
                'next' => $page->nextPageUrl(),
                'prev' => $page->previousPageUrl(),
            ],
        ]);
    }

    /** GET /api/face/comparisons/{uuid} */
    public function show(Comparison $comparison): JsonResponse
    {
        return response()->json(['data' => $this->present($comparison)]);
    }

    /** @return array<string, mixed> */
    private function present(Comparison $comparison, bool $withResult = true): array
    {
        $data = [
            'uuid' => $comparison->uuid,
            'mode' => $comparison->mode,
            'origin' => $comparison->origin,
            'engine' => $comparison->engine,
            'is_match' => $comparison->is_match,
            'passed' => $comparison->passed,
            'similarity' => $comparison->similarity,
            'threshold' => $comparison->threshold,
            'failure_reasons' => $comparison->failure_reasons ?? [],
            'forward' => [
                'status' => $comparison->forward_status,
                'http_code' => $comparison->forward_http_code,
                'forwarded_at' => $comparison->forwarded_at?->toIso8601String(),
            ],
            // URLs firmadas: funcionan sin sesión y caducan (FACE_IMAGE_URL_TTL).
            'source_url' => $comparison->signedImageUrl('origen'),
            'target_url' => $comparison->signedImageUrl('comparada'),
            'image_urls_expire_at' => $comparison->imageUrlExpiresAt()->toIso8601String(),
            'result' => $comparison->result,
            'compared_at' => $comparison->created_at?->toIso8601String(),
            'links' => [
                'self' => route('api.face.comparisons.show', $comparison),
                'web' => route('comparisons.show', $comparison),
            ],
        ];

        if (! $withResult) {
            unset($data['result']);
        }

        return $data;
    }
}
