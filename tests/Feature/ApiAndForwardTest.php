<?php

namespace Tests\Feature;

use App\Models\Comparison;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakesFaceEngine;
use Tests\TestCase;

class ApiAndForwardTest extends TestCase
{
    use FakesFaceEngine, RefreshDatabase;

    private const FORWARD_URL = 'https://otro-proyecto.test/api/face-results';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
    }

    private function api(array $data = [], string $token = 'api-test-token')
    {
        return $this->withToken($token)->post('/api/face/compare', $data + [
            'source_image' => $this->fakeImage('a.jpg'),
            'target_image' => $this->fakeImage('b.jpg'),
        ], ['Accept' => 'application/json']);
    }

    private function enableForward(bool $onlyMatches = true): void
    {
        config([
            'facematch.forward.url' => self::FORWARD_URL,
            'facematch.forward.token' => 'forward-secret',
            'facematch.forward.only_matches' => $onlyMatches,
        ]);
    }

    public function test_la_api_responde_503_sin_token_configurado(): void
    {
        config(['facematch.api_token' => null]);

        $this->api()->assertStatus(503)->assertJsonPath('message', fn ($m) => str_contains($m, 'FACE_API_TOKEN'));
    }

    public function test_la_api_rechaza_un_token_invalido(): void
    {
        $this->api(token: 'otro')->assertUnauthorized();
        $this->postJson('/api/face/compare')->assertUnauthorized();
    }

    public function test_la_api_valida_en_espanol(): void
    {
        $this->withToken('api-test-token')
            ->postJson('/api/face/compare', ['threshold' => 120, 'mode' => 'otro'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.source_image.0', 'Falta la imagen de origen.')
            ->assertJsonPath('errors.threshold.0', 'El umbral debe estar entre 50 y 99.')
            ->assertJsonPath('errors.mode.0', 'El modo debe ser «compare» (Comparar) o «verify» (Verificar).');
    }

    public function test_la_api_rechaza_archivos_que_no_son_jpg_o_png(): void
    {
        $this->api([
            'source_image' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
        ])->assertUnprocessable()->assertJsonValidationErrors('source_image');
    }

    public function test_la_api_compara_y_consulta(): void
    {
        Http::fake(['*/compare' => Http::response($this->engineOutput(0.3))]);

        $response = $this->api(['mode' => 'compare', 'threshold' => 85])
            ->assertCreated()
            ->assertJsonPath('data.is_match', true)
            ->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.similarity', 90)
            ->assertJsonPath('data.threshold', 85)
            ->assertJsonPath('data.origin', 'api')
            ->assertJsonPath('data.forward.status', 'skipped')
            ->assertJsonPath('data.result.transport', 'service');

        $uuid = $response->json('data.uuid');

        $this->withToken('api-test-token')
            ->getJson("/api/face/comparisons/{$uuid}")
            ->assertOk()
            ->assertJsonPath('data.uuid', $uuid);

        $this->withToken('api-test-token')
            ->getJson('/api/face/comparisons/no-existe')
            ->assertNotFound();
    }

    public function test_la_api_devuelve_502_si_el_motor_falla(): void
    {
        Http::fake(['*/compare' => Http::response(['ok' => false, 'error' => 'boom'], 500)]);

        $this->api()->assertStatus(502)->assertJsonPath('message', fn ($m) => str_contains($m, 'boom'));
    }

    public function test_envia_coincidencias_con_ambas_imagenes_y_bearer(): void
    {
        $this->enableForward();
        Http::fake([
            '*/compare' => Http::response($this->engineOutput(0.3)),
            self::FORWARD_URL => Http::response(['received' => true], 200),
        ]);

        $this->api()->assertCreated()->assertJsonPath('data.forward.status', 'sent');

        Http::assertSent(function (Request $request) {
            if ($request->url() !== self::FORWARD_URL) {
                return false;
            }

            $names = collect($request->data())->pluck('name')->all();
            $payload = json_decode(collect($request->data())->firstWhere('name', 'payload')['contents'], true);

            return $request->hasHeader('Authorization', 'Bearer forward-secret')
                && $request->isMultipart()
                && in_array('source_image', $names, true)
                && in_array('target_image', $names, true)
                && $payload['is_match'] === true
                && $payload['mode'] === 'compare'
                && isset($payload['uuid'], $payload['similarity'], $payload['threshold'], $payload['result'], $payload['compared_at'], $payload['source_url'], $payload['target_url'])
                && $payload['failure_reasons'] === [];
        });

        $comparison = Comparison::firstOrFail();
        $this->assertSame(200, $comparison->forward_http_code);
        $this->assertNotNull($comparison->forwarded_at);
    }

    public function test_por_defecto_no_envia_si_no_coinciden(): void
    {
        $this->enableForward(onlyMatches: true);
        Http::fake(['*/compare' => Http::response($this->engineOutput(0.8))]);

        $this->api()->assertCreated()->assertJsonPath('data.forward.status', 'skipped');

        Http::assertNotSent(fn (Request $r) => $r->url() === self::FORWARD_URL);
    }

    public function test_forward_true_envia_aunque_no_coincidan(): void
    {
        $this->enableForward(onlyMatches: true);
        Http::fake([
            '*/compare' => Http::response($this->engineOutput(0.8)),
            self::FORWARD_URL => Http::response('ok'),
        ]);

        $this->api(['forward' => '1'])->assertCreated()->assertJsonPath('data.forward.status', 'sent');
    }

    public function test_forward_false_no_envia(): void
    {
        $this->enableForward();
        Http::fake(['*/compare' => Http::response($this->engineOutput(0.3))]);

        $this->api(['forward' => '0'])->assertCreated()->assertJsonPath('data.forward.status', 'skipped');
        Http::assertNotSent(fn (Request $r) => $r->url() === self::FORWARD_URL);
    }

    public function test_registra_el_fallo_del_envio(): void
    {
        $this->enableForward();
        Http::fake([
            '*/compare' => Http::response($this->engineOutput(0.3)),
            self::FORWARD_URL => Http::response('Error interno', 500),
        ]);

        $this->api()->assertCreated()->assertJsonPath('data.forward.status', 'failed')->assertJsonPath('data.forward.http_code', 500);
        $this->assertSame('Error interno', Comparison::firstOrFail()->forward_response);
    }

    public function test_reenviar_desde_la_web(): void
    {
        Http::fake(['*/compare' => Http::response($this->engineOutput(0.8)), '*/health' => Http::failedConnection()]);
        $this->api()->assertCreated();
        $comparison = Comparison::firstOrFail();
        $this->assertSame('skipped', $comparison->forward_status);

        $this->enableForward();
        Http::fake([self::FORWARD_URL => Http::response('ok', 201)]);

        $this->post(route('comparisons.forward', $comparison))->assertSessionHas('success');
        $this->assertSame('sent', $comparison->fresh()->forward_status);
        $this->assertSame(201, $comparison->fresh()->forward_http_code);
    }

    public function test_eliminar_borra_registro_e_imagenes(): void
    {
        Http::fake(['*/compare' => Http::response($this->engineOutput(0.3))]);
        $this->api()->assertCreated();
        $comparison = Comparison::firstOrFail();

        $this->delete(route('comparisons.destroy', $comparison))->assertRedirect(route('comparisons.history'));

        $this->assertSame(0, Comparison::count());
        Storage::disk('local')->assertMissing([$comparison->source_path, $comparison->target_path]);
    }

    public function test_paginas_principales(): void
    {
        Http::fake(['*/compare' => Http::response($this->engineOutput(0.3)), '*/health' => Http::failedConnection()]);
        $this->api(['mode' => 'verify'])->assertCreated();

        $this->get(route('home'))->assertOk()
            ->assertSee('Modo lento')
            ->assertSee('php artisan face:serve')
            ->assertSee('Enviar al otro proyecto');

        $this->get(route('comparisons.history'))->assertOk()->assertSee('Verificada');

        $comparison = Comparison::firstOrFail();
        $this->get(route('comparisons.show', $comparison))->assertOk()
            ->assertSee('Identidad verificada')
            ->assertSee('Ojos abiertos')
            ->assertSee('Enviar ahora');
    }

    public function test_la_portada_muestra_modo_rapido_con_el_servicio(): void
    {
        Http::fake(['*/health' => Http::response(['ok' => true, 'workers' => 2, 'models_load_ms' => 240, 'handled' => 3])]);

        $this->get(route('home'))->assertOk()->assertSee('Modo rápido');
    }
}
