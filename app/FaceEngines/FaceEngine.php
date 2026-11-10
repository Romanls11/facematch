<?php

namespace App\FaceEngines;

interface FaceEngine
{
    /** Identificador corto del motor (se guarda en cada comparación). */
    public function key(): string;

    /** Nombre legible para la interfaz. */
    public function label(): string;

    /** true si el motor devuelve resultados simulados. */
    public function isSimulated(): bool;

    /**
     * Compara dos rostros.
     *
     * @param  string  $sourcePath  Ruta absoluta de la imagen de origen.
     * @param  string  $targetPath  Ruta absoluta de la imagen a comparar.
     * @param  float  $threshold  Similitud mínima (0–100) para considerar coincidencia.
     * @param  bool  $verify  Además valida que ambas imágenes sean rostros reales de buena calidad.
     *
     * @throws FaceEngineException
     */
    public function analyze(string $sourcePath, string $targetPath, float $threshold, bool $verify): FaceAnalysis;
}
