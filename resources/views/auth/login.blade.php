<!DOCTYPE html>
<html lang="es" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Iniciar sesión · FaceMatch</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🧑‍🤝‍🧑</text></svg>">
    <script>
        (function () {
            var t = null;
            try { t = localStorage.getItem('facematch-theme'); } catch (e) {}
            if (t !== 'light' && t !== 'dark') {
                t = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }
            document.documentElement.setAttribute('data-bs-theme', t);
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="fm-auth">
<div class="position-absolute top-0 end-0 p-3">
    <button type="button" class="btn btn-outline-secondary border-0 fm-theme-toggle" data-theme-toggle
            aria-label="Cambiar tema claro u oscuro">
        <i class="bi bi-moon-stars-fill" data-theme-icon></i>
    </button>
</div>

<main class="container d-flex align-items-center justify-content-center min-vh-100 py-5">
    <div class="w-100" style="max-width: 420px">
        <div class="text-center mb-4 fm-reveal">
            <span class="fm-brand-logo fm-brand-logo-lg mb-3"><i class="bi bi-person-bounding-box"></i></span>
            <h1 class="h3 fw-bold mb-1">FaceMatch</h1>
            <p class="text-muted-fm mb-0">Inicia sesión para comparar rostros</p>
        </div>

        <form method="POST" action="{{ route('login.store') }}" class="fm-card p-4 fm-reveal fm-reveal-2" data-loading novalidate>
            @csrf

            @if (session('success'))
                <div class="alert alert-success small py-2"><i class="bi bi-check-circle me-1"></i> {{ session('success') }}</div>
            @endif

            <div class="mb-3">
                <label for="email" class="form-label fw-semibold">Correo</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                    <input id="email" type="email" name="email" value="{{ old('email') }}"
                           class="form-control @error('email') is-invalid @enderror"
                           autocomplete="username" autofocus required>
                    @error('email')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="mb-3">
                <label for="password" class="form-label fw-semibold">Contraseña</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-key"></i></span>
                    <input id="password" type="password" name="password"
                           class="form-control @error('password') is-invalid @enderror"
                           autocomplete="current-password" required>
                    @error('password')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="form-check mb-4">
                <input class="form-check-input" type="checkbox" name="remember" id="remember" value="1" @checked(old('remember'))>
                <label class="form-check-label" for="remember">Mantener la sesión iniciada</label>
            </div>

            <button type="submit" class="btn btn-gradient btn-lg w-100">
                <i class="bi bi-box-arrow-in-right me-1"></i> Entrar
            </button>
        </form>

        <p class="text-center small text-muted-fm mt-4 mb-0">
            ¿Sin cuenta? Un administrador puede crearla con<br>
            <code>php artisan facematch:user</code>
        </p>
    </div>
</main>
</body>
</html>
