<div class="fm-snippet {{ $class ?? '' }}">
    <button type="button" class="btn btn-sm btn-outline-secondary fm-copy" data-copy-snippet aria-label="Copiar código">
        <i class="bi bi-clipboard"></i> Copiar
    </button>
    <pre class="fm-code mb-0" data-lang="{{ $lang ?? 'text' }}">{{ $code }}</pre>
</div>
