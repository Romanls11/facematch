import * as bootstrap from 'bootstrap';

window.bootstrap = bootstrap;

/* ------------------------------------------------------------------
   Tema claro / oscuro
   ------------------------------------------------------------------ */

const THEME_KEY = 'facematch-theme';

function preferredTheme() {
    try {
        const saved = localStorage.getItem(THEME_KEY);
        if (saved === 'light' || saved === 'dark') return saved;
    } catch {
        /* almacenamiento no disponible */
    }
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

function applyTheme(theme) {
    document.documentElement.setAttribute('data-bs-theme', theme);
    document.querySelectorAll('[data-theme-icon]').forEach((icon) => {
        icon.className = theme === 'dark' ? 'bi bi-sun-fill' : 'bi bi-moon-stars-fill';
    });
    document.querySelectorAll('[data-theme-toggle]').forEach((btn) => {
        btn.setAttribute('title', theme === 'dark' ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro');
    });
}

function initTheme() {
    applyTheme(preferredTheme());
    document.querySelectorAll('[data-theme-toggle]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const next = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
            try {
                localStorage.setItem(THEME_KEY, next);
            } catch {
                /* sin persistencia */
            }
            applyTheme(next);
        });
    });
}

/* ------------------------------------------------------------------
   Toasts
   ------------------------------------------------------------------ */

const TOAST_STYLES = {
    success: { icon: 'bi-check-circle-fill', color: 'var(--fm-success)', title: 'Listo' },
    warning: { icon: 'bi-exclamation-triangle-fill', color: 'var(--fm-warning)', title: 'Atención' },
    error: { icon: 'bi-x-octagon-fill', color: 'var(--fm-danger)', title: 'Error' },
    info: { icon: 'bi-info-circle-fill', color: 'var(--fm-accent)', title: 'Aviso' },
};

export function toast(message, type = 'info') {
    const container = document.getElementById('fm-toasts');
    if (!container) return;
    const style = TOAST_STYLES[type] || TOAST_STYLES.info;

    const el = document.createElement('div');
    el.className = 'toast align-items-center';
    el.setAttribute('role', type === 'error' ? 'alert' : 'status');
    el.setAttribute('aria-live', type === 'error' ? 'assertive' : 'polite');
    el.innerHTML = `
        <div class="d-flex align-items-start gap-2 p-3">
            <i class="bi ${style.icon} fs-5" style="color:${style.color}"></i>
            <div class="flex-grow-1">
                <div class="fw-semibold small">${style.title}</div>
                <div class="small text-body-secondary" data-message></div>
            </div>
            <button type="button" class="btn-close btn-sm" data-bs-dismiss="toast" aria-label="Cerrar"></button>
        </div>`;
    el.querySelector('[data-message]').textContent = message;
    container.appendChild(el);

    const instance = bootstrap.Toast.getOrCreateInstance(el, { delay: type === 'error' ? 9000 : 5500 });
    el.addEventListener('hidden.bs.toast', () => el.remove());
    instance.show();
}

window.fmToast = toast;

function initFlashToasts() {
    const data = document.getElementById('fm-flash');
    if (!data) return;
    try {
        JSON.parse(data.textContent).forEach(([type, message]) => toast(message, type));
    } catch {
        /* sin mensajes */
    }
}

/* ------------------------------------------------------------------
   Zonas para arrastrar imágenes
   ------------------------------------------------------------------ */

const MAX_BYTES = 5 * 1024 * 1024;
const ALLOWED = ['image/jpeg', 'image/png'];

function initDropzones() {
    const zones = Array.from(document.querySelectorAll('[data-dropzone]'));
    if (!zones.length) return;

    const setFile = (zone, file) => {
        const input = zone.querySelector('input[type=file]');
        const img = zone.querySelector('img.preview');
        const name = zone.querySelector('.file-name');

        if (!file) {
            input.value = '';
            zone.classList.remove('has-image');
            if (img.src) URL.revokeObjectURL(img.src);
            img.removeAttribute('src');
            zone._file = null;
            return;
        }

        if (!ALLOWED.includes(file.type)) {
            toast('Solo se aceptan imágenes JPG o PNG.', 'error');
            return;
        }
        if (file.size > MAX_BYTES) {
            toast(`«${file.name}» pesa más de 5 MB.`, 'error');
            return;
        }

        const dt = new DataTransfer();
        dt.items.add(file);
        input.files = dt.files;

        if (img.src) URL.revokeObjectURL(img.src);
        img.src = URL.createObjectURL(file);
        name.textContent = `${file.name} · ${(file.size / 1024).toFixed(0)} KB`;
        zone.classList.add('has-image');
        zone.classList.remove('is-invalid');
        zone._file = file;
    };

    zones.forEach((zone) => {
        const input = zone.querySelector('input[type=file]');

        input.addEventListener('change', () => setFile(zone, input.files[0] || null));

        ['dragenter', 'dragover'].forEach((evt) =>
            zone.addEventListener(evt, (e) => {
                e.preventDefault();
                zone.classList.add('is-dragover');
            }),
        );
        ['dragleave', 'dragend', 'drop'].forEach((evt) =>
            zone.addEventListener(evt, () => zone.classList.remove('is-dragover')),
        );
        zone.addEventListener('drop', (e) => {
            e.preventDefault();
            const file = e.dataTransfer.files[0];
            if (file) setFile(zone, file);
        });

        zone.querySelector('.clear-btn')?.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            setFile(zone, null);
        });
    });

    // Pegar una imagen desde el portapapeles en la primera zona libre.
    document.addEventListener('paste', (e) => {
        const file = Array.from(e.clipboardData?.files || []).find((f) => f.type.startsWith('image/'));
        if (!file) return;
        const target = zones.find((z) => !z._file) || zones[zones.length - 1];
        setFile(target, file);
    });

    document.querySelector('[data-swap]')?.addEventListener('click', () => {
        const [a, b] = zones;
        const fa = a._file || null;
        const fb = b._file || null;
        setFile(a, null);
        setFile(b, null);
        if (fb) setFile(a, fb);
        if (fa) setFile(b, fa);
    });

    // Envío: validación rápida, spinner y animación de análisis.
    const form = document.getElementById('compare-form');
    form?.addEventListener('submit', (e) => {
        const missing = zones.filter((z) => !z.querySelector('input[type=file]').files.length);
        if (missing.length) {
            e.preventDefault();
            missing.forEach((z) => z.classList.add('is-invalid'));
            toast('Selecciona las dos imágenes antes de comparar.', 'warning');
            return;
        }
        const btn = form.querySelector('[data-submit]');
        btn.disabled = true;
        btn.querySelector('.spinner-border').classList.remove('d-none');
        btn.querySelector('[data-label]').textContent = 'Analizando rostros…';
        form.classList.add('is-analyzing');
    });

    // Si el usuario vuelve con el botón Atrás, restablecer el botón.
    window.addEventListener('pageshow', () => {
        const btn = form?.querySelector('[data-submit]');
        if (!btn) return;
        btn.disabled = false;
        btn.querySelector('.spinner-border').classList.add('d-none');
        btn.querySelector('[data-label]').textContent = btn.dataset.text;
        form.classList.remove('is-analyzing');
    });
}

