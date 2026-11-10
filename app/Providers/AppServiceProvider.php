<?php

namespace App\Providers;

use App\FaceEngines\FaceEngine;
use App\FaceEngines\LocalFaceEngine;
use App\FaceEngines\RekognitionFaceEngine;
use App\Support\ActivityLog;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ActivityLog::class);

        // El motor se elige con FACE_ENGINE (local | rekognition).
        $this->app->bind(FaceEngine::class, function ($app) {
            $engine = config('facematch.engine', 'local');

            return match ($engine) {
                'local' => new LocalFaceEngine(config('facematch.local')),
                'rekognition' => new RekognitionFaceEngine,
                default => throw new InvalidArgumentException("Motor facial desconocido: «{$engine}». Usa local o rekognition."),
            };
        });
    }

    public function boot(): void
    {
        Paginator::useBootstrapFive();

        // Límite de la API por token (o por IP si no envían token).
        RateLimiter::for('face-api', function (Request $request) {
            $key = $request->bearerToken() ? 'token:'.sha1($request->bearerToken()) : 'ip:'.$request->ip();

            return Limit::perMinute(max(1, (int) config('facematch.api_rate_limit', 60)))
                ->by($key)
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'Demasiadas peticiones. Intenta de nuevo en '.($headers['Retry-After'] ?? 60).' segundos.',
                ], 429, $headers));
        });

        View::composer(['layouts.app', 'comparisons.*'], function ($view) {
            $engine = $this->app->make(FaceEngine::class);

            $view->with('faceEngine', [
                'key' => $engine->key(),
                'label' => $engine->label(),
                'simulated' => $engine->isSimulated(),
            ]);
        });
    }
}
