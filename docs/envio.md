# Envío del resultado a otro proyecto

FaceMatch puede enviar cada resultado, **junto con las dos imágenes**, a otro sistema por HTTP. Es útil cuando FaceMatch es un servicio auxiliar de tu aplicación principal (alta de clientes, control de acceso, etc.).

## Configuración

```dotenv
FACE_FORWARD_URL=https://mi-app.ejemplo.com/api/face-results
FACE_FORWARD_TOKEN=un-secreto-compartido-largo
FACE_FORWARD_ONLY_MATCHES=true
FACE_FORWARD_TIMEOUT=15
```

Sin `FACE_FORWARD_URL` no se envía nada y el interruptor de la interfaz aparece deshabilitado.

## ¿Cuándo se envía?

| Origen | Regla |
|---|---|
| **Web** | Con el interruptor «Enviar al otro proyecto» activado se aplica `FACE_FORWARD_ONLY_MATCHES`; desactivado, no se envía. |
| **API** | `forward=1` envía siempre, `forward=0` nunca y sin el campo se aplica `FACE_FORWARD_ONLY_MATCHES`. |
| **Manual** | El botón «Enviar ahora / Reenviar» del detalle envía sin importar el resultado. |

Con `FACE_FORWARD_ONLY_MATCHES=true` solo se envían los resultados **aprobados** (`passed = true`).

## Petición que recibe tu sistema

`POST` a `FACE_FORWARD_URL` con cuerpo `multipart/form-data`:

| Parte | Tipo | Contenido |
|---|---|---|
| `Authorization` | cabecera | `Bearer <FACE_FORWARD_TOKEN>`. **Valídala.** |
| `X-FaceMatch-Uuid` | cabecera | UUID de la comparación. |
| `Accept` | cabecera | `application/json` |
| `source_image` | archivo | Imagen de referencia. |
| `target_image` | archivo | Imagen comparada. |
| `payload` | texto | JSON con el resultado. |

Contenido de `payload`:

```json
{
  "uuid": "01a1c714-c046-7268-a3d6-e7a207a5ec66",
  "mode": "compare",
  "origin": "web",
  "engine": "local",
  "is_match": true,
  "passed": true,
  "similarity": 86.13,
  "threshold": 80,
  "failure_reasons": [],
  "result": { "…": "detalle del motor (ver api.md)" },
  "compared_at": "2026-11-10T14:09:48-06:00",
  "source_url": "https://facematch.ejemplo.com/imagenes/01a1c714-…/origen?expires=…&signature=…",
  "target_url": "https://facematch.ejemplo.com/imagenes/01a1c714-…/comparada?expires=…&signature=…",
  "image_urls_expire_at": "2026-11-11T14:09:00-06:00"
}
```

Las imágenes ya van adjuntas; `source_url` y `target_url` son URLs firmadas que caducan (`FACE_IMAGE_URL_TTL`) por si prefieres descargarlas después.

## Respuesta esperada

- Cualquier código **2xx** marca el envío como **Enviada** (`sent`).
- Otro código o un error de conexión lo marca como **Falló** (`failed`) y queda en los registros (`forward.failed`) con el código y el inicio de la respuesta.
- No hay reintentos automáticos: reenvía desde el detalle de la comparación o desde tu sistema consultando la API.

Haz tu receptor **idempotente por `uuid`**: un mismo resultado puede llegar más de una vez si alguien pulsa «Reenviar».

## Receptor de ejemplo (Laravel)

```php
// routes/api.php del otro proyecto
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/face-results', function (Request $request) {
    abort_unless(
        hash_equals((string) config('services.facematch.forward_token'), (string) $request->bearerToken()),
        401,
        'Token inválido'
    );

    $request->validate([
        'source_image' => ['required', 'image', 'max:5120'],
        'target_image' => ['required', 'image', 'max:5120'],
        'payload' => ['required', 'json'],
    ]);

    $payload = json_decode($request->input('payload'), true);
    $dir = 'facematch/'.$payload['uuid'];

    $request->file('source_image')->storeAs($dir, 'source.'.$request->file('source_image')->extension());
    $request->file('target_image')->storeAs($dir, 'target.'.$request->file('target_image')->extension());

    \App\Models\FaceResult::updateOrCreate(
        ['uuid' => $payload['uuid']],
        ['passed' => $payload['passed'], 'similarity' => $payload['similarity'], 'reasons' => $payload['failure_reasons']],
    );

    return response()->json(['received' => true, 'uuid' => $payload['uuid']]);
});
```

En `/api-docs` hay también receptores en PHP puro y en Node (Express).

## Probar sin el otro proyecto

Puedes levantar un receptor mínimo que solo muestre lo que llega:

```js
// receptor.cjs — node receptor.cjs <token>
const http = require('http');
http.createServer((req, res) => {
  let bytes = 0;
  req.on('data', (c) => (bytes += c.length));
  req.on('end', () => {
    const ok = req.headers.authorization === `Bearer ${process.argv[2]}`;
    console.log(new Date().toISOString(), ok ? 200 : 401, req.headers['x-facematch-uuid'], `${bytes} bytes`);
    res.writeHead(ok ? 200 : 401, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ received: ok }));
  });
}).listen(3398, '127.0.0.1', () => console.log('Receptor en http://127.0.0.1:3398/api/face-results'));
```

```dotenv
FACE_FORWARD_URL=http://127.0.0.1:3398/api/face-results
FACE_FORWARD_TOKEN=<el mismo token que pasas al receptor>
```

Ejecuta `php artisan config:clear` después de cambiar el `.env`.
