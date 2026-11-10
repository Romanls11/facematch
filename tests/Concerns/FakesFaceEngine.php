<?php

namespace Tests\Concerns;

use Illuminate\Http\UploadedFile;

trait FakesFaceEngine
{
    /** Salida de scripts/face-engine.cjs (CLI o servicio). */
    protected function engineOutput(?float $distance = 0.25, array $sourceFace = [], array $targetFace = [], string $detector = 'tiny'): array
    {
        $face = fn (array $overrides) => array_replace_recursive([
            'confidence' => 99.5,
            'bounding_box' => ['width' => 0.3, 'height' => 0.4, 'left' => 0.35, 'top' => 0.2, 'area_percentage' => 12.0],
            'pose' => ['pitch' => 2.0, 'roll' => -1.0, 'yaw' => 3.0],
            'quality' => ['sharpness' => 60.0, 'brightness' => 55.0],
            'eyes_open' => ['value' => true, 'ear' => 0.29],
        ], $overrides);

        $faces = fn (array $overrides) => $overrides === ['none' => true] ? [] : [$face($overrides)];

        return [
            'ok' => true,
            'engine' => 'face-api/tfjs-wasm',
            'elapsed_ms' => 210,
            'distance' => $distance,
            'match_index' => $distance === null ? null : 0,
            'source' => ['width' => 640, 'height' => 800, 'detector' => $detector, 'faces' => $faces($sourceFace)],
            'target' => ['width' => 640, 'height' => 800, 'detector' => $detector, 'faces' => $faces($targetFace)],
        ];
    }

    protected function fakeImage(string $name = 'rostro.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 400, 500);
    }
}
