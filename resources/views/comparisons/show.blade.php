@extends('layouts.app')

@section('title', 'Resultado')

@php
    /** @var \App\Models\Comparison $comparison */
    $ok = $comparison->successful();
    $verify = $comparison->isVerify();
    $similarity = $comparison->similarity;
    $threshold = $comparison->threshold;
    $color = $ok ? 'var(--fm-success)' : 'var(--fm-danger)';
    $r = $comparison->result ?? [];
    $cmp = $r['comparison'] ?? [];
    $sourceFace = $cmp['source_face'] ?? data_get($r, 'source.primary_face');
    $targetFace = $cmp['matched_face'] ?? data_get($r, 'target.primary_face');

    $verdictTitle = $verify
        ? ($ok ? 'Identidad verificada' : 'Identidad rechazada')
        : ($ok ? 'Los rostros coinciden' : 'Los rostros no coinciden');
    $verdictText = $verify
        ? ($ok ? 'Ambas imágenes superaron los controles de calidad y pertenecen a la misma persona.'
               : 'La verificación no se superó. Revisa los motivos a continuación.')
        : ($ok ? 'La similitud supera el umbral configurado: probablemente es la misma persona.'
               : 'La similitud está por debajo del umbral: probablemente son personas distintas.');

    $checkLabels = [
        'human_detected' => 'Rostro humano detectado',
        'single_face' => 'Un solo rostro',
        'sharpness_ok' => 'Nitidez suficiente',
        'brightness_ok' => 'Brillo adecuado',
        'pose_frontal' => 'Rostro de frente',
        'eyes_open' => 'Ojos abiertos',
        'no_occlusion' => 'Sin obstrucciones',
        'min_face_size_ok' => 'Tamaño del rostro',
    ];

    $fmt = fn ($v, $d = 1) => $v === null ? '—' : number_format((float) $v, $d);

    $forwardBadges = [
        'sent' => ['fm-chip-success', 'bi-send-check', 'Enviado'],
        'failed' => ['fm-chip-danger', 'bi-send-x', 'Falló'],
        'skipped' => ['', 'bi-skip-forward', 'No enviado'],
        'pending' => ['fm-chip-warning', 'bi-hourglass', 'Pendiente'],
    ];
    [$fwClass, $fwIcon, $fwLabel] = $forwardBadges[$comparison->forward_status] ?? $forwardBadges['pending'];
@endphp

