@php /** @var \App\Models\Comparison $comparison */ @endphp
<a href="{{ route('comparisons.show', $comparison) }}" class="text-decoration-none text-reset d-block h-100">
    <div class="fm-card fm-card-hover h-100">
        <div class="fm-thumbs">
            <img src="{{ $comparison->sourceUrl() }}" alt="Imagen de origen" loading="lazy">
            <img src="{{ $comparison->targetUrl() }}" alt="Imagen comparada" loading="lazy">
        </div>
        <div class="p-3">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="fw-bold fs-5">{{ number_format($comparison->similarity, 1) }} %</span>
                @if ($comparison->successful())
                    <span class="fm-chip fm-chip-success"><i class="bi bi-check2-circle"></i>
                        {{ $comparison->isVerify() ? 'Verificada' : 'Coinciden' }}</span>
                @else
                    <span class="fm-chip fm-chip-danger"><i class="bi bi-x-circle"></i>
                        {{ $comparison->isVerify() ? 'Rechazada' : 'No coinciden' }}</span>
                @endif
            </div>
            <div class="small text-muted-fm d-flex flex-wrap gap-2">
                <span><i class="bi {{ $comparison->isVerify() ? 'bi-shield-check' : 'bi-arrow-left-right' }}"></i>
                    {{ $comparison->isVerify() ? 'Verificar' : 'Comparar' }}</span>
                <span>·</span>
                <span title="{{ $comparison->created_at->format('d/m/Y H:i') }}">{{ $comparison->created_at->diffForHumans() }}</span>
                @if ($comparison->forward_status === 'sent')
                    <span>·</span><span class="text-success"><i class="bi bi-send-check"></i> Enviada</span>
                @endif
            </div>
        </div>
    </div>
</a>
