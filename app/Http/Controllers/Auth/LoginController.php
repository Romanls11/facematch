<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\ActivityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Inicio y cierre de sesión de la interfaz web.
 *
 * No hay registro público: los usuarios se crean con `php artisan facematch:user`.
 */
class LoginController extends Controller
{
    /** Intentos fallidos permitidos por correo + IP antes de bloquear. */
    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly ActivityLog $log) {}

    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ], [
            'email.required' => 'Escribe tu correo.',
            'email.email' => 'El correo no es válido.',
            'password.required' => 'Escribe tu contraseña.',
        ]);

        $key = Str::transliterate(Str::lower($credentials['email'])).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);

            $this->log->warning('auth.locked', "Acceso bloqueado temporalmente para {$credentials['email']}", [
                'email' => $credentials['email'],
                'ip' => $request->ip(),
                'retry_in_s' => $seconds,
            ]);

            throw ValidationException::withMessages([
                'email' => "Demasiados intentos. Vuelve a intentarlo en {$seconds} segundos.",
            ]);
        }

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);

            $this->log->warning('auth.failed', "Intento de acceso fallido para {$credentials['email']}", [
                'email' => $credentials['email'],
                'ip' => $request->ip(),
                'user_agent' => mb_strimwidth((string) $request->userAgent(), 0, 160, '…'),
            ]);

            throw ValidationException::withMessages([
                'email' => 'El correo o la contraseña no son correctos.',
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        $this->log->info('auth.login', 'Inicio de sesión de '.$request->user()->email, [
            'user_id' => $request->user()->id,
            'email' => $request->user()->email,
            'ip' => $request->ip(),
            'remember' => $request->boolean('remember'),
        ]);

        return redirect()->intended(route('home'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($user) {
            $this->log->info('auth.logout', 'Cierre de sesión de '.$user->email, [
                'user_id' => $user->id,
                'email' => $user->email,
                'ip' => $request->ip(),
            ]);
        }

        return redirect()->route('login')->with('success', 'Cerraste sesión.');
    }
}
