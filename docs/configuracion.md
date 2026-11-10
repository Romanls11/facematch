# Configuración

Toda la configuración se hace con variables de entorno en el `.env`. Los valores por defecto están en `config/facematch.php`. Después de cambiar el `.env` en un servidor con caché de configuración, ejecuta `php artisan config:clear` (o `config:cache`).

## Aplicación

| Variable | Por defecto | Descripción |
|---|---|---|
| `APP_URL` | `http://localhost` | URL pública. Se usa para generar enlaces fuera de una petición (comandos). |
| `APP_TIMEZONE` | `UTC` | Zona horaria de fechas y registros, p. ej. `America/Mexico_City`. |
| `APP_LOCALE` | `en` | Usa `es`: la interfaz y la paginación están en español. |
| `APP_DEBUG` | `true` en el ejemplo | **`false` en producción.** |

## Motor facial

| Variable | Por defecto | Descripción |
|---|---|---|
| `FACE_ENGINE` | `local` | `local` (face-api, gratis y sin conexión) o `rekognition` (Amazon). |
| `FACE_DEFAULT_THRESHOLD` | `80` | Umbral inicial del control deslizante y de la API (50–99). |

### Motor local

| Variable | Por defecto | Descripción |
|---|---|---|
| `FACE_NODE_BINARY` | `node` | Ejecutable de Node. En Windows, ruta completa. |
| `FACE_PROCESS_TIMEOUT` | `120` | Segundos máximos de un análisis en modo proceso. |
| `FACE_LOCAL_MATCH_DISTANCE` | `0.6` | Distancia euclidiana que equivale al 80 % de similitud. Más baja = más estricto. |
| `FACE_LOCAL_DETECTOR` | `tiny` | Detector del modo Comparar: `tiny` (rápido) o `ssd` (preciso). Verificar usa siempre `ssd`. |
| `FACE_SERVICE_URL` | `http://127.0.0.1:3311` | Dirección del servicio `face:serve`. Vacío = siempre modo proceso. |
| `FACE_SERVICE_TOKEN` | — | Secreto compartido con el servicio (cabecera `X-Face-Token`). Genéralo con `facematch:tokens`. |
| `FACE_SERVICE_TIMEOUT` | `60` | Segundos máximos de espera al servicio. |

Los límites de calidad del modo Verificar (confianza, nitidez, brillo, pose, ojos, tamaño) están en `config/facematch.php` → `local.limits`. Ver [motores.md](motores.md#controles-del-modo-verificar).

### Amazon Rekognition

| Variable | Por defecto | Descripción |
|---|---|---|
| `REKOGNITION_TEST_MODE` | `true` | Simula las respuestas sin AWS. La interfaz lo indica. |
| `REKOGNITION_SIMULATE_SCENARIO` | `match` | `match`, `no_match`, `blurry`, `closed_eyes`, `multiple_faces`, `occluded`, `not_human`. |
| `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` | — | Credenciales de AWS (solo con `REKOGNITION_TEST_MODE=false`). |
| `AWS_DEFAULT_REGION` | `us-east-1` | Región de Rekognition. |

## API

| Variable | Por defecto | Descripción |
|---|---|---|
| `FACE_API_TOKEN` | — | Token Bearer de la API. **Vacío = API deshabilitada** (responde `503`). |
| `FACE_API_RATE_LIMIT` | `60` | Peticiones por minuto por token. Al excederlo: `429`. |
| `FACE_IMAGE_URL_TTL` | `1440` | Minutos de validez de las URLs firmadas de las imágenes (24 h). |

## Envío al otro proyecto

| Variable | Por defecto | Descripción |
|---|---|---|
| `FACE_FORWARD_URL` | — | Endpoint que recibe cada resultado. Vacío = no se envía nada. |
| `FACE_FORWARD_TOKEN` | — | Se envía como `Authorization: Bearer …` para que el receptor lo valide. |
| `FACE_FORWARD_ONLY_MATCHES` | `true` | `true`: solo se envían los resultados aprobados. `false`: todos. |
| `FACE_FORWARD_TIMEOUT` | `15` | Segundos máximos de espera al receptor. |

Detalles en [envio.md](envio.md).

## Registros

| Variable | Por defecto | Descripción |
|---|---|---|
| `FACE_LOG_CHANNEL` | `facematch` | Canal de Laravel para el registro de actividad. `null` lo desactiva. |
| `FACE_LOG_DAYS` | `30` | Días de archivos de registro que se conservan. |

Detalles en [registros.md](registros.md).

## Base de datos, sesión y caché

FaceMatch usa la configuración estándar de Laravel. Con el `.env.example`:

| Variable | Valor | Notas |
|---|---|---|
| `DB_CONNECTION` | `pgsql` | Las pruebas usan SQLite en memoria (ver `phpunit.xml`). |
| `SESSION_DRIVER` | `database` | Tabla `sessions`. |
| `SESSION_LIFETIME` | `120` | Minutos de inactividad antes de cerrar la sesión. |
| `CACHE_STORE` | `database` | También guarda los contadores de intentos de login y del límite de la API. |

## Almacenamiento

Las imágenes se guardan en el disco privado `local` (`storage/app/private/comparisons/AAAA/MM/<uuid>.jpg`). No hay variable para cambiarlo; si necesitas otro disco (por ejemplo S3), cambia `disk` en `config/facematch.php` y ten en cuenta que el motor local necesita leer los archivos desde el sistema de archivos.
