@extends('layouts.app')

@section('title', 'Comparar rostros')

@section('content')
    {{-- Hero --}}
    <section class="fm-hero row align-items-center g-4 mb-4 fm-reveal">
        <div class="col-lg-7">
            <div class="d-flex flex-wrap gap-2 mb-3">
                @if ($service !== null)
                    @if ($service['available'])
                        <span class="fm-chip fm-chip-success" data-bs-toggle="tooltip"
                              title="Servicio persistente en {{ $service['url'] }} · {{ $service['workers'] }} workers · modelos cargados en {{ $service['models_load_ms'] }} ms · {{ $service['handled'] }} análisis">
                            <span class="dot"></span> Modo rápido
                        </span>
                    @else
                        <span class="fm-chip fm-chip-warning" data-bs-toggle="tooltip"
                              title="Cada análisis inicia Node y carga los modelos. Ejecuta el servicio para respuestas en menos de un segundo.">
                            <i class="bi bi-hourglass-split"></i> Modo lento · ejecuta <code class="ms-1">php artisan face:serve</code>
                        </span>
                    @endif
                    <span class="fm-chip"><i class="bi bi-wifi-off"></i> Sin conexión · gratis</span>
                @else
                    <span class="fm-chip {{ $faceEngine['simulated'] ? 'fm-chip-warning' : 'fm-chip-accent' }}">
                        <i class="bi bi-cloud"></i> {{ $faceEngine['label'] }}
                    </span>
                @endif
                <span class="fm-chip"><i class="bi bi-shield-lock"></i> Imágenes privadas</span>
            </div>
            <h1 class="display-5 mb-3">Compara dos rostros <span class="fm-gradient-text">en segundos</span></h1>
            <p class="lead text-muted-fm mb-0">
                Sube una foto de referencia y otra para comparar. FaceMatch mide la similitud, valida la calidad
                de cada imagen y envía el resultado —con ambas fotos— a tu otro proyecto.
            </p>
        </div>
        <div class="col-lg-5">
            <div class="row g-3">
                @foreach ([
                    ['Comparaciones', $stats['total'], 'bi-collection'],
                    ['Coincidencias', $stats['matches'], 'bi-person-check'],
                    ['Enviadas', $stats['sent'], 'bi-send-check'],
                ] as $i => [$label, $value, $icon])
                    <div class="col-4">
                        <div class="fm-card fm-stat h-100 fm-reveal fm-reveal-{{ $i + 2 }}">
                            <div class="icon mb-2"><i class="bi {{ $icon }}"></i></div>
                            <div class="value">{{ number_format($value) }}</div>
                            <div class="small text-muted-fm">{{ $label }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Formulario --}}
    <form id="compare-form" method="POST" action="{{ route('comparisons.store') }}" enctype="multipart/form-data"
          class="fm-card p-3 p-lg-4 mb-5 fm-reveal fm-reveal-2" novalidate>
        @csrf
        <div class="row g-4">
            <div class="col-lg-8">
                <div class="row g-3 align-items-center">
                    <div class="col">
                        <label class="fm-drop @error('source_image') is-invalid @enderror" data-dropzone>
                            <span class="badge text-bg-dark badge-label"><i class="bi bi-1-circle me-1"></i>Origen</span>
                            <button type="button" class="btn btn-sm btn-light rounded-circle clear-btn" aria-label="Quitar imagen">
                                <i class="bi bi-x-lg"></i>
                            </button>
                            <img class="preview" alt="Vista previa de la imagen de origen">
                            <div class="fm-scan"></div>
                            <div class="fm-placeholder p-3">
                                <div class="placeholder-icon"><i class="bi bi-person-square"></i></div>
                                <div class="fw-semibold">Imagen de referencia</div>
                                <div class="small text-muted-fm">Arrastra, pega o haz clic<br>JPG o PNG · máx. 5 MB</div>
                            </div>
                            <div class="file-name"></div>
                            <input type="file" name="source_image" accept="image/jpeg,image/png" required>
                        </label>
                    </div>

                    <div class="col-auto px-0">
                        <button type="button" class="btn fm-swap" data-swap data-bs-toggle="tooltip" title="Intercambiar imágenes"
                                aria-label="Intercambiar imágenes">
                            <i class="bi bi-arrow-left-right"></i>
                        </button>
                    </div>

                    <div class="col">
                        <label class="fm-drop @error('target_image') is-invalid @enderror" data-dropzone>
                            <span class="badge text-bg-dark badge-label"><i class="bi bi-2-circle me-1"></i>Comparar</span>
                            <button type="button" class="btn btn-sm btn-light rounded-circle clear-btn" aria-label="Quitar imagen">
                                <i class="bi bi-x-lg"></i>
                            </button>
                            <img class="preview" alt="Vista previa de la imagen a comparar">
                            <div class="fm-scan"></div>
                            <div class="fm-placeholder p-3">
                                <div class="placeholder-icon"><i class="bi bi-camera"></i></div>
                                <div class="fw-semibold">Imagen a comparar</div>
                                <div class="small text-muted-fm">Arrastra, pega o haz clic<br>JPG o PNG · máx. 5 MB</div>
                            </div>
                            <div class="file-name"></div>
                            <input type="file" name="target_image" accept="image/jpeg,image/png" required>
                        </label>
                    </div>
                </div>
            </div>

            <div class="col-lg-4 d-flex flex-column gap-4">
                <div>
                    <div class="fw-semibold mb-2">Modo</div>
                    <div class="fm-mode d-grid gap-2">
                        <input type="radio" class="btn-check" name="mode" id="mode-compare" value="compare"
                               data-hint="Solo mide la similitud entre los dos rostros."
                               @checked(old('mode', 'compare') === 'compare')>
                        <label class="btn" for="mode-compare">
                            <i class="bi bi-arrow-left-right me-1"></i> <strong>Comparar</strong>
                            <small>Similitud rápida entre dos rostros</small>
                        </label>

                        <input type="radio" class="btn-check" name="mode" id="mode-verify" value="verify"
                               data-hint="Además valida rostro único, nitidez, brillo, pose, ojos abiertos y tamaño."
                               @checked(old('mode') === 'verify')>
                        <label class="btn" for="mode-verify">
                            <i class="bi bi-shield-check me-1"></i> <strong>Verificar</strong>
                            <small>Identidad + controles de calidad</small>
                        </label>
                    </div>
                    <div class="small text-muted-fm mt-2" id="mode-hint">
                        {{ old('mode') === 'verify' ? 'Además valida rostro único, nitidez, brillo, pose, ojos abiertos y tamaño.' : 'Solo mide la similitud entre los dos rostros.' }}
                    </div>
                </div>

                <div>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label for="threshold" class="fw-semibold mb-0">Umbral de similitud</label>
                        <span class="fm-threshold-value" id="threshold-value">{{ (int) old('threshold', $defaultThreshold) }} %</span>
                    </div>
                    <input type="range" class="form-range" id="threshold" name="threshold" min="50" max="99" step="1"
                           value="{{ old('threshold', $defaultThreshold) }}">
                    <div class="d-flex justify-content-between small text-muted-fm">
                        <span>Permisivo</span><span>Estricto</span>
                    </div>
                </div>

                <div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="forward" name="forward" value="1"
                               @checked($forwardConfigured && (old() ? old('forward') : true)) @disabled(! $forwardConfigured)>
                        <label class="form-check-label fw-semibold" for="forward">Enviar al otro proyecto</label>
                    </div>
                    <div class="small text-muted-fm mt-1">
                        @if ($forwardConfigured)
                            {{ $onlyMatches ? 'Solo se envían los resultados exitosos (FACE_FORWARD_ONLY_MATCHES).' : 'Se envía el resultado con ambas imágenes.' }}
                        @else
                            <i class="bi bi-info-circle"></i> Configura <code>FACE_FORWARD_URL</code> para habilitarlo.
                        @endif
                    </div>
                </div>

                <button type="submit" class="btn btn-gradient btn-lg w-100 mt-auto" data-submit data-text="Comparar rostros">
                    <span class="spinner-border spinner-border-sm me-2 d-none" aria-hidden="true"></span>
                    <i class="bi bi-stars me-1"></i>
                    <span data-label>Comparar rostros</span>
                </button>
            </div>
        </div>
    </form>

    {{-- Recientes --}}
    <section class="fm-reveal fm-reveal-3">
        <div class="d-flex justify-content-between align-items-end mb-3">
            <div>
                <h2 class="h4 fw-bold mb-0">Comparaciones recientes</h2>
                <div class="small text-muted-fm">Las últimas {{ $recent->count() }} comparaciones</div>
            </div>
            @if ($stats['total'] > 0)
                <a href="{{ route('comparisons.history') }}" class="btn btn-sm btn-outline-secondary">
                    Ver historial <i class="bi bi-arrow-right"></i>
                </a>
            @endif
        </div>

        @if ($recent->isEmpty())
            <div class="fm-card p-5 text-center text-muted-fm">
                <i class="bi bi-images fs-1 d-block mb-2"></i>
                Aún no hay comparaciones. ¡Sube dos fotos para empezar!
            </div>
        @else
            <div class="row g-3">
                @foreach ($recent as $comparison)
                    <div class="col-sm-6 col-lg-4 col-xl-2">
                        @include('comparisons.partials.card', ['comparison' => $comparison])
                    </div>
                @endforeach
            </div>
        @endif
    </section>
@endsection
