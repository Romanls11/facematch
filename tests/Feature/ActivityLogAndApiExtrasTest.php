<?php

namespace Tests\Feature;

use App\Models\Comparison;
use App\Models\User;
use App\Support\ActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakesFaceEngine;
use Tests\TestCase;

class ActivityLogAndApiExtrasTest extends TestCase
{
    use FakesFaceEngine, RefreshDatabase;

    private string $logDir;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->actingAs(User::factory()->create());

        // Registro real en un directorio temporal.
        $this->logDir = storage_path('framework/testing/logs-'.uniqid());
        config([
            'facematch.log.channel' => 'facematch',
            'logging.channels.facematch.path' => $this->logDir.'/facematch.log',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->logDir);
        parent::tearDown();
    }

    private function api(array $data = [], string $token = 'api-test-token')
    {
        return $this->withToken($token)->post('/api/face/compare', $data + [
            'source_image' => $this->fakeImage('a.jpg'),
            'target_image' => $this->fakeImage('b.jpg'),
        ], ['Accept' => 'application/json']);
    }

    /** @return list<array<string, mixed>> */
    private function entries(array $filters = []): array
    {
        return app(ActivityLog::class)->read(now()->toDateString(), $filters)['entries'];
    }

    private function events(): array
    {
        return array_column($this->entries(), 'event');
    }

    public function test_registra_comparacion_envio_y_peticion_api_con_request_id(): void
    {
        config([
            'facematch.forward.url' => 'https://otro.test/recibir',
            'facematch.forward.token' => 'secreto',
        ]);
        Http::fake([
            '*/compare' => Http::response($this->engineOutput(0.3)),
            'https://otro.test/*' => Http::response(['ok' => true]),
        ]);

        $response = $this->withHeader('X-Request-Id', 'mi-peticion-123')->api(['mode' => 'compare'])->assertCreated();
        $response->assertHeader('X-Request-Id', 'mi-peticion-123');
        $uuid = $response->json('data.uuid');

        // Del más reciente al más antiguo.
        $this->assertSame(['api.request', 'forward.sent', 'comparison.created'], $this->events());

        [$api, $forward, $created] = $this->entries();

        $this->assertSame('info', $created['level']);
        $this->assertSame($uuid, $created['uuid']);
        $this->assertSame('mi-peticion-123', $created['request_id']);
        $this->assertSame(90, (int) $created['context']['similarity']);
        $this->assertSame('service', $created['context']['transport']);
        $this->assertStringContainsString('aprobada', $created['message']);

        $this->assertSame(200, $forward['context']['http_code']);
        $this->assertSame('https://otro.test/recibir', $forward['context']['url']);

        $this->assertSame(201, $api['context']['status']);
        $this->assertSame($uuid, $api['uuid']);
        $this->assertSame('mi-peticion-123', $api['request_id']);

        Http::assertSent(fn ($r) => $r->url() === 'https://otro.test/recibir' && $r->hasHeader('X-FaceMatch-Uuid', $uuid));
    }

    public function test_registra_errores_del_motor_y_envios_fallidos(): void
    {
        Http::fake(['*/compare' => Http::response(['ok' => false, 'error' => 'imagen corrupta'], 500)]);
        $this->api()->assertStatus(502);

        $failed = collect($this->entries())->firstWhere('event', 'comparison.failed');
        $this->assertSame('error', $failed['level']);
        $this->assertStringContainsString('imagen corrupta', $failed['message']);

        $api = collect($this->entries())->firstWhere('event', 'api.request');
        $this->assertSame('error', $api['level']);
        $this->assertSame(502, $api['context']['status']);
    }

    public function test_registra_401_y_envio_fallido(): void
    {
        $this->api(token: 'malo')->assertUnauthorized();
        $this->assertSame('warning', $this->entries()[0]['level']);
        $this->assertSame(401, $this->entries()[0]['context']['status']);

        config(['facematch.forward.url' => 'https://otro.test/recibir']);
        Http::fake([
            '*/compare' => Http::response($this->engineOutput(0.3)),
            'https://otro.test/*' => Http::response('caído', 503),
        ]);
        $this->api()->assertCreated()->assertJsonPath('data.forward.status', 'failed');

        $forward = collect($this->entries())->firstWhere('event', 'forward.failed');
        $this->assertSame('warning', $forward['level']);
        $this->assertSame(503, $forward['context']['http_code']);
    }

    public function test_registra_el_modo_lento_y_la_eliminacion(): void
    {
        Http::fake(['*/compare' => Http::failedConnection()]);
        Process::fake(['*' => Process::result(json_encode($this->engineOutput(0.3)))]);

        $this->api()->assertCreated();
        $this->assertContains('engine.fallback', $this->events());

        $this->delete(route('comparisons.destroy', Comparison::firstOrFail()));
        $this->assertSame('comparison.deleted', $this->events()[0]);
    }

