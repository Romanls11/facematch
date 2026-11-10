<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class FaceServe extends Command
{
    protected $signature = 'face:serve
        {--host= : Host del servicio (por defecto el de FACE_SERVICE_URL)}
        {--port= : Puerto del servicio (por defecto el de FACE_SERVICE_URL)}
        {--workers=2 : Número de worker_threads con los modelos cargados}';

    protected $description = 'Inicia el servicio facial persistente (modo rápido): carga los modelos una sola vez';

    public function handle(): int
    {
        $config = config('facematch.local');
        $token = (string) ($config['service_token'] ?? '');

        if ($token === '') {
            $this->error('Falta FACE_SERVICE_TOKEN en el archivo .env.');

            return self::FAILURE;
        }

        $url = parse_url((string) ($config['service_url'] ?? 'http://127.0.0.1:3311')) ?: [];
        $host = $this->option('host') ?: ($url['host'] ?? '127.0.0.1');
        $port = $this->option('port') ?: ($url['port'] ?? 3311);
        $workers = max(1, (int) $this->option('workers'));

        $command = [
            (string) $config['node_binary'],
            (string) $config['script'],
            '--serve',
            "--host={$host}",
            "--port={$port}",
            "--workers={$workers}",
        ];

        $this->components->info("Iniciando el servicio facial en http://{$host}:{$port} con {$workers} worker(s)… (Ctrl+C para detener)");

        $result = Process::forever()
            ->path(base_path())
            ->env([
                'FACE_SERVICE_TOKEN' => $token,
                'FACE_SERVICE_ROOT' => $config['service_root'],
            ])
            ->run($command, function (string $type, string $output) {
                $this->output->write($output);
            });

        if (! $result->successful()) {
            $this->error('El servicio terminó con código '.$result->exitCode().'. ¿Está bien FACE_NODE_BINARY ('.$config['node_binary'].')?');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
