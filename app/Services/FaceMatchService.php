<?php

namespace App\Services;

use App\FaceEngines\FaceEngine;
use App\FaceEngines\FaceEngineException;
use App\Models\Comparison;
use App\Support\ActivityLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class FaceMatchService
{
    public function __construct(
        private readonly FaceEngine $engine,
        private readonly ComparisonForwarder $forwarder,
        private readonly ActivityLog $log,
    ) {}

    /**
     * Guarda las imágenes, las compara, registra el resultado y lo reenvía si corresponde.
     *
     * @param  bool|null  $forward  true = enviar siempre, false = no enviar,
     *                              null = según FACE_FORWARD_ONLY_MATCHES.
     *
     * @throws FaceEngineException
     */
    public function compare(
        UploadedFile $source,
        UploadedFile $target,
        string $mode = Comparison::MODE_COMPARE,
        ?float $threshold = null,
        string $origin = 'web',
        ?bool $forward = null,
    ): Comparison {
        $start = hrtime(true);
        $threshold ??= (float) config('facematch.default_threshold', 80);
        $disk = Storage::disk(config('facematch.disk'));

        $sourcePath = $this->store($source);
        $targetPath = $this->store($target);

        try {
            $analysis = $this->engine->analyze(
                $disk->path($sourcePath),
                $disk->path($targetPath),
                $threshold,
                $mode === Comparison::MODE_VERIFY,
            );
        } catch (Throwable $e) {
            $disk->delete([$sourcePath, $targetPath]);

            $this->log->error('comparison.failed', 'Falló la comparación: '.$e->getMessage(), [
                'mode' => $mode,
                'origin' => $origin,
                'engine' => $this->engine->key(),
                'threshold' => $threshold,
                'source_name' => $source->getClientOriginalName(),
                'target_name' => $target->getClientOriginalName(),
                'error' => $e->getMessage(),
                'duration_ms' => $this->elapsed($start),
            ]);

            throw $e;
        }

        $comparison = Comparison::create([
            'mode' => $mode,
            'origin' => $origin,
            'engine' => $this->engine->key(),
            'source_path' => $sourcePath,
            'target_path' => $targetPath,
            'threshold' => $threshold,
            'similarity' => $analysis->similarity,
            'is_match' => $analysis->isMatch,
            'passed' => $analysis->passed,
            'failure_reasons' => $analysis->failureReasons,
            'result' => $analysis->result,
            'forward_status' => 'pending',
        ]);

        $this->log->info('comparison.created', sprintf(
            '%s %s: %s %% (umbral %s %%) · %s',
            $mode === Comparison::MODE_VERIFY ? 'Verificación' : 'Comparación',
            $origin === 'api' ? 'por API' : 'web',
            number_format($analysis->similarity, 2),
            number_format($threshold, 0),
            $comparison->successful() ? 'aprobada' : 'no aprobada',
        ), [
            'uuid' => $comparison->uuid,
            'mode' => $mode,
            'origin' => $origin,
            'engine' => $comparison->engine,
            'transport' => $comparison->resultValue('transport'),
            'similarity' => $analysis->similarity,
            'threshold' => $threshold,
            'is_match' => $analysis->isMatch,
            'passed' => $analysis->passed,
            'failure_reasons' => $analysis->failureReasons,
            'engine_ms' => $comparison->resultValue('elapsed_ms'),
            'duration_ms' => $this->elapsed($start),
            'source_name' => $source->getClientOriginalName(),
            'target_name' => $target->getClientOriginalName(),
        ]);

        if ($this->shouldForward($comparison, $forward)) {
            $this->forwarder->send($comparison);
        } else {
            $comparison->update(['forward_status' => 'skipped']);

            $this->log->write('debug', 'forward.skipped', 'Envío omitido: '.$this->skipReason($comparison, $forward), [
                'uuid' => $comparison->uuid,
            ]);
        }

        return $comparison;
    }

    public function delete(Comparison $comparison): void
    {
        Storage::disk(config('facematch.disk'))->delete([$comparison->source_path, $comparison->target_path]);
        $comparison->delete();

        $this->log->info('comparison.deleted', 'Comparación eliminada con sus imágenes', ['uuid' => $comparison->uuid]);
    }

    private function shouldForward(Comparison $comparison, ?bool $forward): bool
    {
        if (! $this->forwarder->isConfigured() || $forward === false) {
            return false;
        }

        if ($forward === true) {
            return true;
        }

        return ! config('facematch.forward.only_matches', true) || $comparison->successful();
    }

    private function skipReason(Comparison $comparison, ?bool $forward): string
    {
        return match (true) {
            ! $this->forwarder->isConfigured() => 'FACE_FORWARD_URL no está configurada.',
            $forward === false => 'se pidió no enviar.',
            default => 'el resultado no fue exitoso (FACE_FORWARD_ONLY_MATCHES=true).',
        };
    }

    private function elapsed(int $start): int
    {
        return (int) round((hrtime(true) - $start) / 1e6);
    }

    /** comparisons/AAAA/MM/uuid.ext en el disco public. */
    private function store(UploadedFile $file): string
    {
        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'jpg');
        $extension = $extension === 'jpeg' ? 'jpg' : $extension;

        return $file->storeAs(
            'comparisons/'.now()->format('Y/m'),
            Str::uuid().'.'.$extension,
            config('facematch.disk'),
        );
    }
}
