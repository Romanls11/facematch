<?php

namespace Tests\Feature;

use App\Models\Comparison;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakesFaceEngine;
use Tests\TestCase;

class AuthAndPrivacyTest extends TestCase
{
    use FakesFaceEngine, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function createComparisonViaApi(): Comparison
    {
        Http::fake(['*/compare' => Http::response($this->engineOutput(0.3))]);

        $this->withToken('api-test-token')->post('/api/face/compare', [
            'source_image' => $this->fakeImage('a.jpg'),
            'target_image' => $this->fakeImage('b.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated();

        return Comparison::firstOrFail();
    }

    public function test_las_paginas_web_requieren_sesion(): void
    {
        foreach (['home', 'comparisons.history', 'logs.index', 'docs.api'] as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }

        $this->post(route('comparisons.store'))->assertRedirect(route('login'));
        $this->get('/login')->assertOk()->assertSee('Inicia sesión')->assertSee('facematch:user');
    }

    public function test_inicia_y_cierra_sesion(): void
    {
        $user = User::factory()->create(['email' => 'ana@example.com', 'password' => 'una-clave-segura']);

        $this->get(route('logs.index'));
        $this->post('/login', ['email' => 'ana@example.com', 'password' => 'una-clave-segura'])
            ->assertRedirect(route('logs.index')); // vuelve a la página que pidió

        $this->assertAuthenticatedAs($user);
        $this->get(route('home'))->assertOk()->assertSee($user->name)->assertSee('Cerrar sesión');
        $this->get('/login')->assertRedirect(route('home'));

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_rechaza_credenciales_incorrectas_y_bloquea_tras_cinco_intentos(): void
    {
        User::factory()->create(['email' => 'ana@example.com', 'password' => 'una-clave-segura']);

        $this->post('/login', ['email' => 'ana@example.com', 'password' => 'mala'])
            ->assertSessionHasErrors(['email' => 'El correo o la contraseña no son correctos.']);
        $this->assertGuest();

        $this->post('/login', [])->assertSessionHasErrors(['email' => 'Escribe tu correo.', 'password' => 'Escribe tu contraseña.']);

        for ($i = 0; $i < 4; $i++) {
            $this->post('/login', ['email' => 'ana@example.com', 'password' => 'mala']);
        }

        // Ni la contraseña correcta funciona mientras dura el bloqueo.
        $this->post('/login', ['email' => 'ana@example.com', 'password' => 'una-clave-segura'])
            ->assertSessionHasErrors('email');
        $this->assertStringStartsWith('Demasiados intentos', session('errors')->first('email'));
        $this->assertGuest();
    }

    public function test_las_imagenes_son_privadas(): void
    {
        $comparison = $this->createComparisonViaApi();

        // Se guardan en el disco privado, no en el público.
        Storage::disk('local')->assertExists([$comparison->source_path, $comparison->target_path]);
        $this->assertStringNotContainsString('/storage/', $comparison->sourceUrl());

        // Sin sesión: redirige al login.
        $this->get($comparison->sourceUrl())->assertRedirect(route('login'));

        // Con sesión: se sirve la imagen con caché privada.
        $this->actingAs(User::factory()->create())
            ->get($comparison->targetUrl())
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        // Un lado inexistente no coincide con la ruta.
        $this->get(route('comparisons.show', $comparison).'/imagen/otra')->assertNotFound();
    }

    public function test_url_firmada_funciona_sin_sesion_y_caduca(): void
    {
        $comparison = $this->createComparisonViaApi();

        $url = $this->withToken('api-test-token')
            ->getJson("/api/face/comparisons/{$comparison->uuid}")
            ->assertOk()
            ->assertJsonStructure(['data' => ['image_urls_expire_at']])
            ->json('data.source_url');

        $this->assertStringContainsString('signature=', $url);

        $this->withoutToken()->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        // Firma alterada.
        $this->get(preg_replace('/signature=\w+/', 'signature=falsa', $url))->assertForbidden();

        // Caducada.
        $this->travel(config('facematch.image_url_ttl') + 2)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_comando_para_crear_y_listar_usuarios(): void
    {
        $this->artisan('facematch:user', ['email' => 'luis@example.com', '--name' => 'Luis', '--password' => 'otra-clave-segura'])
            ->assertSuccessful();

        $this->assertTrue(auth()->attempt(['email' => 'luis@example.com', 'password' => 'otra-clave-segura']));

        $this->artisan('facematch:user', ['email' => 'luis@example.com', '--password' => 'corta'])
            ->expectsOutputToContain('al menos 10 caracteres')
            ->assertFailed();

        $this->artisan('facematch:user', ['email' => 'luis@example.com', '--password' => 'clave-nueva-larga'])->assertSuccessful();
        $this->assertSame(1, User::count());

        $this->artisan('facematch:user', ['--list' => true])->expectsOutputToContain('luis@example.com')->assertSuccessful();
        $this->artisan('facematch:user', ['email' => 'luis@example.com', '--delete' => true])->assertSuccessful();
        $this->assertSame(0, User::count());
    }
}
