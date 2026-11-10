# Registros de actividad

FaceMatch registra cada operación relevante para poder auditar quién hizo qué y diagnosticar problemas.

## Dónde están

- **Archivos:** `storage/logs/facematch-AAAA-MM-DD.log`, una línea JSON por evento. Se crea un archivo por día y se conservan `FACE_LOG_DAYS` días (30 por defecto).
- **Interfaz:** menú **Registros** (`/registros`), con contadores del día y filtros por fecha, tipo, nivel y texto libre. Cada evento se despliega para ver todos sus datos.
- **Desde una comparación:** el botón «Ver registros» del detalle filtra por su UUID.

Para desactivarlo: `FACE_LOG_CHANNEL=null`.

## Eventos

| Evento | Nivel | Cuándo | Datos principales |
|---|---|---|---|
| `comparison.created` | info | Comparación terminada | `uuid`, modo, origen, motor, `transport`, similitud, umbral, `passed`, motivos, `engine_ms`, `duration_ms`, nombres de archivo |
| `comparison.failed` | error | El motor falló (las fotos se borran) | modo, origen, error, nombres de archivo |
| `comparison.deleted` | info | Se eliminó una comparación | `uuid` |
| `forward.sent` | info | El otro proyecto respondió 2xx | URL, código, duración, inicio de la respuesta |
| `forward.failed` | warning / error | Respondió otro código / no hubo conexión | URL, código o error, duración |
| `forward.skipped` | debug | No se envió | motivo |
| `engine.fallback` | warning | `face:serve` no responde y se usó el modo proceso | URL del servicio, error |
| `api.request` | info / warning / error | Cada llamada a la API (2xx / 4xx / 5xx) | método, ruta, estado, duración, IP, user agent, `uuid`, error |
| `auth.login` | info | Inicio de sesión | usuario, IP, «recordarme» |
| `auth.logout` | info | Cierre de sesión | usuario, IP |
| `auth.failed` | warning | Credenciales incorrectas | correo intentado, IP, user agent |
| `auth.locked` | warning | Bloqueo tras 5 intentos fallidos | correo, IP, segundos restantes |

**No se registran** imágenes, descriptores faciales, contraseñas ni tokens.

## Seguir una petición completa

Todas las líneas de una misma petición HTTP comparten un `request_id`, que también se devuelve en la cabecera `X-Request-Id`. Al buscarlo en `/registros` aparece la historia completa, por ejemplo:

```text
INFO  api.request         POST /api/face/compare → 201
INFO  forward.sent        Resultado enviado al otro proyecto (HTTP 200)
INFO  comparison.created  Verificación por API: 87.87 % (umbral 80 %) · aprobada
```

Si tu sistema envía su propio `X-Request-Id`, FaceMatch lo reutiliza.

## Formato de cada línea

```json
{
  "message": "Comparación web: 86.13 % (umbral 80 %) · aprobada",
  "context": {
    "event": "comparison.created",
    "uuid": "01a1c714-c046-7268-a3d6-e7a207a5ec66",
    "mode": "compare",
    "origin": "web",
    "engine": "local",
    "transport": "service",
    "similarity": 86.13,
    "threshold": 80,
    "is_match": true,
    "passed": true,
    "failure_reasons": [],
    "engine_ms": 296,
    "duration_ms": 341,
    "request_id": "47c12708-b410-4163-81ae-c8c2fba803f8"
  },
  "level": 200,
  "level_name": "INFO",
  "channel": "local",
  "datetime": "2026-11-10T14:09:48.123456-06:00",
  "extra": {}
}
```

Al ser JSON por línea, se puede enviar tal cual a herramientas como Loki, Elastic, Datadog o CloudWatch.

## Consultar desde la consola

```bash
# Linux / macOS: seguir en vivo
tail -f storage/logs/facematch-$(date +%F).log

# Solo errores (requiere jq)
jq -c 'select(.level >= 400) | {datetime, message}' storage/logs/facematch-$(date +%F).log
```

```powershell
# Windows PowerShell: seguir en vivo
Get-Content storage/logs/facematch-$(Get-Date -Format yyyy-MM-dd).log -Wait -Tail 20
```

## Privacidad

Los registros contienen correos de usuarios, IPs y nombres de archivo. Trátalos como datos personales: limita quién accede al servidor y ajusta `FACE_LOG_DAYS` a tu política de retención.
