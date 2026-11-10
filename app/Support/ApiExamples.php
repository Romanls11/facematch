<?php

namespace App\Support;

/**
 * Ejemplos de consumo para /api-docs. {BASE} se reemplaza por la URL de la app.
 */
final class ApiExamples
{
    /** @return array<string, array{label: string, lang: string, code: string}> */
    public static function clients(string $base): array
    {
        return self::fill($base, [
            'curl' => ['label' => 'cURL', 'lang' => 'bash', 'code' => <<<'TXT'
# Comparar dos rostros (multipart/form-data)
curl -X POST "{BASE}/api/face/compare" \
  -H "Authorization: Bearer TU_FACE_API_TOKEN" \
  -H "Accept: application/json" \
  -F "source_image=@/ruta/referencia.jpg" \
  -F "target_image=@/ruta/selfie.jpg" \
  -F "mode=verify" \
  -F "threshold=85"
TXT],
            'laravel' => ['label' => 'PHP · Laravel', 'lang' => 'php', 'code' => <<<'TXT'
use Illuminate\Support\Facades\Http;

$response = Http::withToken(config('services.facematch.token'))
    ->acceptJson()
    ->timeout(60)
    ->attach('source_image', file_get_contents($rutaReferencia), 'referencia.jpg')
    ->attach('target_image', file_get_contents($rutaSelfie), 'selfie.jpg')
    ->post('{BASE}/api/face/compare', [
        'mode'      => 'verify',   // compare | verify
        'threshold' => 85,         // 50–99 (opcional)
        'forward'   => 0,          // 1 = enviar siempre, 0 = no enviar (opcional)
    ]);

if ($response->status() === 201) {
    $data = $response->json('data');

    if ($data['passed']) {
        // Identidad confirmada: $data['similarity'] %
    } else {
        // Rechazada: $data['failure_reasons'] (en español)
    }
} elseif ($response->status() === 422) {
    $errores = $response->json('errors');   // validación
} else {
    $error = $response->json('message');    // 401, 429, 502…
}
TXT],
            'php' => ['label' => 'PHP · cURL', 'lang' => 'php', 'code' => <<<'TXT'
$ch = curl_init('{BASE}/api/face/compare');

curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer TU_FACE_API_TOKEN',
        'Accept: application/json',
    ],
    CURLOPT_POSTFIELDS     => [
        'source_image' => new CURLFile('/ruta/referencia.jpg', 'image/jpeg'),
        'target_image' => new CURLFile('/ruta/selfie.jpg', 'image/jpeg'),
        'mode'         => 'compare',
        'threshold'    => 80,
    ],
]);

$body   = json_decode(curl_exec($ch), true);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($status === 201) {
    echo $body['data']['passed'] ? 'Coinciden' : 'No coinciden';
    echo " ({$body['data']['similarity']} %)";
}
TXT],
            'node' => ['label' => 'JavaScript · Node', 'lang' => 'javascript', 'code' => <<<'TXT'
// Node 20+ (fetch, FormData y openAsBlob nativos)
import { openAsBlob } from 'node:fs';

const form = new FormData();
form.append('source_image', await openAsBlob('referencia.jpg', { type: 'image/jpeg' }), 'referencia.jpg');
form.append('target_image', await openAsBlob('selfie.jpg', { type: 'image/jpeg' }), 'selfie.jpg');
form.append('mode', 'verify');
form.append('threshold', '85');

const res = await fetch('{BASE}/api/face/compare', {
  method: 'POST',
  headers: {
    Authorization: 'Bearer TU_FACE_API_TOKEN',
    Accept: 'application/json',
  },
  body: form,
});

const json = await res.json();
if (res.status === 201) {
  console.log(json.data.passed ? 'Aprobada' : 'Rechazada', json.data.similarity, json.data.failure_reasons);
} else {
  console.error(res.status, json.message, json.errors ?? '');
}
TXT],
            'python' => ['label' => 'Python', 'lang' => 'python', 'code' => <<<'TXT'
import requests

with open("referencia.jpg", "rb") as src, open("selfie.jpg", "rb") as tgt:
    res = requests.post(
        "{BASE}/api/face/compare",
        headers={
            "Authorization": "Bearer TU_FACE_API_TOKEN",
            "Accept": "application/json",
        },
        files={
            "source_image": ("referencia.jpg", src, "image/jpeg"),
            "target_image": ("selfie.jpg", tgt, "image/jpeg"),
        },
        data={"mode": "compare", "threshold": 80},
        timeout=60,
    )

body = res.json()
if res.status_code == 201:
    d = body["data"]
    print("Coinciden" if d["passed"] else "No coinciden", d["similarity"], "%")
else:
    print(res.status_code, body.get("message"), body.get("errors"))
TXT],
        ]);
    }

    /** @return array<string, string> */
    public static function queries(string $base): array
    {
        return self::fill($base, [
            'health' => <<<'TXT'
curl "{BASE}/api/face/health" -H "Authorization: Bearer TU_FACE_API_TOKEN"
TXT,
            'list' => <<<'TXT'
# Últimas 10 verificaciones aprobadas que llegaron por la API
curl "{BASE}/api/face/comparisons?mode=verify&passed=1&origin=api&per_page=10" \
  -H "Authorization: Bearer TU_FACE_API_TOKEN"
TXT,
            'show' => <<<'TXT'
curl "{BASE}/api/face/comparisons/01a1c705-c0fd-7271-86a4-7ef91851021b" \
  -H "Authorization: Bearer TU_FACE_API_TOKEN"
TXT,
        ]);
    }

    public static function response(string $base): string
    {
        return self::fill($base, [<<<'TXT'
HTTP/1.1 201 Created
X-Request-Id: 9b1f6c2e-3d1a-4c55-9a0e-1f2b3c4d5e6f

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
    "source_url": "{BASE}/imagenes/01a1c705-…/origen?expires=1762890000&signature=…",
    "target_url": "{BASE}/imagenes/01a1c705-…/comparada?expires=1762890000&signature=…",
    "image_urls_expire_at": "2026-11-11T13:53:00-06:00",
    "result": {
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
          "bounding_box": { "width": 0.4062, "height": 0.3174, "left": 0.2748, "top": 0.3135 },
          "pose": { "pitch": 2.9, "roll": -1.6, "yaw": -4.7 },
          "quality": { "sharpness": 71.87, "brightness": 36.91 },
          "eyes_open": { "value": true, "ear": 0.307 }
        },
        "matched_face": { "…": "misma estructura que source_face" },
        "unmatched_count": 0
      },
      "passed": true,
      "failure_reasons": []
    },
    "compared_at": "2026-11-10T13:53:25-06:00",
    "links": {
      "self": "{BASE}/api/face/comparisons/01a1c705-…",
      "web": "{BASE}/comparaciones/01a1c705-…"
    }
  }
}
TXT])[0];
    }

    public static function validationError(): string
    {
        return <<<'TXT'
HTTP/1.1 422 Unprocessable Content

{
  "message": "Falta la imagen de origen. (y 1 error más)",
  "errors": {
    "source_image": ["Falta la imagen de origen."],
    "threshold": ["El umbral debe estar entre 50 y 99."]
  }
}
TXT;
    }

    /** @return array<string, array{label: string, lang: string, code: string}> */
    public static function receivers(): array
    {
        return [
            'laravel' => ['label' => 'Laravel', 'lang' => 'php', 'code' => <<<'TXT'
// routes/api.php del OTRO proyecto
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/face-results', function (Request $request) {
    // 1. Validar el token compartido (FACE_FORWARD_TOKEN en FaceMatch)
    abort_unless(
        hash_equals((string) config('services.facematch.forward_token'), (string) $request->bearerToken()),
        401,
        'Token inválido'
    );

    // 2. Leer el resultado
    $payload = json_decode($request->input('payload'), true);

    // 3. Guardar las dos imágenes
    $dir = 'facematch/'.$payload['uuid'];
    $request->file('source_image')->storeAs($dir, 'source.jpg');
    $request->file('target_image')->storeAs($dir, 'target.jpg');

    // 4. Usar el resultado (idempotente por uuid: puede llegar más de una vez)
    \App\Models\FaceResult::updateOrCreate(
        ['uuid' => $payload['uuid']],
        [
            'passed'     => $payload['passed'],
            'similarity' => $payload['similarity'],
            'mode'       => $payload['mode'],
            'reasons'    => $payload['failure_reasons'],
        ],
    );

    return response()->json(['received' => true, 'uuid' => $payload['uuid']]);
});
TXT],
            'php' => ['label' => 'PHP puro', 'lang' => 'php', 'code' => <<<'TXT'
<?php // recibir.php
$token = getenv('FACE_FORWARD_TOKEN');
$auth  = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

if (! hash_equals("Bearer {$token}", $auth)) {
    http_response_code(401);
    exit(json_encode(['message' => 'Token inválido']));
}

$payload = json_decode($_POST['payload'], true);
$dir = __DIR__ . '/recibidos/' . basename($payload['uuid']);
@mkdir($dir, 0775, true);

move_uploaded_file($_FILES['source_image']['tmp_name'], "$dir/source.jpg");
move_uploaded_file($_FILES['target_image']['tmp_name'], "$dir/target.jpg");
file_put_contents("$dir/payload.json", json_encode($payload, JSON_PRETTY_PRINT));

header('Content-Type: application/json');
echo json_encode(['received' => true]);
TXT],
            'node' => ['label' => 'Node · Express', 'lang' => 'javascript', 'code' => <<<'TXT'
import express from 'express';
import multer from 'multer';

const app = express();
const upload = multer({ dest: 'recibidos/' });

app.post('/api/face-results',
  upload.fields([{ name: 'source_image' }, { name: 'target_image' }]),
  (req, res) => {
    if (req.get('Authorization') !== `Bearer ${process.env.FACE_FORWARD_TOKEN}`) {
      return res.status(401).json({ message: 'Token inválido' });
    }

    const payload = JSON.parse(req.body.payload);
    const { source_image, target_image } = req.files;

    console.log(payload.uuid, payload.passed, payload.similarity,
      source_image[0].path, target_image[0].path);

    res.json({ received: true });
  });

app.listen(3000);
TXT],
        ];
    }

    /**
     * @template T of array
     *
     * @param  T  $items
     * @return T
     */
    private static function fill(string $base, array $items): array
    {
        array_walk_recursive($items, function (&$value) use ($base) {
            $value = str_replace('{BASE}', rtrim($base, '/'), $value);
        });

        return $items;
    }
}
