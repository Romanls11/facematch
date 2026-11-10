<?php

namespace App\FaceEngines;

final readonly class FaceAnalysis
{
    /**
     * @param  float  $similarity  Similitud 0–100.
     * @param  bool  $isMatch  La similitud alcanza el umbral.
     * @param  bool  $passed  Resultado final (en Verificar exige además las validaciones de calidad).
     * @param  list<string>  $failureReasons  Motivos de rechazo en español.
     * @param  array<string, mixed>  $result  Resultado completo, con formato compatible con Rekognition.
     */
    public function __construct(
        public float $similarity,
        public bool $isMatch,
        public bool $passed,
        public array $failureReasons,
        public array $result,
    ) {}
}
