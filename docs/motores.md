# Motores faciales

FaceMatch separa la lógica de la aplicación del motor que compara los rostros. El motor se elige con `FACE_ENGINE` y se registra en `App\Providers\AppServiceProvider`. Todos implementan la misma interfaz:

```php
interface FaceEngine
{
    public function key(): string;          // 'local' | 'rekognition'
    public function label(): string;        // nombre para la interfaz
    public function isSimulated(): bool;    // true en Rekognition de prueba
    public function analyze(string $sourcePath, string $targetPath, float $threshold, bool $verify): FaceAnalysis;
}
```

`FaceAnalysis` es una clase `readonly` con `similarity` (0–100), `isMatch`, `passed`, `failureReasons` (en español) y `result` (array con el detalle, con el mismo formato en ambos motores).

## Modos

| Modo | Qué hace | `passed` es verdadero si… |
|---|---|---|
| **Comparar** (`compare`) | Mide la similitud entre el rostro principal de cada foto. | la similitud alcanza el umbral. |
| **Verificar** (`verify`) | Además valida la calidad de cada foto. | la similitud alcanza el umbral **y** ambas fotos pasan todos los controles. |

## Motor `local` (por defecto)

Gratuito, sin registro, sin llaves y sin conexión a internet. Usa [`@vladmandic/face-api`](https://github.com/vladmandic/face-api) sobre TensorFlow.js con backend **WASM**.

### Cómo funciona

1. **Decodificación** con `sharp`: aplica la rotación EXIF y reduce la imagen a 1024 px como máximo.
2. **Detección** de rostros con `tinyFaceDetector` (rápido) o `ssdMobilenetv1` (preciso).
3. **68 puntos faciales** con `faceLandmark68Net`.
4. **Descriptor** de 128 valores por rostro con `faceRecognitionNet`.
5. **Distancia euclidiana** entre los descriptores del rostro de origen y el más parecido de la foto comparada.
6. **Calidad** de cada rostro, calculada en JavaScript.

### De distancia a similitud

Con un corte `D = FACE_LOCAL_MATCH_DISTANCE` (0.6 por defecto, el valor recomendado por face-api), que equivale al 80 %:

- si `d ≤ D`: `similitud = 100 − d/D × 20`
- si `d > D`: `similitud = 80 − (d − D)/0.4 × 80`, limitada a 0–100

| Distancia | Similitud |
|---|---|
| 0.0 | 100 % |
| 0.3 | 90 % |
| 0.6 | 80 % |
| 0.8 | 40 % |
| ≥ 1.0 | 0 % |

### Métricas de calidad

| Métrica | Cálculo |
|---|---|
| Pose (yaw, pitch, roll) | Estimada a partir de los 68 puntos faciales. |
| Ojos abiertos | EAR (*Eye Aspect Ratio*) > 0.2. |
| Brillo | Gris promedio del recorte del rostro (0–100). |
| Nitidez | Varianza del Laplaciano / 10 sobre el rostro reescalado a 112×112 (máx. 100). |
| Tamaño | Área del rostro en % de la imagen. |

### Controles del modo Verificar

Configurables en `config/facematch.php` → `local.limits`:

| Control | Clave del resultado | Límite por defecto |
|---|---|---|
| Rostro humano con buena confianza | `human_detected` | confianza ≥ 90 % |
| Un solo rostro | `single_face` | exactamente uno (`strict_single_face`) |
| Nitidez | `sharpness_ok` | ≥ 8 |
| Brillo | `brightness_ok` | entre 20 y 90 |
| Pose frontal | `pose_frontal` | yaw y pitch ≤ 35°, roll ≤ 25° |
| Ojos abiertos | `eyes_open` | EAR > 0.2 |
| Sin oclusión | `no_occlusion` | aproximación: EAR ≥ 0.12 y confianza ≥ 70 % |
| Tamaño mínimo | `min_face_size_ok` | área ≥ 1.5 % |

Cada control que falla agrega un motivo en español a `failure_reasons`, por ejemplo «La imagen está borrosa (nitidez 3.2 < 8)».

> **Limitación:** face-api no detecta lentes oscuros ni cubrebocas. `no_occlusion` solo falla cuando los puntos de los ojos dejan de ser fiables. Si necesitas detección real de oclusión o de vida (*liveness*), usa Rekognition u otro servicio especializado.

### Detectores

| Modo | Detector | Notas |
|---|---|---|
| Comparar | `tiny` (`FACE_LOCAL_DETECTOR`) | Rápido. Si no encuentra rostro, reintenta con `ssd`. |
| Verificar | `ssd` siempre | Más preciso y con mejor confianza de detección. |

### Modo rápido: `face:serve`

Sin el servicio, cada análisis arranca Node y carga los modelos (modo **proceso**). Con el servicio, los modelos se cargan **una vez** en un pool de `worker_threads` y las dos fotos se analizan en paralelo (modo **servicio**).

```bash
php artisan face:serve                       # 127.0.0.1:3311, 2 workers
php artisan face:serve --workers=4 --port=3400
npm run face-service                         # equivalente; lee FACE_SERVICE_TOKEN del .env
```

- `LocalFaceEngine` intenta primero el servicio (1 s para conectar).
- Si no hay conexión, usa el proceso y lo anota en los registros (`engine.fallback`).
- Si el servicio responde con un error, lo reporta y **no** reintenta con el proceso.
- Cada resultado guarda `transport` (`service` o `process`) y `elapsed_ms`.

**Seguridad del servicio:** escucha solo en `127.0.0.1`, exige `X-Face-Token`, solo lee archivos dentro de `storage/app/private` (`FACE_SERVICE_ROOT`) y reemplaza los workers que fallan.

Endpoints internos (no los expongas a internet):

- `GET /health` → `{ ok, engine, workers, models_load_ms, handled }`
- `POST /compare` con `{ source, target, detector }` → mismo JSON que el CLI

### Uso del script por línea de comandos

```bash
node scripts/face-engine.cjs foto-a.jpg foto-b.jpg [tiny|ssd]
```

Imprime `{ ok, engine, elapsed_ms, distance, match_index, source: { width, height, detector, faces[] }, target: {…} }`. Cada rostro trae `confidence`, `bounding_box` (en proporciones y `area_percentage`), `pose`, `quality { sharpness, brightness }` y `eyes_open { value, ear }`. Los descriptores no se imprimen.

El mismo archivo implementa los tres modos (CLI, servicio y worker) para no duplicar la lógica.

### Rendimiento de referencia

Medido con las fotos de `tests/Fixtures/faces` en un equipo de escritorio:

| Caso | Distancia | Similitud |
|---|---|---|
| Misma persona (fotos de 2009 y 2012) | ≈ 0.36 | ≈ 88 % |
| Misma persona, foto borrosa | ≈ 0.25 | ≈ 92 % (Verificar la rechaza por nitidez) |
| Personas distintas | 0.82–0.86 | 28–36 % |

| Tiempo por análisis | Comparar (`tiny`) | Verificar (`ssd`) |
|---|---|---|
| Modo servicio | 0.13–0.25 s | 0.35–0.5 s |
| Modo proceso | ≈ 0.6 s | ≈ 1 s (hasta ~3.5 s en equipos lentos) |

## Motor `rekognition`

Usa [`olorunda/laravel-rekognition-face`](https://packagist.org/packages/olorunda/laravel-rekognition-face) (facade `RekognitionFace`): `compare()` para Comparar y `verifyAndCompare()` para Verificar. Los mensajes de la librería se traducen al español.

Con `REKOGNITION_TEST_MODE=true` (por defecto) las respuestas se **simulan** sin AWS, según `REKOGNITION_SIMULATE_SCENARIO`. La interfaz muestra «Rekognition · simulado» y no dibuja recuadros. Para usar AWS:

```dotenv
FACE_ENGINE=rekognition
REKOGNITION_TEST_MODE=false
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=us-east-1
```

Rekognition tiene costo por imagen analizada; revisa los precios de AWS antes de activarlo.

## Agregar otro motor

1. Crea una clase en `app/FaceEngines/` que implemente `FaceEngine` y devuelva un `FaceAnalysis` (usa `LocalFaceEngine::buildAnalysis()` como guía del formato de `result`).
2. Regístrala en el `match` de `AppServiceProvider::register()`.
3. Agrega su etiqueta en `Comparison::ENGINE_LABELS`.
4. Escribe pruebas con respuestas simuladas.
