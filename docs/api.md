# API REST

La API permite que otros sistemas comparen rostros sin usar la interfaz web. Una versión interactiva de esta guía, con ejemplos que ya incluyen la URL de tu instalación, está en la propia aplicación: menú **API** (`/api-docs`).

## Autenticación

Todas las rutas requieren un token Bearer:

```http
Authorization: Bearer <FACE_API_TOKEN>
Accept: application/json
```

- El token es el valor de `FACE_API_TOKEN` en el `.env` (genéralo con `php artisan facematch:tokens`).
- Sin `FACE_API_TOKEN` configurado, la API responde `503`.
- **Llama a la API desde tu servidor**, nunca desde el navegador de tus usuarios: el token daría acceso a todas las comparaciones.

## Límite de peticiones

`FACE_API_RATE_LIMIT` peticiones por minuto por token (60 por defecto). Al excederlo se responde `429` con la cabecera `Retry-After`.

## Trazabilidad

Cada respuesta incluye `X-Request-Id`. Si envías esa cabecera (8–64 caracteres `A-Z a-z 0-9 . _ -`), FaceMatch la respeta y la usa en sus registros, así puedes seguir una petición entre ambos sistemas.

## Endpoints

| Método | Ruta | Descripción |
|---|---|---|
| `POST` | `/api/face/compare` | Compara dos rostros. |
| `GET` | `/api/face/comparisons` | Lista paginada de comparaciones. |
| `GET` | `/api/face/comparisons/{uuid}` | Una comparación. |
| `GET` | `/api/face/health` | Estado del motor y del envío. |

---

### `POST /api/face/compare`

Cuerpo `multipart/form-data`:

| Campo | Requerido | Tipo | Descripción |
|---|---|---|---|
| `source_image` | sí | archivo | Foto de referencia. JPG o PNG, máx. 5 MB. |
| `target_image` | sí | archivo | Foto a comparar. JPG o PNG, máx. 5 MB. |
| `mode` | no | texto | `compare` (por defecto) o `verify`. |
| `threshold` | no | número | Similitud mínima para aprobar, de 50 a 99. Por defecto `FACE_DEFAULT_THRESHOLD`. |
| `forward` | no | booleano | `1`: enviar siempre al otro proyecto · `0`: no enviar · sin valor: según `FACE_FORWARD_ONLY_MATCHES`. |

```bash
curl -X POST "https://facematch.ejemplo.com/api/face/compare" \
  -H "Authorization: Bearer $FACE_API_TOKEN" \
  -H "Accept: application/json" \
  -F "source_image=@referencia.jpg" \
  -F "target_image=@selfie.jpg" \
  -F "mode=verify" \
  -F "threshold=85"
```

Respuesta `201 Created`:

```json
{
  "data": {
    "uuid": "01a1c705-c0fd-7271-86a4-7ef91851021b",
    "mode": "verify",
    "origin": "api",
    "engine": "local",
    "is_match": true,
    "passed": true,
    "similarity": 87.74,
    "threshold": 85,
    "failure_reasons": [],
    "forward": { "status": "sent", "http_code": 200, "forwarded_at": "2026-11-10T13:53:25-06:00" },
    "source_url": "https://facematch.ejemplo.com/imagenes/01a1c705-…/origen?expires=…&signature=…",
    "target_url": "https://facematch.ejemplo.com/imagenes/01a1c705-…/comparada?expires=…&signature=…",
    "image_urls_expire_at": "2026-11-11T13:53:00-06:00",
    "result": { "…": "detalle del motor, ver abajo" },
    "compared_at": "2026-11-10T13:53:25-06:00",
    "links": {
      "self": "https://facematch.ejemplo.com/api/face/comparisons/01a1c705-…",
      "web": "https://facematch.ejemplo.com/comparaciones/01a1c705-…"
    }
  }
}
```

| Campo | Significado |
|---|---|
| `passed` | **Úsalo para decidir.** En `compare` equivale a `is_match`; en `verify` además exige que pasen los controles de calidad. |
| `is_match` | La similitud alcanzó el umbral (sin contar la calidad). |
| `similarity` | 0–100. Con el motor local, 80 % corresponde a la distancia 0.6 de face-api. |
| `failure_reasons` | Motivos en español; vacío si aprobó. |
| `forward.status` | `sent`, `failed`, `skipped` o `pending`. |
| `source_url`, `target_url` | URLs firmadas para descargar las fotos **sin sesión**; caducan en `image_urls_expire_at` (`FACE_IMAGE_URL_TTL`). Vuelve a consultar la comparación para obtener otras. |
| `links.web` | Página del resultado en la interfaz (requiere sesión). |

Una comparación que **no coincide** también responde `201`: revisa `passed`.

#### Bloque `result`

```json
{
  "engine": "local",
  "library": "face-api/tfjs-wasm",
  "mode": "verify",
  "transport": "service",
  "elapsed_ms": 412,
  "images": {
    "source": { "width": 906, "height": 1024, "detector": "ssd", "face_count": 1 },
    "target": { "width": 1024, "height": 1024, "detector": "ssd", "face_count": 1 }
  },
  "comparison": {
    "is_match": true,
    "similarity": 87.74,
    "distance": 0.368,
    "match_distance": 0.6,
    "source_face": {
      "confidence": 93.63,
      "bounding_box": { "width": 0.4062, "height": 0.3174, "left": 0.2748, "top": 0.3135, "area_percentage": 12.89 },
      "pose": { "pitch": 2.9, "roll": -1.6, "yaw": -4.7 },
      "quality": { "sharpness": 71.87, "brightness": 36.91 },
      "eyes_open": { "value": true, "ear": 0.307 }
    },
    "matched_face": { "…": "misma estructura" },
    "unmatched_count": 0
  },
  "passed": true,
  "failure_reasons": []
}
```

