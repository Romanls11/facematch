<?php

namespace App\Http\Controllers;

use App\Models\Comparison;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sirve las imágenes privadas de una comparación.
 *
 * - comparisons.image: usuarios con sesión (web).
 * - comparisons.image.signed: URL firmada y temporal (API y otro proyecto).
 */
class ComparisonImageController extends Controller
{
    public function __invoke(Comparison $comparison, string $side): Response
    {
        $path = $comparison->imagePath($side);
        $disk = $comparison->disk();

        abort_unless($disk->exists($path), 404, 'La imagen ya no existe.');

        return $disk->response($path, null, [
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
        ]);
    }
}
