<?php

namespace App\Models;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * @property int $id
 * @property string $uuid
 * @property string $mode
 * @property string $origin
 * @property string $engine
 * @property string $source_path
 * @property string $target_path
 * @property float $threshold
 * @property float $similarity
 * @property bool $is_match
 * @property bool $passed
 * @property array|null $failure_reasons
 * @property array|null $result
 * @property string $forward_status
 * @property int|null $forward_http_code
 * @property string|null $forward_response
 * @property Carbon|null $forwarded_at
 */
class Comparison extends Model
{
    use HasUuids;

    public const MODE_COMPARE = 'compare';

    public const MODE_VERIFY = 'verify';

    public const ENGINE_LABELS = [
        'local' => 'Local · face-api',
        'rekognition' => 'Amazon Rekognition',
    ];

    protected $fillable = [
        'mode', 'origin', 'engine', 'source_path', 'target_path', 'threshold',
        'similarity', 'is_match', 'passed', 'failure_reasons', 'result',
        'forward_status', 'forward_http_code', 'forward_response', 'forwarded_at',
    ];

    protected function casts(): array
    {
        return [
            'threshold' => 'float',
            'similarity' => 'float',
            'is_match' => 'boolean',
            'passed' => 'boolean',
            'failure_reasons' => 'array',
            'result' => 'array',
            'forward_http_code' => 'integer',
            'forwarded_at' => 'datetime',
        ];
    }

    /** El UUID se genera en la columna `uuid`; la clave primaria sigue siendo `id`. */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** Lados válidos de la ruta de imágenes. */
    public const IMAGE_SIDES = ['origen', 'comparada'];

    /** URL de la imagen de origen para la web (requiere sesión). */
    public function sourceUrl(): string
    {
        return route('comparisons.image', [$this, 'origen']);
    }

    /** URL de la imagen comparada para la web (requiere sesión). */
    public function targetUrl(): string
    {
        return route('comparisons.image', [$this, 'comparada']);
    }

    /**
     * URL firmada y temporal, válida sin sesión (API y otro proyecto).
     * Caduca a los FACE_IMAGE_URL_TTL minutos.
     */
    public function signedImageUrl(string $side): string
    {
        return URL::temporarySignedRoute('comparisons.image.signed', $this->imageUrlExpiresAt(), [$this, $side]);
    }

    public function imageUrlExpiresAt(): Carbon
    {
        return now()->addMinutes(max(1, (int) config('facematch.image_url_ttl', 1440)))->startOfMinute();
    }

    /** Ruta en el disco de la imagen indicada («origen» o «comparada»). */
    public function imagePath(string $side): string
    {
        return $side === 'origen' ? $this->source_path : $this->target_path;
    }

    public function disk(): Filesystem
    {
        return Storage::disk(config('facematch.disk'));
    }

    /** @return array{left: float, top: float, width: float, height: float}|null */
    public function sourceBox(): ?array
    {
        return $this->box('comparison.source_face.bounding_box');
    }

    /** @return array{left: float, top: float, width: float, height: float}|null */
    public function targetBox(): ?array
    {
        return $this->box('comparison.matched_face.bounding_box')
            ?? $this->box('target.primary_face.bounding_box');
    }

    /** Los recuadros simulados de Rekognition no corresponden a las imágenes reales. */
    public function hasRealBoxes(): bool
    {
        return ! Arr::get($this->result ?? [], 'simulated', false)
            && ($this->sourceBox() !== null || $this->targetBox() !== null);
    }

    public function engineLabel(): string
    {
        $label = self::ENGINE_LABELS[$this->engine] ?? ucfirst($this->engine);

        return Arr::get($this->result ?? [], 'simulated') ? $label.' (simulado)' : $label;
    }

    public function successful(): bool
    {
        return $this->passed;
    }

    public function isVerify(): bool
    {
        return $this->mode === self::MODE_VERIFY;
    }

    public function fromService(): bool
    {
        return Arr::get($this->result ?? [], 'transport') === 'service';
    }

    public function resultValue(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->result ?? [], $key, $default);
    }

    private function box(string $key): ?array
    {
        $box = Arr::get($this->result ?? [], $key);

        if (! is_array($box) || ! isset($box['width'], $box['height'], $box['left'], $box['top'])) {
            return null;
        }

        return [
            'left' => (float) $box['left'],
            'top' => (float) $box['top'],
            'width' => (float) $box['width'],
            'height' => (float) $box['height'],
        ];
    }
}