@section('content')
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4 fm-reveal">
        <div>
            <a href="{{ route('home') }}" class="small text-decoration-none"><i class="bi bi-arrow-left"></i> Nueva comparación</a>
            <h1 class="h3 fw-bold mb-0 mt-1">Resultado de la {{ $verify ? 'verificación' : 'comparación' }}</h1>
            <div class="small text-muted-fm">
                {{ $comparison->created_at->format('d/m/Y H:i:s') }} · {{ $comparison->engineLabel() }}
                · origen {{ $comparison->origin === 'api' ? 'API' : 'web' }}
                · <code class="small">{{ $comparison->uuid }}</code>
            </div>
        </div>
        <span class="fm-chip {{ $verify ? 'fm-chip-accent' : '' }}">
            <i class="bi {{ $verify ? 'bi-shield-check' : 'bi-arrow-left-right' }}"></i> {{ $verify ? 'Verificar' : 'Comparar' }}
        </span>
    </div>

    <div class="row g-4">
        {{-- Veredicto --}}
        <div class="col-lg-5">
            <div class="fm-card p-4 h-100 fm-reveal">
                <div class="d-flex flex-column flex-sm-row align-items-center gap-4">
                    <div class="fm-gauge flex-shrink-0" data-gauge data-value="{{ $similarity }}">
                        <svg viewBox="0 0 120 120" aria-hidden="true">
                            <defs>
                                <linearGradient id="gauge-grad" x1="0" y1="0" x2="1" y2="1">
                                    <stop offset="0%" stop-color="{{ $ok ? '#22c55e' : '#fb7185' }}"/>
                                    <stop offset="100%" stop-color="{{ $ok ? '#22c3ee' : '#f59e0b' }}"/>
                                </linearGradient>
                            </defs>
                            <circle class="track" cx="60" cy="60" r="52" fill="none" stroke-width="10"/>
                            <circle class="bar" cx="60" cy="60" r="52" fill="none" stroke="url(#gauge-grad)" stroke-width="10"/>
                        </svg>
                        <div class="center">
                            <div>
                                <div class="value"><span data-gauge-value>0.0</span><small class="fs-5">%</small></div>
                                <div class="small text-muted-fm">similitud</div>
                            </div>
                        </div>
                    </div>
                    <div class="text-center text-sm-start">
                        <div class="fm-verdict-icon {{ $ok ? 'fm-verdict-ok' : 'fm-verdict-bad' }} mb-2 mx-auto mx-sm-0">
                            <i class="bi {{ $ok ? 'bi-patch-check-fill' : 'bi-x-octagon-fill' }}"></i>
                        </div>
                        <h2 class="h4 fw-bold mb-1" style="color: {{ $color }}">{{ $verdictTitle }}</h2>
                        <p class="text-muted-fm small mb-0">{{ $verdictText }}</p>
                    </div>
                </div>

                <div class="mt-5 mb-3">
                    <div class="fm-meter" data-meter data-value="{{ $similarity }}" role="img"
                         aria-label="Similitud {{ $fmt($similarity) }} %, umbral {{ $fmt($threshold, 0) }} %">
                        <div class="fill" style="background: {{ $color }}"></div>
                        <div class="mark" style="left: calc({{ $threshold }}% - 1.5px)"><span>Umbral {{ $fmt($threshold, 0) }} %</span></div>
                    </div>
                    <div class="d-flex justify-content-between small text-muted-fm mt-1"><span>0 %</span><span>100 %</span></div>
                </div>

                @if (! empty($comparison->failure_reasons))
                    <div class="alert alert-danger small mb-0 mt-3" role="alert">
                        <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle me-1"></i> Motivos de rechazo</div>
                        <ul class="mb-0 ps-3">
                            @foreach ($comparison->failure_reasons as $reason)
                                <li>{{ $reason }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if (($r['simulated'] ?? false))
                    <div class="alert alert-warning small mb-0 mt-3">
                        <i class="bi bi-cone-striped me-1"></i> Resultado simulado (REKOGNITION_TEST_MODE=true): no refleja las imágenes reales.
                    </div>
                @endif
            </div>
        </div>

        {{-- Imágenes con el recuadro del rostro --}}
        <div class="col-lg-7">
            <div class="fm-card p-3 p-lg-4 h-100 fm-reveal fm-reveal-2">
                <div class="row g-3">
                    @foreach ([
                        ['Origen', $comparison->sourceUrl(), $comparison->sourceBox(), 'source'],
                        ['Comparada', $comparison->targetUrl(), $comparison->targetBox(), 'target'],
                    ] as [$label, $url, $box, $key])
                        <div class="col-6">
                            <div class="fm-face">
                                <img src="{{ $url }}" alt="Imagen {{ strtolower($label) }}">
                                @if ($box && $comparison->hasRealBoxes())
                                    <div class="box {{ $ok ? 'ok' : 'bad' }}"
                                         style="left: {{ $box['left'] * 100 }}%; top: {{ $box['top'] * 100 }}%; width: {{ $box['width'] * 100 }}%; height: {{ $box['height'] * 100 }}%;"></div>
                                @endif
                                <span class="badge text-bg-dark label">{{ $label }}</span>
                            </div>
                            <div class="small text-muted-fm mt-2 d-flex justify-content-between">
                                @php $img = $r['images'][$key] ?? null; @endphp
                                @if ($img)
                                    <span>{{ $img['face_count'] }} {{ $img['face_count'] === 1 ? 'rostro' : 'rostros' }}</span>
                                    <span>{{ $img['width'] }}×{{ $img['height'] }} · {{ $img['detector'] ?? '' }}</span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Checks de calidad (Verificar) --}}
        @if ($verify)
            @foreach (['source' => 'Imagen de origen', 'target' => 'Imagen comparada'] as $key => $title)
                @php $realness = $r[$key] ?? null; @endphp
                @if ($realness)
                    <div class="col-md-6">
                        <div class="fm-card p-4 h-100 fm-reveal fm-reveal-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h3 class="h6 fw-bold mb-0">{{ $title }}</h3>
                                @if ($realness['is_real_human_face'] ?? false)
                                    <span class="fm-chip fm-chip-success"><i class="bi bi-check2"></i> Calidad {{ $fmt($realness['quality_score'] ?? 0) }}</span>
                                @else
                                    <span class="fm-chip fm-chip-danger"><i class="bi bi-x"></i> Calidad {{ $fmt($realness['quality_score'] ?? 0) }}</span>
                                @endif
                            </div>
                            @foreach ($checkLabels as $check => $label)
                                @if (array_key_exists($check, $realness['checks'] ?? []))
                                    <div class="fm-check">
                                        <i class="bi {{ $realness['checks'][$check] ? 'bi-check-circle-fill' : 'bi-x-circle-fill' }}"></i>
                                        <span>{{ $label }}</span>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endif
            @endforeach
        @endif

        {{-- Métricas --}}
        <div class="col-12">
            <div class="row g-3 fm-reveal fm-reveal-3">
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="fm-card fm-metric">
                        <div class="label">Similitud</div>
                        <div class="value" style="color: {{ $color }}">{{ $fmt($similarity, 2) }} %</div>
                        <div class="sub">{{ $comparison->is_match ? 'supera el umbral' : 'bajo el umbral' }}</div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="fm-card fm-metric">
                        <div class="label">Umbral</div>
                        <div class="value">{{ $fmt($threshold, 0) }} %</div>
                        <div class="sub">mínimo para coincidir</div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="fm-card fm-metric">
                        <div class="label">Confianza</div>
                        <div class="value">{{ $fmt($cmp['confidence'] ?? null) }} %</div>
                        <div class="sub">detección del rostro</div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="fm-card fm-metric">
                        <div class="label">Nitidez / brillo</div>
                        <div class="value">{{ $fmt(data_get($sourceFace, 'quality.sharpness'), 0) }} / {{ $fmt(data_get($sourceFace, 'quality.brightness'), 0) }}</div>
                        <div class="sub">comparada: {{ $fmt(data_get($targetFace, 'quality.sharpness'), 0) }} / {{ $fmt(data_get($targetFace, 'quality.brightness'), 0) }}</div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="fm-card fm-metric">
                        <div class="label">Pose (yaw · pitch · roll)</div>
                        <div class="value fs-6 mt-1">{{ $fmt(data_get($sourceFace, 'pose.yaw')) }}° · {{ $fmt(data_get($sourceFace, 'pose.pitch')) }}° · {{ $fmt(data_get($sourceFace, 'pose.roll')) }}°</div>
                        <div class="sub">comparada: {{ $fmt(data_get($targetFace, 'pose.yaw')) }}° · {{ $fmt(data_get($targetFace, 'pose.pitch')) }}° · {{ $fmt(data_get($targetFace, 'pose.roll')) }}°</div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="fm-card fm-metric">
                        @if (array_key_exists('distance', $cmp))
                            <div class="label">
                                Distancia
                                <i class="bi bi-info-circle" data-bs-toggle="tooltip"
                                   title="Distancia euclidiana entre los descriptores de 128 valores de cada rostro. Menor = más parecidos. {{ $fmt($cmp['match_distance'] ?? 0.6, 2) }} equivale al 80 % de similitud."></i>
                            </div>
                            <div class="value">{{ $fmt($cmp['distance'], 3) }}</div>
                        @else
                            <div class="label">Rostros extra</div>
                            <div class="value">{{ $cmp['unmatched_count'] ?? 0 }}</div>
                        @endif
                        <div class="sub">
                            @if ($comparison->fromService())
                                <i class="bi bi-lightning-charge-fill text-warning" data-bs-toggle="tooltip" title="Analizado por el servicio persistente (modo rápido)"></i>
                            @endif
                            {{ number_format(($r['elapsed_ms'] ?? 0) / 1000, 2) }} s de análisis
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Proyecto destino --}}
        <div class="col-lg-5">
            <div class="fm-card p-4 h-100 fm-reveal fm-reveal-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h3 class="h6 fw-bold mb-0"><i class="bi bi-diagram-3 me-1"></i> Proyecto destino</h3>
                    <span class="fm-chip {{ $fwClass }}"><i class="bi {{ $fwIcon }}"></i> {{ $fwLabel }}</span>
                </div>

                @if ($forwardConfigured)
                    <div class="small text-muted-fm text-break mb-2"><i class="bi bi-link-45deg"></i> {{ $forwardUrl }}</div>
                @else
                    <div class="small text-muted-fm mb-2"><i class="bi bi-info-circle"></i> Configura <code>FACE_FORWARD_URL</code> en el <code>.env</code> para enviar resultados.</div>
                @endif

                <dl class="row small mb-3">
                    <dt class="col-5 text-muted-fm fw-normal">Código HTTP</dt>
                    <dd class="col-7 mb-1">{{ $comparison->forward_http_code ?? '—' }}</dd>
                    <dt class="col-5 text-muted-fm fw-normal">Enviado</dt>
                    <dd class="col-7 mb-1">{{ $comparison->forwarded_at?->format('d/m/Y H:i:s') ?? '—' }}</dd>
                </dl>

                @if ($comparison->forward_response)
                    <pre class="fm-json small mb-3" style="max-height: 120px">{{ $comparison->forward_response }}</pre>
                @endif

                <form method="POST" action="{{ route('comparisons.forward', $comparison) }}" data-loading>
                    @csrf
                    <button type="submit" class="btn btn-primary w-100" @disabled(! $forwardConfigured)>
                        <i class="bi bi-send me-1"></i> {{ $comparison->forward_status === 'sent' ? 'Reenviar' : 'Enviar ahora' }}
                    </button>
                </form>
                <a href="{{ route('logs.index', ['fecha' => $comparison->created_at->toDateString(), 'q' => $comparison->uuid]) }}"
                   class="btn btn-outline-secondary w-100 mt-2">
                    <i class="bi bi-journal-text me-1"></i> Ver registros
                </a>
            </div>
        </div>

        {{-- JSON completo --}}
        <div class="col-lg-7">
            <div class="fm-card p-4 h-100 fm-reveal fm-reveal-4">
                <div class="d-flex justify-content-between align-items-center">
                    <button class="btn btn-link text-reset text-decoration-none p-0 fw-bold" type="button"
                            data-bs-toggle="collapse" data-bs-target="#raw-json" aria-expanded="false" aria-controls="raw-json">
                        <i class="bi bi-braces me-1"></i> JSON completo <i class="bi bi-chevron-down small"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-copy="#raw-json-content">
                        <i class="bi bi-clipboard"></i> Copiar
                    </button>
                </div>
                <div class="collapse mt-3" id="raw-json">
                    <pre class="fm-json" id="raw-json-content">{{ json_encode($comparison->result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                </div>
            </div>
        </div>
    </div>
@endsection
