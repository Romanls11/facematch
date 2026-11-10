@extends('layouts.app')

@section('title', 'Cómo consumir la API')

@use('App\Support\ApiExamples')

@php
    $base = rtrim(url('/'), '/');
    $clients = ApiExamples::clients($base);
    $queries = ApiExamples::queries($base);
    $receivers = ApiExamples::receivers();
    $apiEnabled = filled(config('facematch.api_token'));
    $forwardUrl = config('facematch.forward.url');
@endphp

@section('content')
<div class="fm-docs">
    <div class="mb-4 fm-reveal">
        <h1 class="h3 fw-bold mb-1">Cómo consumir FaceMatch</h1>
        <p class="text-muted-fm mb-0">
            Envía dos fotos desde cualquier sistema y recibe la similitud, si coinciden y los motivos de rechazo.
            También puedes recibir cada resultado automáticamente en tu otro proyecto.
        </p>
    </div>

    <div class="row g-4">
        <div class="col-lg-3 d-none d-lg-block">
            <nav class="fm-card p-3 fm-toc small">
                <div class="fw-semibold mb-2">En esta página</div>
                <ul class="list-unstyled d-grid gap-1 mb-0">
                    <li><a href="#inicio">Inicio rápido</a></li>
                    <li><a href="#endpoints">Endpoints</a></li>
                    <li><a href="#comparar">Comparar rostros</a></li>
                    <li><a href="#ejemplos">Ejemplos por lenguaje</a></li>
                    <li><a href="#respuesta">Respuesta</a></li>
                    <li><a href="#consultas">Consultar resultados</a></li>
                    <li><a href="#errores">Códigos de error</a></li>
                    <li><a href="#recibir">Recibir resultados (envío)</a></li>
                    <li><a href="#registros">Registros y trazabilidad</a></li>
                </ul>
            </nav>
        </div>

        <div class="col-lg-9 d-grid gap-4">

            {{-- Inicio rápido --}}
            <section class="fm-card p-3 p-lg-4" id="inicio">
                <h2 class="h5 fw-bold mb-3"><i class="bi bi-rocket-takeoff me-1"></i> Inicio rápido</h2>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <div class="small text-muted-fm">URL base</div>
                        <code class="fs-6">{{ $base }}/api/face</code>
                    </div>
                    <div class="col-md-6">
                        <div class="small text-muted-fm">Estado de la API</div>
                        @if ($apiEnabled)
                            <span class="fm-chip fm-chip-success"><i class="bi bi-check2-circle"></i> Habilitada</span>
                        @else
                            <span class="fm-chip fm-chip-danger"><i class="bi bi-x-circle"></i> Deshabilitada: configura <code>FACE_API_TOKEN</code></span>
                        @endif
                    </div>
                </div>
                <ol class="mb-3">
                    <li>Toma el token de la variable <code>FACE_API_TOKEN</code> del archivo <code>.env</code> de FaceMatch.</li>
                    <li>Envíalo en cada petición: <code>Authorization: Bearer &lt;token&gt;</code>.</li>
                    <li>Agrega <code>Accept: application/json</code> para recibir siempre JSON (también los errores).</li>
                    <li>Sube las imágenes como <code>multipart/form-data</code> en <code>POST /api/face/compare</code>.</li>
                </ol>
                @include('docs.partials.snippet', ['code' => $clients['curl']['code'], 'lang' => 'bash'])
                <div class="small text-muted-fm mt-2">
                    <i class="bi bi-lightbulb"></i> El token nunca debe ir en el navegador de tus usuarios: llama a FaceMatch desde tu servidor.
                </div>
            </section>

            {{-- Endpoints --}}
            <section class="fm-card p-3 p-lg-4" id="endpoints">
                <h2 class="h5 fw-bold mb-3"><i class="bi bi-diagram-3 me-1"></i> Endpoints</h2>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <tbody>
                        <tr>
                            <td><span class="fm-method fm-method-post">POST</span></td>
                            <td><code>/api/face/compare</code></td>
                            <td>Compara dos rostros, guarda el resultado y lo envía si corresponde. Responde <code>201</code>.</td>
                        </tr>
                        <tr>
                            <td><span class="fm-method fm-method-get">GET</span></td>
                            <td><code>/api/face/comparisons</code></td>
                            <td>Lista paginada, con filtros <code>mode</code>, <code>origin</code>, <code>passed</code>, <code>forward_status</code> y <code>per_page</code>.</td>
                        </tr>
                        <tr>
                            <td><span class="fm-method fm-method-get">GET</span></td>
                            <td><code>/api/face/comparisons/{uuid}</code></td>
                            <td>Consulta una comparación.</td>
                        </tr>
                        <tr>
                            <td><span class="fm-method fm-method-get">GET</span></td>
                            <td><code>/api/face/health</code></td>
                            <td>Estado del motor (rápido/lento), del envío y valores por defecto.</td>
                        </tr>
                        </tbody>
                    </table>
                </div>
                <div class="small text-muted-fm mt-2">
                    Todos requieren el token. Límite: <strong>{{ (int) config('facematch.api_rate_limit', 60) }} peticiones por minuto</strong>
                    por token (<code>FACE_API_RATE_LIMIT</code>); al excederlo se responde <code>429</code> con la cabecera <code>Retry-After</code>.
                </div>
            </section>

            {{-- Comparar --}}
            <section class="fm-card p-3 p-lg-4" id="comparar">
                <h2 class="h5 fw-bold mb-3"><span class="fm-method fm-method-post me-1">POST</span> <code>/api/face/compare</code></h2>
                <div class="table-responsive mb-3">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr class="small text-muted-fm"><th>Campo</th><th>Tipo</th><th>Descripción</th></tr></thead>
                        <tbody>
                        <tr><td><code>source_image</code> <span class="text-danger">*</span></td><td>archivo</td><td>Foto de referencia (documento, foto registrada). JPG o PNG, máx. 5 MB.</td></tr>
                        <tr><td><code>target_image</code> <span class="text-danger">*</span></td><td>archivo</td><td>Foto a comparar (selfie, cámara). JPG o PNG, máx. 5 MB.</td></tr>
                        <tr><td><code>mode</code></td><td>texto</td><td>
                            <code>compare</code> (por defecto): solo mide la similitud.<br>
                            <code>verify</code>: además exige un solo rostro, nitidez, brillo, pose frontal, ojos abiertos y tamaño suficiente.
                        </td></tr>
                        <tr><td><code>threshold</code></td><td>número</td><td>Similitud mínima para aprobar, de 50 a 99. Por defecto {{ (int) config('facematch.default_threshold', 80) }}.</td></tr>
                        <tr><td><code>forward</code></td><td>booleano</td><td>
                            <code>1</code>: enviar siempre al otro proyecto · <code>0</code>: no enviar ·
                            sin valor: se envía solo si aprueba (<code>FACE_FORWARD_ONLY_MATCHES</code>).
                        </td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="alert alert-info small mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    Usa <strong><code>passed</code></strong> para decidir: en <code>compare</code> equivale a <code>is_match</code>;
                    en <code>verify</code> además exige que pasen los controles de calidad. Si no pasa,
                    <code>failure_reasons</code> explica por qué, en español (por ejemplo
                    «Los rostros no coinciden: la similitud de 42.1 % está por debajo del umbral de 80 %»).
                </div>
            </section>

            {{-- Ejemplos --}}
            <section class="fm-card p-3 p-lg-4" id="ejemplos">
                <h2 class="h5 fw-bold mb-3"><i class="bi bi-code-slash me-1"></i> Ejemplos por lenguaje</h2>
                <ul class="nav nav-pills mb-3 flex-wrap gap-1" role="tablist">
                    @foreach ($clients as $key => $client)
                        <li class="nav-item" role="presentation">
                            <button class="nav-link @if($loop->first) active @endif" data-bs-toggle="pill" data-bs-target="#client-{{ $key }}"
                                    type="button" role="tab" aria-controls="client-{{ $key }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}">
                                {{ $client['label'] }}
                            </button>
                        </li>
                    @endforeach
                </ul>
                <div class="tab-content">
                    @foreach ($clients as $key => $client)
                        <div class="tab-pane fade @if($loop->first) show active @endif" id="client-{{ $key }}" role="tabpanel">
                            @include('docs.partials.snippet', ['code' => $client['code'], 'lang' => $client['lang']])
                        </div>
                    @endforeach
                </div>
                <div class="small text-muted-fm mt-2">
                    Reemplaza <code>TU_FACE_API_TOKEN</code> por el valor de <code>FACE_API_TOKEN</code>.
                    Una comparación tarda ~0.2 s (Comparar) o ~0.5 s (Verificar) con el servicio activo; usa un timeout de al menos 60 s por si arranca en modo lento.
                </div>
            </section>

            {{-- Respuesta --}}
            <section class="fm-card p-3 p-lg-4" id="respuesta">
                <h2 class="h5 fw-bold mb-3"><i class="bi bi-reply me-1"></i> Respuesta</h2>
                @include('docs.partials.snippet', ['code' => ApiExamples::response($base), 'lang' => 'json'])
                <div class="table-responsive mt-3">
                    <table class="table table-sm mb-0">
                        <tbody class="small">
                        <tr><td><code>passed</code></td><td>Resultado final para tu lógica de negocio.</td></tr>
                        <tr><td><code>is_match</code></td><td>La similitud alcanzó el umbral (sin contar los controles de calidad).</td></tr>
                        <tr><td><code>similarity</code></td><td>0–100. Con el motor local, 80 % corresponde a la distancia de corte 0.6 de face-api.</td></tr>
                        <tr><td><code>failure_reasons</code></td><td>Lista de motivos en español; vacía si aprobó.</td></tr>
                        <tr><td><code>forward.status</code></td><td><code>sent</code>, <code>failed</code>, <code>skipped</code> o <code>pending</code>.</td></tr>
                        <tr><td><code>result</code></td><td>Detalle del motor: caras detectadas, pose, nitidez, brillo, ojos, tiempos.</td></tr>
                        <tr><td><code>source_url</code>, <code>target_url</code></td><td>URLs firmadas para descargar las fotos sin sesión. Caducan en <code>image_urls_expire_at</code> ({{ (int) config('facematch.image_url_ttl', 1440) }} min, <code>FACE_IMAGE_URL_TTL</code>); vuelve a consultar la comparación para obtener otras nuevas.</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            {{-- Consultas --}}
            <section class="fm-card p-3 p-lg-4" id="consultas">
                <h2 class="h5 fw-bold mb-3"><i class="bi bi-search me-1"></i> Consultar resultados</h2>
                <div class="d-grid gap-3">
                    <div>
                        <div class="fw-semibold mb-1"><span class="fm-method fm-method-get me-1">GET</span> Estado del servicio</div>
                        @include('docs.partials.snippet', ['code' => $queries['health'], 'lang' => 'bash'])
                    </div>
                    <div>
                        <div class="fw-semibold mb-1"><span class="fm-method fm-method-get me-1">GET</span> Listado con filtros</div>
                        @include('docs.partials.snippet', ['code' => $queries['list'], 'lang' => 'bash'])
                        <div class="small text-muted-fm mt-1">Devuelve <code>data</code> (sin el bloque <code>result</code>), <code>meta</code> (página, total) y <code>links</code> (siguiente/anterior).</div>
                    </div>
                    <div>
                        <div class="fw-semibold mb-1"><span class="fm-method fm-method-get me-1">GET</span> Una comparación</div>
                        @include('docs.partials.snippet', ['code' => $queries['show'], 'lang' => 'bash'])
                    </div>
                </div>
            </section>

            {{-- Errores --}}
            <section class="fm-card p-3 p-lg-4" id="errores">
                <h2 class="h5 fw-bold mb-3"><i class="bi bi-exclamation-octagon me-1"></i> Códigos de respuesta</h2>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr class="small text-muted-fm"><th>Código</th><th>Significado</th><th>Qué hacer</th></tr></thead>
                        <tbody class="small">
                        <tr><td><code>201</code></td><td>Comparación creada (aunque no coincidan).</td><td>Revisa <code>data.passed</code>.</td></tr>
                        <tr><td><code>200</code></td><td>Consulta correcta.</td><td>—</td></tr>
                        <tr><td><code>401</code></td><td>Token ausente o incorrecto.</td><td>Revisa la cabecera <code>Authorization</code>.</td></tr>
                        <tr><td><code>404</code></td><td>El UUID no existe.</td><td>—</td></tr>
                        <tr><td><code>422</code></td><td>Datos inválidos; <code>errors</code> trae los mensajes por campo.</td><td>Corrige y reintenta.</td></tr>
                        <tr><td><code>429</code></td><td>Límite de peticiones excedido.</td><td>Espera <code>Retry-After</code> segundos.</td></tr>
                        <tr><td><code>502</code></td><td>El motor facial falló (imagen corrupta, servicio caído…).</td><td>Lee <code>message</code>; reintenta.</td></tr>
                        <tr><td><code>503</code></td><td>La API está deshabilitada (sin <code>FACE_API_TOKEN</code>).</td><td>Configura el token.</td></tr>
                        </tbody>
                    </table>
                </div>
                @include('docs.partials.snippet', ['class' => 'mt-3', 'lang' => 'json', 'code' => ApiExamples::validationError()])
            </section>

            {{-- Recibir --}}
            <section class="fm-card p-3 p-lg-4" id="recibir">
                <h2 class="h5 fw-bold mb-3"><i class="bi bi-send me-1"></i> Recibir resultados en tu otro proyecto</h2>
                <p>
                    Si configuras <code>FACE_FORWARD_URL</code>, FaceMatch hace un <code>POST multipart/form-data</code> a esa URL
                    después de cada comparación (web o API), con el resultado y las dos imágenes.
                </p>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <div class="small text-muted-fm">URL configurada</div>
                        @if ($forwardUrl)
                            <code class="text-break">{{ $forwardUrl }}</code>
                        @else
                            <span class="text-muted-fm">Sin configurar</span>
                        @endif
                    </div>
                    <div class="col-md-6">
                        <div class="small text-muted-fm">Cuándo se envía</div>
                        {{ config('facematch.forward.only_matches', true) ? 'Solo resultados aprobados' : 'Siempre' }}
                        <span class="text-muted-fm">(<code>FACE_FORWARD_ONLY_MATCHES</code>)</span>
                    </div>
                </div>
                <div class="table-responsive mb-3">
                    <table class="table table-sm mb-0">
                        <thead><tr class="small text-muted-fm"><th>Parte</th><th>Contenido</th></tr></thead>
                        <tbody class="small">
                        <tr><td><code>Authorization</code></td><td>Cabecera <code>Bearer &lt;FACE_FORWARD_TOKEN&gt;</code>: valídala en el receptor.</td></tr>
                        <tr><td><code>X-FaceMatch-Uuid</code></td><td>Cabecera con el UUID de la comparación.</td></tr>
                        <tr><td><code>payload</code></td><td>Campo de texto con JSON: <code>uuid</code>, <code>mode</code>, <code>origin</code>, <code>engine</code>, <code>is_match</code>, <code>passed</code>, <code>similarity</code>, <code>threshold</code>, <code>failure_reasons</code>, <code>result</code>, <code>compared_at</code>, <code>source_url</code>, <code>target_url</code>, <code>image_urls_expire_at</code>.</td></tr>
                        <tr><td><code>source_image</code></td><td>Archivo: imagen de referencia.</td></tr>
                        <tr><td><code>target_image</code></td><td>Archivo: imagen comparada.</td></tr>
                        </tbody>
                    </table>
                </div>
                <p class="small text-muted-fm">
                    Responde con un código <code>2xx</code> para que quede como <em>Enviada</em>; cualquier otro código o un error de conexión
                    queda como <em>Falló</em> y se puede reintentar con «Enviar ahora» en el detalle. Haz tu receptor idempotente por <code>uuid</code>.
                </p>

                <ul class="nav nav-pills mb-3 flex-wrap gap-1" role="tablist">
                    @foreach ($receivers as $key => $receiver)
                        <li class="nav-item" role="presentation">
                            <button class="nav-link @if($loop->first) active @endif" data-bs-toggle="pill" data-bs-target="#receiver-{{ $key }}"
                                    type="button" role="tab" aria-controls="receiver-{{ $key }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}">
                                {{ $receiver['label'] }}
                            </button>
                        </li>
                    @endforeach
                </ul>
                <div class="tab-content">
                    @foreach ($receivers as $key => $receiver)
                        <div class="tab-pane fade @if($loop->first) show active @endif" id="receiver-{{ $key }}" role="tabpanel">
                            @include('docs.partials.snippet', ['code' => $receiver['code'], 'lang' => $receiver['lang']])
                        </div>
                    @endforeach
                </div>
            </section>

            {{-- Registros --}}
            <section class="fm-card p-3 p-lg-4" id="registros">
                <h2 class="h5 fw-bold mb-3"><i class="bi bi-journal-text me-1"></i> Registros y trazabilidad</h2>
                <ul class="mb-0">
                    <li>Cada respuesta incluye la cabecera <code>X-Request-Id</code>. Si tú envías una, FaceMatch la respeta, así puedes seguir una petición entre ambos sistemas.</li>
                    <li>Cada comparación, envío, petición a la API y fallo queda en
                        <code>storage/logs/facematch-AAAA-MM-DD.log</code> (una línea JSON por evento, {{ (int) config('facematch.log.days', 30) }} días).</li>
                    <li>Consúltalos con filtros en <a href="{{ route('logs.index') }}">Registros</a>: busca por UUID, request id o IP.</li>
                </ul>
            </section>
        </div>
    </div>
</div>
@endsection
