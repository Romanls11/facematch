# Política de seguridad

FaceMatch procesa **fotografías de rostros**, que en muchas legislaciones se consideran datos personales sensibles (biométricos). Tomamos en serio cualquier fallo que pueda exponerlas.

## Versiones con soporte

| Versión | Soporte |
|---|---|
| `main` (última) | ✅ |
| Anteriores | ❌ |

## Cómo reportar una vulnerabilidad

**No abras un issue público.** Usa el reporte privado de GitHub:

1. Ve a la pestaña **Security** del repositorio.
2. Pulsa **Report a vulnerability** (*Private vulnerability reporting*).
3. Describe el problema, la versión o commit afectado, los pasos para reproducirlo y el impacto que estimas.

Responderemos en un plazo de **5 días hábiles** con una primera evaluación. Te mantendremos informado hasta publicar la corrección y, si lo deseas, te daremos crédito en el `CHANGELOG.md`.

## Alcance

Nos interesan especialmente:

- Acceso a imágenes o resultados sin sesión, sin token o con una URL firmada alterada o caducada.
- Omisión del login, del límite de intentos o del límite de peticiones de la API.
- Lectura de archivos fuera de `storage/app/private` a través del servicio facial (`face:serve`).
- Inyección (SQL, comandos, XSS) en la interfaz, la API o los registros.
- Fugas de tokens o datos sensibles en respuestas, registros o en el envío al otro proyecto.

Fuera de alcance: ataques que requieren acceso previo al servidor o al `.env`, ingeniería social y denegación de servicio por volumen.

## Recomendaciones para quien despliega FaceMatch

Consulta [docs/seguridad.md](docs/seguridad.md). En resumen:

- Usa siempre **HTTPS** y `APP_DEBUG=false` en producción.
- Genera tokens propios con `php artisan facematch:tokens`; nunca reutilices los de ejemplo.
- Mantén `face:serve` escuchando solo en `127.0.0.1`.
- Define cuánto tiempo conservas las fotos y elimina las que ya no necesites.
- Informa a las personas fotografiadas y obtén su consentimiento según la ley aplicable (p. ej. GDPR, LFPDPPP en México).
