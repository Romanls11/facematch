<?php

namespace Tests\Feature;

use App\FaceEngines\FaceEngine;
use App\FaceEngines\RekognitionFaceEngine;
use App\Models\Comparison;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Olorunda\RekognitionFace\Facades\RekognitionFace;
use Tests\Concerns\FakesFaceEngine;
use Tests\TestCase;

/** Rekognition en modo simulado (REKOGNITION_TEST_MODE=true): no llama a AWS. */
class RekognitionEngineTest extends TestCase
{
    use FakesFaceEngine, RefreshDatabase;

    private string $a;

    private string $b;

    protected function setUp(): void
    {
        parent::setUp();
        config(['facematch.engine' => 'rekognition']);
        RekognitionFace::fake();
        Storage::fake('local');
        $this->actingAs(User::factory()->create());

        $this->a = base_path('tests/Fixtures/faces/person_a_1.jpg');
        $this->b = base_path('tests/Fixtures/faces/person_a_2.jpg');
    }

    private function engine(): RekognitionFaceEngine
    {
        return $this->app->make(FaceEngine::class);
    }

    public function test_se_elige_con_face_engine(): void
    {
        $engine = $this->engine();

        $this->assertInstanceOf(RekognitionFaceEngine::class, $engine);
        $this->assertSame('rekognition', $engine->key());
        $this->assertTrue($engine->isSimulated());
        $this->assertSame('Rekognition · simulado', $engine->label());
    }

    public function test_comparar_coincidencia_simulada(): void
    {
        RekognitionFace::fakeScenario('match');

        $analysis = $this->engine()->analyze($this->a, $this->b, 80, false);

        $this->assertTrue($analysis->isMatch);
        $this->assertTrue($analysis->passed);
        $this->assertGreaterThanOrEqual(80, $analysis->similarity);
        $this->assertSame([], $analysis->failureReasons);
        $this->assertTrue($analysis->result['simulated']);
        $this->assertArrayHasKey('comparison', $analysis->result);
        $this->assertArrayHasKey('source_face', $analysis->result['comparison']);
    }

    public function test_comparar_sin_coincidencia_simulada(): void
    {
        RekognitionFace::fakeScenario('no_match');

        $analysis = $this->engine()->analyze($this->a, $this->b, 80, false);

        $this->assertFalse($analysis->isMatch);
        $this->assertFalse($analysis->passed);
        $this->assertStringContainsString('Los rostros no coinciden', $analysis->failureReasons[0]);
    }

    public function test_verificar_foto_borrosa_simulada_traduce_los_motivos(): void
    {
        RekognitionFace::fakeScenario('blurry');

        $analysis = $this->engine()->analyze($this->a, $this->b, 80, true);

        $this->assertFalse($analysis->passed);
        $this->assertFalse($analysis->result['source']['checks']['sharpness_ok']);
        $this->assertStringContainsString('borrosa', implode(' ', $analysis->failureReasons));
        $this->assertStringNotContainsString('simulated', implode(' ', $analysis->failureReasons));
    }

    public function test_verificar_varios_rostros_simulado(): void
    {
        RekognitionFace::fakeScenario('multiple_faces');

        $analysis = $this->engine()->analyze($this->a, $this->b, 80, true);

        $this->assertFalse($analysis->passed);
        $this->assertFalse($analysis->result['source']['checks']['single_face']);
    }

    public function test_la_web_muestra_el_aviso_de_simulacion(): void
    {
        RekognitionFace::fakeScenario('match');

        $this->post(route('comparisons.store'), [
            'source_image' => $this->fakeImage(),
            'target_image' => $this->fakeImage(),
            'mode' => 'verify',
        ])->assertRedirect();

        $comparison = Comparison::firstOrFail();
        $this->assertSame('rekognition', $comparison->engine);
        $this->assertFalse($comparison->hasRealBoxes());
        $this->assertSame('Amazon Rekognition (simulado)', $comparison->engineLabel());

        $this->get(route('comparisons.show', $comparison))
            ->assertOk()
            ->assertSee('Identidad verificada')
            ->assertSee('Resultado simulado');

        $this->get(route('home'))->assertOk()->assertSee('Rekognition · simulado');
    }
}
