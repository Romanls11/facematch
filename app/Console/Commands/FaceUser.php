<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;

/**
 * Administra los usuarios de la interfaz web (no hay registro público).
 *
 *   php artisan facematch:user                         # interactivo
 *   php artisan facematch:user ana@empresa.com --name="Ana"
 *   php artisan facematch:user --list
 *   php artisan facematch:user ana@empresa.com --delete
 */
class FaceUser extends Command
{
    protected $signature = 'facematch:user
        {email? : Correo del usuario (si existe, se cambia su contraseña)}
        {--name= : Nombre visible}
        {--password= : Contraseña (si se omite se pide de forma oculta; evita dejarla en el historial)}
        {--list : Lista los usuarios}
        {--delete : Elimina el usuario indicado}';

    protected $description = 'Crea, actualiza, lista o elimina usuarios de la interfaz web de FaceMatch';

    public function handle(): int
    {
        if ($this->option('list')) {
            return $this->listUsers();
        }

        $email = $this->argument('email') ?? text(
            label: 'Correo',
            placeholder: 'ana@empresa.com',
            required: true,
            validate: fn (string $v) => filter_var($v, FILTER_VALIDATE_EMAIL) ? null : 'El correo no es válido.',
        );

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->components->error('El correo no es válido.');

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        if ($this->option('delete')) {
            if (! $user) {
                $this->components->error("No existe el usuario {$email}.");

                return self::FAILURE;
            }

            $user->delete();
            $this->components->info("Usuario {$email} eliminado.");

            return self::SUCCESS;
        }

        $name = $this->option('name')
            ?? ($user?->name ?? ($this->input->isInteractive()
                ? text(label: 'Nombre', default: strstr($email, '@', true), required: true)
                : strstr($email, '@', true)));

        $plain = $this->option('password') ?? ($this->input->isInteractive()
            ? password(label: 'Contraseña', placeholder: 'mínimo 10 caracteres', required: true)
            : null);

        $validator = Validator::make(['password' => $plain], [
            'password' => ['required', 'string', 'min:10', 'max:255'],
        ], [
            'password.required' => 'Indica la contraseña con --password o ejecuta el comando de forma interactiva.',
            'password.min' => 'La contraseña debe tener al menos 10 caracteres.',
        ]);

        if ($validator->fails()) {
            $this->components->error($validator->errors()->first());

            return self::FAILURE;
        }

        $created = $user === null;
        $user ??= new User(['email' => $email]);
        $user->fill(['name' => $name, 'password' => $plain])->save();

        $this->components->info($created
            ? "Usuario {$email} creado. Ya puede iniciar sesión en ".route('login')
            : "Usuario {$email} actualizado.");

        return self::SUCCESS;
    }

    private function listUsers(): int
    {
        $users = User::orderBy('email')->get(['name', 'email', 'created_at']);

        if ($users->isEmpty()) {
            $this->components->warn('No hay usuarios. Crea uno con: php artisan facematch:user');

            return self::SUCCESS;
        }

        table(['Nombre', 'Correo', 'Creado'], $users->map(fn (User $u) => [
            $u->name, $u->email, $u->created_at?->format('d/m/Y H:i'),
        ])->all());

        return self::SUCCESS;
    }
}
