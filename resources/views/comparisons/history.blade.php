@extends('layouts.app')

@section('title', 'Historial')

@section('content')
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-4 fm-reveal">
        <div>
            <h1 class="h3 fw-bold mb-0">Historial</h1>
            <div class="small text-muted-fm">{{ number_format($comparisons->total()) }} comparaciones registradas</div>
        </div>
        <a href="{{ route('home') }}" class="btn btn-gradient"><i class="bi bi-plus-lg me-1"></i> Nueva comparación</a>
    </div>

    @if ($comparisons->isEmpty())
        <div class="fm-card p-5 text-center text-muted-fm">
            <i class="bi bi-inbox fs-1 d-block mb-2"></i>
            No hay comparaciones todavía.
        </div>
    @else
        <div class="fm-card fm-reveal fm-reveal-2 overflow-hidden">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                    <tr class="small text-muted-fm">
                        <th class="ps-4">Imágenes</th>
                        <th>Resultado</th>
                        <th>Similitud</th>
                        <th class="d-none d-md-table-cell">Modo</th>
                        <th class="d-none d-lg-table-cell">Motor</th>
                        <th class="d-none d-md-table-cell">Envío</th>
                        <th class="d-none d-sm-table-cell">Fecha</th>
                        <th class="pe-4 text-end">Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($comparisons as $comparison)
                        <tr>
                            <td class="ps-4">
                                <a href="{{ route('comparisons.show', $comparison) }}" class="d-inline-flex gap-1">
                                    <img src="{{ $comparison->sourceUrl() }}" class="fm-thumb-sm" alt="Origen" loading="lazy">
                                    <img src="{{ $comparison->targetUrl() }}" class="fm-thumb-sm" alt="Comparada" loading="lazy">
                                </a>
                            </td>
                            <td>
                                @if ($comparison->successful())
                                    <span class="fm-chip fm-chip-success"><i class="bi bi-check2-circle"></i> {{ $comparison->isVerify() ? 'Verificada' : 'Coinciden' }}</span>
                                @else
                                    <span class="fm-chip fm-chip-danger"><i class="bi bi-x-circle"></i> {{ $comparison->isVerify() ? 'Rechazada' : 'No coinciden' }}</span>
                                @endif
                            </td>
                            <td class="fw-bold">{{ number_format($comparison->similarity, 1) }} %
                                <div class="small text-muted-fm fw-normal">umbral {{ number_format($comparison->threshold, 0) }} %</div>
                            </td>
                            <td class="d-none d-md-table-cell">{{ $comparison->isVerify() ? 'Verificar' : 'Comparar' }}
                                <div class="small text-muted-fm">{{ $comparison->origin === 'api' ? 'API' : 'Web' }}</div>
                            </td>
                            <td class="d-none d-lg-table-cell small">{{ $comparison->engineLabel() }}</td>
                            <td class="d-none d-md-table-cell small">
                                @switch($comparison->forward_status)
                                    @case('sent') <span class="text-success"><i class="bi bi-send-check"></i> Enviada</span> @break
                                    @case('failed') <span class="text-danger"><i class="bi bi-send-x"></i> Falló</span> @break
                                    @case('skipped') <span class="text-muted-fm"><i class="bi bi-skip-forward"></i> No enviada</span> @break
                                    @default <span class="text-warning"><i class="bi bi-hourglass"></i> Pendiente</span>
                                @endswitch
                            </td>
                            <td class="d-none d-sm-table-cell small text-muted-fm" title="{{ $comparison->created_at->format('d/m/Y H:i:s') }}">
                                {{ $comparison->created_at->diffForHumans() }}
                            </td>
                            <td class="pe-4 text-end text-nowrap">
                                <a href="{{ route('comparisons.show', $comparison) }}" class="btn btn-sm btn-outline-secondary" title="Ver">
                                    <i class="bi bi-eye"></i><span class="visually-hidden">Ver</span>
                                </a>
                                <button type="button" class="btn btn-sm btn-outline-danger" title="Eliminar"
                                        data-bs-toggle="modal" data-bs-target="#delete-modal"
                                        data-action="{{ route('comparisons.destroy', $comparison) }}">
                                    <i class="bi bi-trash"></i><span class="visually-hidden">Eliminar</span>
                                </button>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-4 d-flex justify-content-center">
            {{ $comparisons->links() }}
        </div>
    @endif

    <div class="modal fade" id="delete-modal" tabindex="-1" aria-labelledby="delete-modal-title" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content fm-card" method="POST" action="#" data-loading>
                @csrf
                @method('DELETE')
                <div class="modal-header border-0">
                    <h2 class="modal-title h5 fw-bold" id="delete-modal-title"><i class="bi bi-trash text-danger me-1"></i> Eliminar comparación</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body pt-0 text-muted-fm">
                    Se borrarán el registro y las dos imágenes. Esta acción no se puede deshacer.
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger">Eliminar</button>
                </div>
            </form>
        </div>
    </div>
@endsection