/* ------------------------------------------------------------------
   Formulario: umbral y modo
   ------------------------------------------------------------------ */

function initFormControls() {
    const range = document.getElementById('threshold');
    const output = document.getElementById('threshold-value');
    if (range && output) {
        const sync = () => (output.textContent = `${Number(range.value).toFixed(0)} %`);
        range.addEventListener('input', sync);
        sync();
    }

    const hint = document.getElementById('mode-hint');
    document.querySelectorAll('input[name=mode]').forEach((radio) =>
        radio.addEventListener('change', () => {
            if (hint) hint.textContent = radio.dataset.hint;
        }),
    );
}

/* ------------------------------------------------------------------
   Resultado: medidor y barra animados
   ------------------------------------------------------------------ */

function initResult() {
    document.querySelectorAll('[data-gauge]').forEach((gauge) => {
        const value = Number(gauge.dataset.value);
        const bar = gauge.querySelector('.bar');
        const label = gauge.querySelector('[data-gauge-value]');
        const circumference = 2 * Math.PI * Number(bar.getAttribute('r'));
        bar.style.strokeDasharray = `${circumference}`;
        bar.style.strokeDashoffset = `${circumference}`;

        requestAnimationFrame(() =>
            requestAnimationFrame(() => {
                bar.style.strokeDashoffset = `${circumference * (1 - value / 100)}`;
            }),
        );

        const started = performance.now();
        const duration = 1400;
        const tick = (now) => {
            const t = Math.min(1, (now - started) / duration);
            const eased = 1 - Math.pow(1 - t, 3);
            label.textContent = (value * eased).toFixed(1);
            if (t < 1) requestAnimationFrame(tick);
        };
        requestAnimationFrame(tick);
    });

    document.querySelectorAll('[data-meter]').forEach((meter) => {
        const fill = meter.querySelector('.fill');
        requestAnimationFrame(() => requestAnimationFrame(() => (fill.style.width = `${meter.dataset.value}%`)));
    });

    document.querySelectorAll('[data-copy]').forEach((btn) =>
        btn.addEventListener('click', async () => {
            const source = document.querySelector(btn.dataset.copy);
            try {
                await navigator.clipboard.writeText(source.textContent);
                toast('JSON copiado al portapapeles.', 'success');
            } catch {
                toast('No se pudo copiar.', 'error');
            }
        }),
    );
}

/* ------------------------------------------------------------------
   Varios
   ------------------------------------------------------------------ */

function initTooltips() {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => new bootstrap.Tooltip(el));
}

function initDeleteModal() {
    const modal = document.getElementById('delete-modal');
    if (!modal) return;
    modal.addEventListener('show.bs.modal', (e) => {
        const trigger = e.relatedTarget;
        modal.querySelector('form').action = trigger.dataset.action;
    });
}

function initLoadingButtons() {
    document.querySelectorAll('form[data-loading]').forEach((form) =>
        form.addEventListener('submit', () => {
            const btn = form.querySelector('button[type=submit]');
            if (!btn) return;
            btn.disabled = true;
            btn.insertAdjacentHTML('afterbegin', '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>');
        }),
    );
}

/** Botones «Copiar» de los bloques de código (data-copy-snippet). */
function initCopyButtons() {
    document.querySelectorAll('[data-copy-snippet]').forEach((btn) =>
        btn.addEventListener('click', async () => {
            const code = btn.closest('.fm-snippet')?.querySelector('pre');
            if (!code) return;
            try {
                await navigator.clipboard.writeText(code.innerText);
                toast('Copiado al portapapeles', 'success');
            } catch {
                toast('No se pudo copiar; selecciona el texto manualmente', 'warning');
            }
        }),
    );
}

document.addEventListener('DOMContentLoaded', () => {
    initTheme();
    initFlashToasts();
    initDropzones();
    initFormControls();
    initResult();
    initTooltips();
    initDeleteModal();
    initLoadingButtons();
    initCopyButtons();
});
