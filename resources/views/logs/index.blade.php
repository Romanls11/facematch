@extends('layouts.app')

@section('title', 'Registros')

@php
    $levelChip = fn (string $level) => match ($level) {
        'error', 'critical', 'alert', 'emergency' => ['fm-chip-danger', 'bi-x-octagon', 'Error'],
        'warning' => ['fm-chip-warning', 'bi-exclamation-triangle', 'Aviso'],
        'debug' => ['', 'bi-bug', 'Detalle'],
        default => ['fm-chip-success', 'bi-info-circle', 'Info'],
    };
    $eventLabels = [
        'comparison.created' => 'Comparación',
        'comparison.failed' => 'Comparación fallida',
        'comparison.deleted' => 'Eliminación',
        'forward.sent' => 'Envío',
        'forward.failed' => 'Envío fallido',
        'forward.skipped' => 'Envío omitido',
        'api.request' => 'Petición API',
        'engine.fallback' => 'Modo lento',
        'auth.login' => 'Inicio de sesión',
        'auth.logout' => 'Cierre de sesión',
        'auth.failed' => 'Acceso fallido',
        'auth.locked' => 'Acceso bloqueado',
    ];
@endphp

@section('content')
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-4 fm-reveal">
        <div>
            <h1 class="h3 fw-bold mb-0">Registros</h1>
            <div class="small text-muted-fm">
                Actividad del {{ \Illuminate\Support\Carbon::parse($date)->translatedFormat('l j \d\e F \d\e Y') }}
                · <code>storage/logs/facematch-{{ $date }}.log</code>
            </div>
        </div>
        <a href="{{ request()->fullUrl() }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-clockwise me-1"></i> Actualizar</a>
    </div>

    @unless ($enabled)
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle me-1"></i>
            El registro está desactivado (<code>FACE_LOG_CHANNEL=null</code>). Quita esa variable del <code>.env</code> para activarlo.
        </div>
    @endunless

    <div class="row g-3 mb-4 fm-reveal fm-reveal-2">
        @foreach ([
            ['Eventos', $summary['total'], 'bi-journal-text', null],
            ['Comparaciones', $summary['comparisons'], 'bi-people', 'comparison'],
            ['Envíos', $summary['forwards'], 'bi-send-check', 'forward'],
            ['Peticiones API', $summary['api'], 'bi-plug', 'api'],
            ['Avisos', $summary['warnings'], 'bi-exclamation-triangle', null],
            ['Errores', $summary['errors'], 'bi-x-octagon', null],
        ] as [$label, $value, $icon, $group])
            <div class="col-6 col-md-4 col-xl-2">
                <div class="fm-card fm-stat h-100">
                    <div class="icon mb-2"><i class="bi {{ $icon }}"></i></div>
                    <div class="value">{{ number_format($value) }}</div>
                    <div class="small text-muted-fm">{{ $label }}</div>
                </div>
            </div>
        @endforeach
    </div>

    <form method="GET" class="fm-card p-3 mb-4 fm-reveal fm-reveal-2">
        <div class="row g-2 align-items-end">
            <div class="col-sm-6 col-lg-2">
                <label for="fecha" class="form-label small fw-semibold mb-1">Fecha</label>
                <select id="fecha" name="fecha" class="form-select">
                    @forelse ($dates as $d)
                        <option value="{{ $d }}" @selected($d === $date)>{{ \Illuminate\Support\Carbon::parse($d)->format('d/m/Y') }}</option>
                    @empty
                        <option value="{{ $date }}">{{ \Illuminate\Support\Carbon::parse($date)->format('d/m/Y') }}</option>
                    @endforelse
                </select>
            </div>
            <div class="col-sm-6 col-lg-2">
                <label for="tipo" class="form-label small fw-semibold mb-1">Tipo</label>
                <select id="tipo" name="tipo" class="form-select">
                    <option value="">Todos</option>
                    @foreach ($groups as $key => $label)
                        <option value="{{ $key }}" @selected($filters['group'] === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-6 col-lg-2">
                <label for="nivel" class="form-label small fw-semibold mb-1">Nivel</label>
                <select id="nivel" name="nivel" class="form-select">
                    <option value="">Todos</option>
                    @foreach (['info' => 'Info', 'warning' => 'Aviso', 'error' => 'Error', 'debug' => 'Detalle'] as $key => $label)
                        <option value="{{ $key }}" @selected($filters['level'] === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-6 col-lg-4">
                <label for="q" class="form-label small fw-semibold mb-1">Buscar</label>
                <input id="q" type="search" name="q" value="{{ $filters['q'] }}" class="form-control"
                       placeholder="UUID, request id, IP, texto…">
            </div>
            <div class="col-lg-2 d-flex gap-2">
                <button class="btn btn-gradient flex-fill"><i class="bi bi-funnel me-1"></i> Filtrar</button>
                @if ($filters['group'] || $filters['level'] || $filters['q'])
                    <a href="{{ route('logs.index', ['fecha' => $date]) }}" class="btn btn-outline-secondary" title="Quitar filtros">
                        <i class="bi bi-x-lg"></i><span class="visually-hidden">Quitar filtros</span>
                    </a>
                @endif
            </div>
        </div>
    </form>

    @if (empty($result['entries']))
        <div class="fm-card p-5 text-center text-muted-fm">
            <i class="bi bi-journal-x fs-1 d-block mb-2"></i>
            No hay eventos {{ $filters['group'] || $filters['level'] || $filters['q'] ? 'que coincidan con los filtros' : 'para esta fecha' }}.
        </div>
    @else
        <div class="small text-muted-fm mb-2">
            {{ number_format($result['total']) }} {{ $result['total'] === 1 ? 'evento' : 'eventos' }}
            @if ($result['truncated']) · se muestran los {{ count($result['entries']) }} más recientes @endif
        </div>

        <div class="fm-card overflow-hidden fm-reveal fm-reveal-3">
            <ul class="list-group list-group-flush fm-log">
                @foreach ($result['entries'] as $entry)
                    @php [$chipClass, $chipIcon, $chipLabel] = $levelChip($entry['level']); @endphp
                    <li class="list-group-item bg-transparent px-3 px-lg-4 py-3">
                        <details>
                            <summary class="d-flex flex-wrap align-items-center gap-2">
                                <span class="font-monospace small text-muted-fm" title="{{ $entry['time']?->format('d/m/Y H:i:s.v') }}">
                                    {{ $entry['time']?->format('H:i:s') }}
                                </span>
                                <span class="fm-chip {{ $chipClass }}"><i class="bi {{ $chipIcon }}"></i> {{ $chipLabel }}</span>
                                <span class="badge text-bg-secondary fw-normal">{{ $eventLabels[$entry['event']] ?? $entry['event'] }}</span>
                                <span class="flex-fill">{{ $entry['message'] }}</span>
                                @if ($entry['uuid'])
                                    <a href="{{ route('comparisons.show', $entry['uuid']) }}" class="small text-nowrap" title="Ver comparación">
                                        <i class="bi bi-box-arrow-up-right"></i> {{ \Illuminate\Support\Str::limit($entry['uuid'], 13, '…') }}
                                    </a>
                                @endif
                            </summary>
                            <div class="mt-3">
                                <div class="small text-muted-fm mb-2">
                                    Evento <code>{{ $entry['event'] }}</code>
                                    @if ($entry['request_id'])
                                        · petición
                                        <a href="{{ route('logs.index', ['fecha' => $date, 'q' => $entry['request_id']]) }}"><code>{{ $entry['request_id'] }}</code></a>
                                    @endif
                                </div>
                                <pre class="fm-code mb-0">{{ json_encode($entry['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                            </div>
                        </details>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
@endsection
