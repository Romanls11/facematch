<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Las primeras versiones guardaban las fotos en el disco «public»
 * (accesibles sin sesión en /storage). Esta migración las mueve al disco
 * privado configurado (facematch.disk). En instalaciones nuevas no hace nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->move('public', config('facematch.disk', 'local'));
    }

    public function down(): void
    {
        $this->move(config('facematch.disk', 'local'), 'public');
    }

    private function move(string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }

        $source = Storage::disk($from);
        $target = Storage::disk($to);

        DB::table('comparisons')->select(['source_path', 'target_path'])->orderBy('id')->each(function ($row) use ($source, $target) {
            foreach ([$row->source_path, $row->target_path] as $path) {
                if ($source->exists($path) && ! $target->exists($path)) {
                    $stream = $source->readStream($path);
                    $written = $target->writeStream($path, $stream);
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                    if ($written) {
                        $source->delete($path);
                    }
                }
            }
        });
    }
};
