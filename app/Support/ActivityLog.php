<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Registro de actividad de FaceMatch.
 *
 * Escribe una línea JSON por evento en el canal «facematch»
 * (storage/logs/facematch-AAAA-MM-DD.log) y permite leerlas para /registros.
 * Un fallo al escribir nunca interrumpe una comparación.
 */
class ActivityLog
{
    /** Grupos de eventos que se muestran como filtro. */
    public const GROUPS = [
        'comparison' => 'Comparaciones',
        'forward' => 'Envíos',
        'api' => 'API',
        'engine' => 'Motor',
        'auth' => 'Accesos',
    ];

    public const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    /** @param  array<string, mixed>  $context */
    public function info(string $event, string $message, array $context = []): void
    {
        $this->write('info', $event, $message, $context);
    }

    /** @param  array<string, mixed>  $context */
    public function warning(string $event, string $message, array $context = []): void
    {
        $this->write('warning', $event, $message, $context);
    }

    /** @param  array<string, mixed>  $context */
    public function error(string $event, string $message, array $context = []): void
    {
        $this->write('error', $event, $message, $context);
    }

    /** @param  array<string, mixed>  $context */
    public function write(string $level, string $event, string $message, array $context = []): void
    {
        $channel = $this->channel();

        if ($channel === null) {
            return;
        }

        $context = ['event' => $event] + $context;

        if ($requestId = Context::getHidden('request_id')) {
            $context['request_id'] = $requestId;
        }

        try {
            Log::channel($channel)->log($level, $message, $context);
        } catch (Throwable) {
            // El registro es auxiliar: nunca debe romper la operación principal.
        }
    }

    public function enabled(): bool
    {
        return $this->channel() !== null;
    }

    /**
     * Fechas (AAAA-MM-DD) con archivo de registro, de la más reciente a la más antigua.
     *
     * @return list<string>
     */
    public function dates(): array
    {
        $dates = [];

        foreach (glob($this->directory().DIRECTORY_SEPARATOR.$this->prefix().'-*.log') ?: [] as $file) {
            if (preg_match('/-(\d{4}-\d{2}-\d{2})\.log$/', $file, $m)) {
                $dates[] = $m[1];
            }
        }

        rsort($dates);

        return $dates;
    }

    /**
     * Entradas de un día, de la más reciente a la más antigua.
     *
     * @param  array{level?: ?string, group?: ?string, q?: ?string}  $filters
     * @return array{entries: list<array<string, mixed>>, total: int, truncated: bool}
     */
    public function read(string $date, array $filters = [], int $limit = 300): array
    {
        $file = $this->directory().DIRECTORY_SEPARATOR.$this->prefix()."-{$date}.log";

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! is_file($file)) {
            return ['entries' => [], 'total' => 0, 'truncated' => false];
        }

        $level = $filters['level'] ?? null;
        $group = $filters['group'] ?? null;
        $q = mb_strtolower(trim((string) ($filters['q'] ?? '')));

        $entries = [];
        $total = 0;

        foreach (array_reverse(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) as $line) {
            $row = json_decode($line, true);

            if (! is_array($row)) {
                continue;
            }

            $entry = $this->normalize($row);

            if ($level && $entry['level'] !== $level) {
                continue;
            }

            if ($group && ! str_starts_with($entry['event'], $group.'.')) {
                continue;
            }

            if ($q !== '' && ! str_contains(mb_strtolower($line), $q)) {
                continue;
            }

            $total++;

            if (count($entries) < $limit) {
                $entries[] = $entry;
            }
        }

        return ['entries' => $entries, 'total' => $total, 'truncated' => $total > $limit];
    }

    /**
     * Resumen del día para las tarjetas de /registros.
     *
     * @return array{total: int, errors: int, warnings: int, comparisons: int, forwards: int, api: int}
     */
    public function summary(string $date): array
    {
        $all = $this->read($date, [], PHP_INT_MAX)['entries'];

        $count = fn (callable $fn) => count(array_filter($all, $fn));

        return [
            'total' => count($all),
            'errors' => $count(fn ($e) => in_array($e['level'], ['error', 'critical', 'alert', 'emergency'], true)),
            'warnings' => $count(fn ($e) => $e['level'] === 'warning'),
            'comparisons' => $count(fn ($e) => $e['event'] === 'comparison.created'),
            'forwards' => $count(fn ($e) => $e['event'] === 'forward.sent'),
            'api' => $count(fn ($e) => $e['event'] === 'api.request'),
        ];
    }

    /**
     * @param  array<string, mixed>  $row  Línea del JsonFormatter de Monolog.
     * @return array<string, mixed>
     */
    private function normalize(array $row): array
    {
        $context = is_array($row['context'] ?? null) ? $row['context'] : [];
        $event = (string) ($context['event'] ?? 'otro');
        $requestId = $context['request_id'] ?? null;
        unset($context['event'], $context['request_id']);

        return [
            'time' => isset($row['datetime']) ? Carbon::parse($row['datetime'])->setTimezone(config('app.timezone')) : null,
            'level' => strtolower((string) ($row['level_name'] ?? 'info')),
            'event' => $event,
            'message' => (string) ($row['message'] ?? ''),
            'uuid' => $context['uuid'] ?? null,
            'request_id' => $requestId,
            'context' => $context,
        ];
    }

    private function channel(): ?string
    {
        $channel = config('facematch.log.channel');

        return blank($channel) || $channel === 'null' ? null : (string) $channel;
    }

    private function directory(): string
    {
        return dirname($this->basePath());
    }

    private function prefix(): string
    {
        return pathinfo($this->basePath(), PATHINFO_FILENAME);
    }

    private function basePath(): string
    {
        return (string) config('logging.channels.'.($this->channel() ?? 'facematch').'.path', storage_path('logs/facematch.log'));
    }
}