En modo `verify` se agregan los bloques `source` y `target`, uno por imagen:

```json
"source": {
  "is_real_human_face": true,
  "confidence": 99.1,
  "quality_score": 84.2,
  "face_count": 1,
  "primary_face": { "…": "misma estructura que source_face" },
  "checks": {
    "human_detected": true, "single_face": true, "sharpness_ok": true, "brightness_ok": true,
    "pose_frontal": true, "eyes_open": true, "no_occlusion": true, "min_face_size_ok": true
  },
  "violations": [],
  "passed_all_checks": true
}
``` Los recuadros (`bounding_box`) están en proporciones de 0 a 1 respecto al ancho y alto de la imagen.

---

### `GET /api/face/comparisons`

| Parámetro | Valores |
|---|---|
| `mode` | `compare` o `verify` |
| `origin` | `web` o `api` |
| `passed` | `1` / `0` |
| `forward_status` | `pending`, `sent`, `failed`, `skipped` |
| `per_page` | 1–100 (por defecto 20) |
| `page` | número de página |

```bash
curl "https://facematch.ejemplo.com/api/face/comparisons?mode=verify&passed=1&per_page=10" \
  -H "Authorization: Bearer $FACE_API_TOKEN"
```

Devuelve `data` (cada elemento sin el bloque `result`), `meta` (`current_page`, `per_page`, `total`, `last_page`) y `links` (`next`, `prev`).

### `GET /api/face/comparisons/{uuid}`

Devuelve la misma estructura que `POST /compare`, con URLs firmadas nuevas. `404` si no existe.

### `GET /api/face/health`

```json
{
  "ok": true,
  "engine": { "key": "local", "label": "Local · face-api", "simulated": false, "speed": "fast" },
  "forward": { "configured": true, "only_matches": true },
  "defaults": { "threshold": 80, "max_upload_kb": 5120 },
  "rate_limit_per_minute": 60,
  "time": "2026-11-10T14:20:24-06:00"
}
```

`engine.speed`: `fast` (servicio activo), `slow` (modo proceso) o `cloud` (Rekognition).

---

## Códigos de respuesta

| Código | Significado | Qué hacer |
|---|---|---|
| `200` | Consulta correcta. | — |
| `201` | Comparación creada (aunque no coincidan). | Revisa `data.passed`. |
| `401` | Token ausente o incorrecto. | Revisa `Authorization`. |
| `404` | El UUID no existe. | — |
| `422` | Datos inválidos; `errors` trae los mensajes por campo, en español. | Corrige y reintenta. |
| `429` | Límite de peticiones excedido. | Espera `Retry-After` segundos. |
| `502` | El motor facial falló (imagen corrupta, servicio caído…). | Lee `message`; reintenta. |
| `503` | API deshabilitada (sin `FACE_API_TOKEN`). | Configura el token. |

```json
{
  "message": "Falta la imagen de origen. (y 1 error más)",
  "errors": {
    "source_image": ["Falta la imagen de origen."],
    "threshold": ["El umbral debe estar entre 50 y 99."]
  }
}
```

## Ejemplos por lenguaje

### PHP (Laravel)

```php
use Illuminate\Support\Facades\Http;

$response = Http::withToken(config('services.facematch.token'))
    ->acceptJson()
    ->timeout(60)
    ->attach('source_image', file_get_contents($rutaReferencia), 'referencia.jpg')
    ->attach('target_image', file_get_contents($rutaSelfie), 'selfie.jpg')
    ->post('https://facematch.ejemplo.com/api/face/compare', [
        'mode' => 'verify',
        'threshold' => 85,
    ]);

if ($response->created() && $response->json('data.passed')) {
    // Identidad confirmada
}
```

### JavaScript (Node 20+)

```js
import { openAsBlob } from 'node:fs';

const form = new FormData();
form.append('source_image', await openAsBlob('referencia.jpg', { type: 'image/jpeg' }), 'referencia.jpg');
form.append('target_image', await openAsBlob('selfie.jpg', { type: 'image/jpeg' }), 'selfie.jpg');
form.append('mode', 'verify');

const res = await fetch('https://facematch.ejemplo.com/api/face/compare', {
  method: 'POST',
  headers: { Authorization: `Bearer ${process.env.FACE_API_TOKEN}`, Accept: 'application/json' },
  body: form,
});
const { data } = await res.json();
console.log(data.passed, data.similarity, data.failure_reasons);
```

### Python

```python
import os, requests

with open("referencia.jpg", "rb") as src, open("selfie.jpg", "rb") as tgt:
    res = requests.post(
        "https://facematch.ejemplo.com/api/face/compare",
        headers={"Authorization": f"Bearer {os.environ['FACE_API_TOKEN']}", "Accept": "application/json"},
        files={"source_image": src, "target_image": tgt},
        data={"mode": "compare", "threshold": 80},
        timeout=60,
    )

data = res.json()["data"]
print(data["passed"], data["similarity"])
```

Hay más ejemplos (PHP con cURL y receptores en Laravel, PHP y Express) en `/api-docs`.

## Tiempos y timeouts

Con `face:serve` activo una comparación tarda ~0.2 s (Comparar) o ~0.5 s (Verificar). Usa un timeout de al menos **60 s** en tu cliente por si el servicio no está activo y FaceMatch cae al modo proceso.
