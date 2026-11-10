<?php

namespace Tests\Feature;

use App\FaceEngines\FaceEngine;
use App\FaceEngines\FaceEngineException;
use App\FaceEngines\LocalFaceEngine;
use App\Models\Comparison;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakesFaceEngine;
use Tests\TestCase;

class LocalFaceEngineTest extends TestCase
{
    use FakesFaceEngine, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        config(['facematch.local.service_root' => Storage::disk('local')->path('')]);
    }

    private function engine(): LocalFaceEngine
    {
        return $this->app->make(FaceEngine::class);
    }

    public function test_el_motor_por_defecto_es_local(): void
    {
        $engine = $this->engine();

        $this->assertInstanceOf(LocalFaceEngine::class, $engine);
        $this->assertSame('local', $engine->key());
        $this->assertFalse($engine->isSimulated());
    }

    public function test_usa_el_servicio_cuando_esta_disponible(): void
    {
        Process::fake();
        Http::fake(['127.0.0.1:3311/compare' => Http::response($this->engineOutput(0.3))]);

        $analysis = $this->engine()->analyze('/a.jpg', '/b.jpg', 80, false);

        $this->assertTrue($analysis->isMatch);
        $this->assertTrue($analysis->passed);
        $this->assertEqualsWithDelta(90.0, $analysis->similarity, 0.01);
        $this->assertSame('service', $analysis->result['transport']);
        $this->assertSame(210, $analysis->result['elapsed_ms']);
        $this->assertSame(0.3, $analysis->result['comparison']['distance']);
        $this->assertSame(0.6, $analysis->result['comparison']['match_distance']);
        $this->assertSame(0, $analysis->result['comparison']['unmatched_count']);
        $this->assertArrayNotHasKey('source', $analysis->result, 'Comparar no incluye la verificación de calidad');

        Http::assertSent(fn (Request $r) => $r->url() === 'http://127.0.0.1:3311/compare'
            && $r->hasHeader('X-Face-Token', 'service-test-token')
            && $r['source'] === '/a.jpg'
            && $r['target'] === '/b.jpg');
        Process::assertNothingRan();
    }

    public function test_comparar_usa_el_detector_tiny(): void
    {
        Http::fake(['*/compare' => Http::response($this->engineOutput())]);

        $this->engine()->analyze('/a.jpg', '/b.jpg', 80, false);

        Http::assertSent(fn (Request $r) => $r['detector'] === 'tiny');
    }

    public function test_verificar_usa_siempre_el_detector_ssd(): void
    {
        config(['facematch.local.detector' => 'tiny']);
        Http::fake(['*/compare' => Http::response($this->engineOutput(detector: 'ssd'))]);

        $analysis = $this->engine()->analyze('/a.jpg', '/b.jpg', 80, true);

        Http::assertSent(fn (Request $r) => $r['detector'] === 'ssd');
        $this->assertTrue($analysis->passed);
        $this->assertTrue($analysis->result['source']['is_real_human_face']);
        $this->assertSame(
            ['human_detected', 'single_face', 'sharpness_ok', 'brightness_ok', 'pose_frontal', 'eyes_open', 'no_occlusion', 'min_face_size_ok'],
            array_keys($analysis->result['source']['checks']),
        );
    }

    public function test_recurre_al_proceso_si_el_servicio_no_esta(): void
    {
        Http::fake(['*' => Http::failedConnection()]);
        Process::fake(['*' => Process::result(output: json_encode($this->engineOutput(0.75)))]);

        $analysis = $this->engine()->analyze('/a.jpg', '/b.jpg', 80, false);

        $this->assertSame('process', $analysis->result['transport']);
        $this->assertFalse($analysis->isMatch);
        $this->assertEqualsWithDelta(50.0, $analysis->similarity, 0.01);
        $this->assertStringContainsString('no coinciden', $analysis->failureReasons[0]);

        Process::assertRan(fn ($process) => in_array('/a.jpg', (array) $process->command, true)
            && in_array('tiny', (array) $process->command, true)
            && str_contains(implode(' ', (array) $process->command), 'face-engine.cjs'));
    }

    public function test_el_proceso_recibe_ssd_en_modo_verificar(): void
    {
        Http::fake(['*' => Http::failedConnection()]);
        Process::fake(['*' => Process::result(output: json_encode($this->engineOutput(detector: 'ssd')))]);

        $this->engine()->analyze('/a.jpg', '/b.jpg', 80, true);

        Process::assertRan(fn ($process) => end($process->command) === 'ssd');
    }

    public function test_sin_token_no_intenta_el_servicio(): void
    {
        config(['facematch.local.service_token' => null]);
        Http::fake();
        Process::fake(['*' => Process::result(output: json_encode($this->engineOutput()))]);

        $analysis = $this->engine()->analyze('/a.jpg', '/b.jpg', 80, false);

        $this->assertSame('process', $analysis->result['transport']);
        Http::assertNothingSent();
    }

    public function test_reporta_los_errores_del_servicio_sin_usar_el_proceso(): void
    {
        Http::fake(['*/compare' => Http::response(['ok' => false, 'error' => 'Las rutas deben existir y estar dentro de FACE_SERVICE_ROOT.'], 422)]);
        Process::fake();

        try {
            $this->engine()->analyze('/a.jpg', '/b.jpg', 80, false);
            $this->fail('Se esperaba FaceEngineException');
        } catch (FaceEngineException $e) {
            $this->assertStringContainsString('El servicio facial respondió con un error', $e->getMessage());
            $this->assertStringContainsString('FACE_SERVICE_ROOT', $e->getMessage());
        }

        Process::assertNothingRan();
    }

    public function test_reporta_los_errores_del_proceso(): void
    {
        Http::fake(['*' => Http::failedConnection()]);
        Process::fake(['*' => Process::result(output: '{"ok":false,"error":"No existe el archivo: /a.jpg"}', exitCode: 2)]);

        $this->expectException(FaceEngineException::class);
        $this->expectExceptionMessage('No existe el archivo');

        $this->engine()->analyze('/a.jpg', '/b.jpg', 80, false);
    }

    public function test_sin_rostro_devuelve_motivo_en_espanol(): void
    {
        Http::fake(['*/compare' => Http::response($this->engineOutput(null, targetFace: ['none' => true]))]);

        $analysis = $this->engine()->analyze('/a.jpg', '/b.jpg', 80, false);

        $this->assertFalse($analysis->isMatch);
        $this->assertSame(0.0, $analysis->similarity);
        $this->assertContains('No se detectó ningún rostro en la imagen a comparar.', $analysis->failureReasons);
    }

    public function test_verificar_rechaza_foto_borrosa_aunque_coincida(): void
    {
        Http::fake(['*/compare' => Http::response($this->engineOutput(0.2, targetFace: ['quality' => ['sharpness' => 2.7]], detector: 'ssd'))]);

        $analysis = $this->engine()->analyze('/a.jpg', '/b.jpg', 80, true);

        $this->assertTrue($analysis->isMatch);
        $this->assertFalse($analysis->passed);
        $this->assertFalse($analysis->result['target']['checks']['sharpness_ok']);
        $this->assertTrue($analysis->result['source']['checks']['sharpness_ok']);
        $this->assertStringContainsString('borrosa', implode(' ', $analysis->failureReasons));
    }

    public function test_verificar_aplica_los_limites_de_pose_ojos_y_tamano(): void
    {
        Http::fake(['*/compare' => Http::response($this->engineOutput(0.2, sourceFace: [
            'pose' => ['yaw' => 50.0],
            'eyes_open' => ['value' => false, 'ear' => 0.15],
            'bounding_box' => ['area_percentage' => 0.9],
            'quality' => ['brightness' => 95.0],
        ], detector: 'ssd'))]);

        $checks = $this->engine()->analyze('/a.jpg', '/b.jpg', 80, true)->result['source']['checks'];

        $this->assertFalse($checks['pose_frontal']);
        $this->assertFalse($checks['eyes_open']);
        $this->assertFalse($checks['min_face_size_ok']);
        $this->assertFalse($checks['brightness_ok']);
        $this->assertTrue($checks['sharpness_ok']);
    }

    public function test_estado_del_servicio(): void
    {
        Http::fake(['*/health' => Http::response(['ok' => true, 'engine' => 'face-api/tfjs-wasm', 'workers' => 2, 'models_load_ms' => 250, 'handled' => 7])]);

        $status = $this->engine()->serviceStatus();

        $this->assertTrue($status['available']);
        $this->assertSame(2, $status['workers']);
        $this->assertSame(7, $status['handled']);
    }

    public function test_estado_del_servicio_caido(): void
    {
        Http::fake(['*' => Http::failedConnection()]);

        $this->assertFalse($this->engine()->serviceStatus()['available']);
    }

    public function test_la_web_compara_y_guarda_el_registro(): void
    {
        Http::fake(['*/compare' => Http::response($this->engineOutput(0.3)), '*/health' => Http::failedConnection()]);

        $response = $this->post(route('comparisons.store'), [
            'source_image' => $this->fakeImage('a.jpg'),
            'target_image' => $this->fakeImage('b.png'),
            'mode' => 'compare',
            'threshold' => 80,
        ]);

        $comparison = Comparison::firstOrFail();
        $response->assertRedirect(route('comparisons.show', $comparison));
        $response->assertSessionHas('success');

        $this->assertSame('local', $comparison->engine);
        $this->assertSame('web', $comparison->origin);
        $this->assertTrue($comparison->is_match);
        $this->assertSame('skipped', $comparison->forward_status);
        $this->assertMatchesRegularExpression('#^comparisons/\d{4}/\d{2}/[0-9a-f-]{36}\.jpg$#', $comparison->source_path);
        $this->assertStringEndsWith('.png', $comparison->target_path);
        Storage::disk('local')->assertExists([$comparison->source_path, $comparison->target_path]);

        $this->get(route('comparisons.show', $comparison))
            ->assertOk()
            ->assertSee('Los rostros coinciden')
            ->assertSee('90.00 %');
    }

    public function test_si_el_motor_falla_borra_las_imagenes(): void
    {
        Http::fake(['*/compare' => Http::response(['ok' => false, 'error' => 'boom'], 500)]);

        $this->from(route('home'))->post(route('comparisons.store'), [
            'source_image' => $this->fakeImage(),
            'target_image' => $this->fakeImage(),
        ])->assertRedirect(route('home'))->assertSessionHas('error');

        $this->assertSame(0, Comparison::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
