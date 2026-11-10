<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Genera en el .env los tokens de FaceMatch que estén vacíos.
 *
 *   php artisan facematch:tokens            # solo rellena los vacíos
 *   php artisan facematch:tokens --force    # los regenera todos
 */
class FaceTokens extends Command
{
    protected $signature = 'facematch:tokens
        {--force : Regenera también los tokens que ya tienen valor}
        {--show : Muestra los valores generados (por defecto se ocultan)}';

    protected $description = 'Genera FACE_SERVICE_TOKEN y FACE_API_TOKEN en el archivo .env';

    /** Variable => bytes aleatorios. */
    private const TOKENS = [
        'FACE_SERVICE_TOKEN' => 32,
        'FACE_API_TOKEN' => 32,
    ];

    public function handle(): int
    {
        $path = $this->laravel->environmentFilePath();

        if (! is_file($path)) {
            $this->components->error('No existe el archivo .env. Cópialo primero: cp .env.example .env');

            return self::FAILURE;
        }

        $env = (string) file_get_contents($path);
        $changed = [];

        foreach (self::TOKENS as $key => $bytes) {
            $pattern = '/^'.preg_quote($key, '/').'=(.*)$/m';
            $current = preg_match($pattern, $env, $m) ? trim($m[1], " \t\"'") : null;

            if ($current !== null && $current !== '' && ! $this->option('force')) {
                $this->components->twoColumnDetail($key, '<fg=gray>ya tiene valor</>');

                continue;
            }

            $token = bin2hex(random_bytes($bytes));
            $env = $current === null
                ? rtrim($env).PHP_EOL.$key.'='.$token.PHP_EOL
                : preg_replace($pattern, $key.'='.$token, $env, 1);

            $changed[$key] = $token;
            $this->components->twoColumnDetail($key, '<fg=green>generado</>'.($this->option('show') ? " {$token}" : ''));
        }

        if ($changed === []) {
            $this->components->info('No había tokens vacíos. Usa --force para regenerarlos.');

            return self::SUCCESS;
        }

        file_put_contents($path, $env);
        $this->call('config:clear');

        $this->components->info('Tokens guardados en .env.');

        if (isset($changed['FACE_SERVICE_TOKEN'])) {
            $this->components->warn('Reinicia «php artisan face:serve» para que use el nuevo FACE_SERVICE_TOKEN.');
        }

        if (isset($changed['FACE_API_TOKEN'])) {
            $this->components->warn('Comparte el nuevo FACE_API_TOKEN con los sistemas que consumen la API.');
        }

        return self::SUCCESS;
    }
}
