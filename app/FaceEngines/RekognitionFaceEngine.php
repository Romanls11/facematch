<?php

namespace App\FaceEngines;

use Olorunda\RekognitionFace\Facades\RekognitionFace;
use Throwable;

/**
 * Amazon Rekognition mediante olorunda/laravel-rekognition-face.
 * Con REKOGNITION_TEST_MODE=true las respuestas se simulan sin AWS.
 */
class RekognitionFaceEngine implements FaceEngine
{
    /** Traducción de los mensajes de la librería (en inglés) al español. */
    private const TRANSLATIONS = [
        '/^No human face was detected in the image.*$/' => 'No se detectó ningún rostro humano.',
        '/^Low face detection confidence \(([\d.]+)% < ([\d.]+)%\).*$/' => 'Confianza de detección baja ($1 % < $2 %).',
        '/^Multiple faces detected \((\d+) faces found\).*$/' => 'Se detectaron $1 rostros; se requiere exactamente uno.',
        '/^Image sharpness is too low \(([\d.]+) < ([\d.]+)\).*$/' => 'La imagen está borrosa (nitidez $1 < $2).',
        '/^Image brightness \(([\d.]+)\) is out of acceptable range \(([\d.]+) - ([\d.]+)\).*$/' => 'Brillo fuera de rango ($1; permitido $2–$3).',
        '/^Face is not frontal \(Yaw: ([-\d.]+)°, Pitch: ([-\d.]+)°, Roll: ([-\d.]+)°\).*$/' => 'El rostro no está de frente (yaw $1°, pitch $2°, roll $3°).',
        '/^Eyes appear closed.*$/' => 'Los ojos parecen cerrados.',
        '/^Face is wearing sunglasses.*$/' => 'El rostro tiene lentes oscuros.',
        '/^Face is occluded.*$/' => 'El rostro está cubierto (cubrebocas u otro objeto).',
        '/^Face size is too small \(([\d.]+)% of image < ([\d.]+)%\).*$/' => 'El rostro es demasiado pequeño ($1 % de la imagen < $2 %).',
    ];

    public function key(): string
    {
        return 'rekognition';
    }

    public function label(): string
    {
        return $this->isSimulated() ? 'Rekognition · simulado' : 'Amazon Rekognition';
    }

    public function isSimulated(): bool
    {
        return (bool) RekognitionFace::isTestMode();
    }

    public function analyze(string $sourcePath, string $targetPath, float $threshold, bool $verify): FaceAnalysis
    {
        $started = microtime(true);

        try {
            if ($verify) {
                $verification = RekognitionFace::verifyAndCompare($sourcePath, $targetPath, ['threshold' => $threshold]);
                $data = $verification->toArray();
                $comparison = $verification->getComparison();
            } else {
                $comparison = RekognitionFace::compare($sourcePath, $targetPath, $threshold);
                $data = ['comparison' => $comparison->toArray()];
            }
        } catch (Throwable $e) {
            throw new FaceEngineException('Amazon Rekognition falló: '.$e->getMessage(), 0, $e);
        }

        $isMatch = $comparison->isMatch();
        $similarity = (float) $comparison->getSimilarity();
        $reasons = [];

        if ($verify) {
            foreach (['source' => 'origen', 'target' => 'a comparar'] as $key => $label) {
                $data[$key]['violations'] = array_map($this->translate(...), $data[$key]['violations'] ?? []);

                if (! ($data[$key]['is_real_human_face'] ?? false)) {
                    $reasons[] = "La imagen {$label} no superó la verificación: ".implode(' ', $data[$key]['violations']);
                }
            }
            $passed = (bool) $verification->passed();
        } else {
            $passed = $isMatch;
        }

        if (! $isMatch) {
            $reasons[] = sprintf(
                'Los rostros no coinciden: la similitud de %s %% está por debajo del umbral de %s %%.',
                round($similarity, 2),
                round($threshold, 2),
            );
        }

        $result = [
            'engine' => $this->key(),
            'library' => 'olorunda/laravel-rekognition-face',
            'simulated' => $this->isSimulated(),
            'mode' => $verify ? 'verify' : 'compare',
            'transport' => 'aws',
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
            ...$data,
            'passed' => $passed,
            'failure_reasons' => $reasons,
        ];

        return new FaceAnalysis($similarity, $isMatch, $passed, $reasons, $result);
    }

    private function translate(string $message): string
    {
        $message = trim(str_replace(' (simulated)', '', $message));

        foreach (self::TRANSLATIONS as $pattern => $replacement) {
            if (preg_match($pattern, $message)) {
                return preg_replace($pattern, $replacement, $message);
            }
        }

        return $message;
    }
}
