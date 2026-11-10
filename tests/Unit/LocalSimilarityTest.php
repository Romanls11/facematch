<?php

namespace Tests\Unit;

use App\FaceEngines\LocalFaceEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LocalSimilarityTest extends TestCase
{
    public static function distances(): array
    {
        return [
            'idénticos' => [0.0, 100.0],
            'misma persona' => [0.3, 90.0],
            'corte D = 80 %' => [0.6, 80.0],
            'distintas' => [0.8, 40.0],
            'límite inferior' => [1.0, 0.0],
            'más allá del límite' => [1.4, 0.0],
            'sin rostro' => [null, 0.0],
        ];
    }

    #[DataProvider('distances')]
    public function test_convierte_distancia_en_similitud(?float $distance, float $expected): void
    {
        $engine = new LocalFaceEngine(['match_distance' => 0.6]);

        $this->assertEqualsWithDelta($expected, $engine->similarityFromDistance($distance), 0.01);
    }

    public function test_el_corte_es_configurable(): void
    {
        $engine = new LocalFaceEngine(['match_distance' => 0.5]);

        $this->assertEqualsWithDelta(80.0, $engine->similarityFromDistance(0.5), 0.01);
        $this->assertEqualsWithDelta(90.0, $engine->similarityFromDistance(0.25), 0.01);
    }
}