    public function test_filtros_del_lector(): void
    {
        $log = app(ActivityLog::class);
        $log->info('comparison.created', 'Comparación uno', ['uuid' => 'aaa']);
        $log->warning('forward.failed', 'Envío dos', ['uuid' => 'bbb']);
        $log->error('comparison.failed', 'Tres');

        $this->assertCount(3, $this->entries());
        $this->assertSame(['comparison.failed', 'comparison.created'], array_column($this->entries(['group' => 'comparison']), 'event'));
        $this->assertSame(['forward.failed'], array_column($this->entries(['level' => 'warning']), 'event'));
        $this->assertSame(['comparison.created'], array_column($this->entries(['q' => 'aaa']), 'event'));

        $summary = $log->summary(now()->toDateString());
        $this->assertSame(['total' => 3, 'errors' => 1, 'warnings' => 1, 'comparisons' => 1, 'forwards' => 0, 'api' => 0], $summary);
        $this->assertSame([now()->toDateString()], $log->dates());
    }

    public function test_el_registro_se_puede_desactivar(): void
    {
        config(['facematch.log.channel' => 'null']);
        app(ActivityLog::class)->info('comparison.created', 'No debe escribirse');

        $this->assertFalse(app(ActivityLog::class)->enabled());
        $this->assertDirectoryDoesNotExist($this->logDir);
    }

    public function test_pagina_de_registros(): void
    {
        $log = app(ActivityLog::class);
        $log->info('comparison.created', 'Comparación web: 91.00 % (umbral 80 %) · aprobada', ['uuid' => 'uuid-de-prueba']);
        $log->warning('engine.fallback', 'El servicio facial no responde');

        $this->get(route('logs.index'))->assertOk()
            ->assertSee('Registros')
            ->assertSee('Comparación web: 91.00 %')
            ->assertSee('Modo lento')
            ->assertSee('uuid-de-prueba');

        $this->get(route('logs.index', ['nivel' => 'warning']))->assertOk()
            ->assertSee('El servicio facial no responde')
            ->assertDontSee('Comparación web: 91.00 %');

        $this->get(route('logs.index', ['fecha' => '2000-01-01']))->assertOk()->assertSee('No hay eventos para esta fecha');
        $this->get(route('logs.index', ['fecha' => '../../.env']))->assertOk();
    }

    public function test_pagina_de_documentacion(): void
    {
        $this->get(route('docs.api'))->assertOk()
            ->assertSee('Cómo consumir FaceMatch')
            ->assertSee(url('/api/face/compare'))
            ->assertSee('Python')
            ->assertSee('X-FaceMatch-Uuid')
            ->assertDontSee('api-test-token');
    }

    public function test_health_y_listado(): void
    {
        Http::fake([
            '*/health' => Http::failedConnection(),
            '*/compare' => Http::sequence()
                ->push($this->engineOutput(0.3))
                ->push($this->engineOutput(0.9)),
        ]);

        $this->withToken('api-test-token')->getJson('/api/face/health')->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('engine.key', 'local')
            ->assertJsonPath('engine.speed', 'slow')
            ->assertJsonPath('forward.configured', false);

        $this->api(['mode' => 'verify'])->assertCreated()->assertJsonPath('data.passed', true);
        $this->api(['mode' => 'compare'])->assertCreated()->assertJsonPath('data.passed', false);

        $this->withToken('api-test-token')->getJson('/api/face/comparisons')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonMissingPath('data.0.result');

        $this->withToken('api-test-token')->getJson('/api/face/comparisons?passed=1')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.mode', 'verify');

        $this->withToken('api-test-token')->getJson('/api/face/comparisons?mode=compare&per_page=1')->assertOk()
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('data.0.passed', false);

        $this->withToken('api-test-token')->getJson('/api/face/comparisons?per_page=500')
            ->assertUnprocessable()
            ->assertJsonPath('errors.per_page.0', 'per_page no puede ser mayor a 100.');

        $this->withoutToken()->getJson('/api/face/health')->assertUnauthorized();
    }

    public function test_limite_de_peticiones(): void
    {
        config(['facematch.api_rate_limit' => 2]);

        $this->withToken('api-test-token')->getJson('/api/face/comparisons')->assertOk();
        $this->withToken('api-test-token')->getJson('/api/face/comparisons')->assertOk();
        $this->withToken('api-test-token')->getJson('/api/face/comparisons')
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('message', fn ($m) => str_starts_with($m, 'Demasiadas peticiones'));
    }
}
