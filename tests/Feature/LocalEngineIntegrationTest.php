<?php

namespace Tests\Feature;

use App\FaceEngines\LocalFaceEngine;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Prueba real del motor local (Node + face-api), sin fakes.
 * Requiere `npm install`. Ejecutar con: php artisan test --group=integration
 */
#[Group('integration')]
class LocalEngineIntegrationTest extends TestCase
{
    private LocalFaceEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $node = (string) config('facematch.local.node_binary');
        if (! Process::run([$node, '--version'])->successful()) {
            $this->markTestSkipped("Node no está disponible ({$node}). Configura FACE_NODE_BINARY.");
        }

        // Fuerza el modo proceso para no depender del servicio.
        $this->engine = new LocalFaceEngine(['service_url' => null] + config('facematch.local'));
    }

    private function fixture(string $name): string
    {
        return base_path("tests/Fixtures/faces/{$name}");
    }

    public function test_misma_persona_coincide(): void
    {
        $analysis = $this->engine->analyze($this->fixture('person_a_1.jpg'), $this->fixture('person_a_2.jpg'), 80, false);

        $distance = $analysis->result['comparison']['distance'];
        $this->assertLessThan(0.45, $distance, "Distancia {$distance}");
        $this->assertTrue($analysis->isMatch);
        $this->assertGreaterThan(85, $analysis->similarity);
        $this->assertSame('process', $analysis->result['transport']);
    }

    public function test_personas_distintas_no_coinciden(): void
    {
        $analysis = $this->engine->analyze($this->fixture('person_a_1.jpg'), $this->fixture('person_b.jpg'), 80, false);

        $distance = $analysis->result['comparison']['distance'];
        $this->assertGreaterThan(0.6, $distance, "Distancia {$distance}");
        $this->assertFalse($analysis->isMatch);
        $this->assertLessThan(80, $analysis->similarity);
    }

    public function test_verificar_dos_retratos_nitidos(): void
    {
        $analysis = $this->engine->analyze($this->fixture('person_a_1.jpg'), $this->fixture('person_a_2.jpg'), 80, true);

        $this->assertSame('ssd', $analysis->result['images']['source']['detector']);
        $this->assertTrue($analysis->result['source']['is_real_human_face'], implode(' ', $analysis->result['source']['violations']));
        $this->assertTrue($analysis->result['target']['is_real_human_face'], implode(' ', $analysis->result['target']['violations']));
        $this->assertTrue($analysis->passed);
    }

    public function test_verificar_rechaza_la_foto_borrosa(): void
    {
        $analysis = $this->engine->analyze($this->fixture('person_a_1.jpg'), $this->fixture('blurry.jpg'), 80, true);

        $this->assertFalse($analysis->result['target']['checks']['sharpness_ok']);
        $this->assertFalse($analysis->passed);
        $this->assertStringContainsString('borrosa', implode(' ', $analysis->failureReasons));
    }
}
