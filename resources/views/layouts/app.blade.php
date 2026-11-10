<!DOCTYPE html>
<html lang="es" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@hasSection('title')@yield('title') · @endif FaceMatch</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🧑‍🤝‍🧑</text></svg>">
    <script>
        // Aplica el tema antes de pintar para evitar el parpadeo.
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
<body>
<nav class="navbar navbar-expand-md fm-navbar sticky-top">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2 fm-brand" href="{{ route('home') }}">
            <span class="fm-brand-logo"><i class="bi bi-person-bounding-box"></i></span>
            FaceMatch
        </a>

        <div class="d-flex align-items-center gap-2 order-md-last">
            <span class="fm-chip {{ $faceEngine['simulated'] ? 'fm-chip-warning' : 'fm-chip-accent' }} d-none d-sm-inline-flex"
                  data-bs-toggle="tooltip" data-bs-placement="bottom"
                  title="Motor activo (FACE_ENGINE={{ $faceEngine['key'] }})">
                <i class="bi {{ $faceEngine['key'] === 'local' ? 'bi-cpu' : 'bi-cloud' }}"></i>
                {{ $faceEngine['label'] }}
            </span>
            <button type="button" class="btn btn-outline-secondary border-0 fm-theme-toggle" data-theme-toggle
                    aria-label="Cambiar tema claro u oscuro">
                <i class="bi bi-moon-stars-fill" data-theme-icon></i>
            </button>
            @auth
                <div class="dropdown">
                    <button class="btn btn-outline-secondary border-0 d-flex align-items-center gap-2" type="button"
                            data-bs-toggle="dropdown" aria-expanded="false" aria-label="Menú de usuario">
                        <span class="fm-avatar">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
                        <span class="d-none d-lg-inline small">{{ auth()->user()->name }}</span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow">
                        <li class="px-3 py-2 small text-muted-fm">{{ auth()->user()->email }}</li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="dropdown-item"><i class="bi bi-box-arrow-right me-2"></i>Cerrar sesión</button>
                            </form>
                        </li>
                    </ul>
                </div>
            @endauth
            <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#nav"
                    aria-controls="nav" aria-expanded="false" aria-label="Mostrar menú">
                <span class="navbar-toggler-icon"></span>
            </button>
        </div>

        <div class="collapse navbar-collapse" id="nav">
            <ul class="navbar-nav ms-md-4 me-auto gap-1">
                <li class="nav-item">
                    <a class="nav-link @if(request()->routeIs('home')) active @endif" href="{{ route('home') }}">
                        <i class="bi bi-arrow-left-right me-1"></i> Comparar
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link @if(request()->routeIs('comparisons.history')) active @endif" href="{{ route('comparisons.history') }}">
                        <i class="bi bi-clock-history me-1"></i> Historial
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link @if(request()->routeIs('logs.index')) active @endif" href="{{ route('logs.index') }}">
                        <i class="bi bi-journal-text me-1"></i> Registros
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link @if(request()->routeIs('docs.api')) active @endif" href="{{ route('docs.api') }}">
                        <i class="bi bi-code-slash me-1"></i> API
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<main class="container py-4 py-lg-5">
    @yield('content')
</main>

<footer class="container pb-4 small text-muted-fm d-flex flex-wrap justify-content-between gap-2">
    <span>FaceMatch · comparación facial {{ $faceEngine['key'] === 'local' ? 'local y sin conexión' : 'con Amazon Rekognition' }}</span>
    <span>Laravel {{ app()->version() }} · Bootstrap 5.3</span>
</footer>

<div class="toast-container position-fixed bottom-0 end-0 p-3 fm-toasts" id="fm-toasts"></div>

@php
    $flash = collect(['success', 'warning', 'error', 'info'])
        ->filter(fn ($type) => session()->has($type))
        ->map(fn ($type) => [$type, session($type)])
        ->values()
        ->all();
    foreach ($errors->all() as $message) {
        $flash[] = ['error', $message];
    }
@endphp
<script type="application/json" id="fm-flash">@json($flash)</script>
@stack('scripts')
</body>
</html>
