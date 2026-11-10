# Seguridad y privacidad

FaceMatch maneja fotografías de rostros. En muchas legislaciones son **datos personales sensibles** (biométricos), así que el proyecto está diseñado para no exponerlas y para dejar rastro de quién accede.

## Modelo de acceso

| Recurso | Quién accede | Mecanismo |
|---|---|---|
| Interfaz web (`/`, `/historial`, `/registros`, `/api-docs`…) | Usuarios con sesión | Login con correo y contraseña |
| Imágenes en la web (`/comparaciones/{uuid}/imagen/…`) | Usuarios con sesión | Misma sesión |
| API (`/api/face/*`) | Sistemas con el token | `Authorization: Bearer FACE_API_TOKEN` |
| Imágenes para la API y el otro proyecto (`/imagenes/{uuid}/…`) | Quien tenga la URL, hasta que caduca | URL firmada con `APP_KEY` y caducidad `FACE_IMAGE_URL_TTL` |
| Servicio facial (`127.0.0.1:3311`) | Solo Laravel en el mismo equipo | `X-Face-Token` + escucha local |
| `/up` | Cualquiera | Chequeo de salud de Laravel; no expone datos |

## Usuarios

No hay registro público: los usuarios se administran desde la consola del servidor.

```bash
php artisan facematch:user                          # interactivo (la contraseña no queda en el historial)
php artisan facematch:user ana@empresa.com --name="Ana"
php artisan facematch:user ana@empresa.com          # si existe, cambia su contraseña
php artisan facematch:user --list
php artisan facematch:user ana@empresa.com --delete
```

- Contraseñas de **10 caracteres como mínimo**, guardadas con bcrypt (`BCRYPT_ROUNDS`).
- Tras **5 intentos fallidos** por combinación correo + IP, el acceso se bloquea temporalmente (`auth.locked`).
- La sesión se regenera al iniciar y se invalida al cerrar; caduca tras `SESSION_LIFETIME` minutos de inactividad.
- Todos los inicios, cierres, fallos y bloqueos quedan en los [registros](registros.md).

Todos los usuarios tienen los mismos permisos.

## Imágenes

- Se guardan en `storage/app/private/comparisons/AAAA/MM/<uuid>.<ext>`, **fuera de `public/`**: no hay URL directa.
- La web las sirve a través de una ruta que exige sesión, con `Cache-Control: private` y `X-Content-Type-Options: nosniff`.
- La API y el envío reciben **URLs firmadas** que caducan (24 h por defecto). Una firma alterada o caducada responde `403`.
- Si el motor falla, las fotos subidas se borran de inmediato.
- Al eliminar una comparación se borran el registro y las dos fotos.
- Solo se aceptan JPG y PNG de hasta 5 MB, validados por contenido (no solo por extensión).

**Retención:** FaceMatch no borra fotos automáticamente. Define cuánto tiempo las necesitas y elimina las comparaciones antiguas desde el historial (o con una tarea programada propia).

## Tokens

| Token | Para qué | Cómo rotarlo |
|---|---|---|
| `APP_KEY` | Cifra sesiones y firma URLs | `php artisan key:generate` (cierra todas las sesiones e invalida las URLs firmadas) |
| `FACE_API_TOKEN` | Autentica a los clientes de la API | `php artisan facematch:tokens --force` y compártelo con los clientes |
| `FACE_SERVICE_TOKEN` | Laravel ↔ `face:serve` | `php artisan facematch:tokens --force` y reinicia `face:serve` |
| `FACE_FORWARD_TOKEN` | Lo valida el otro proyecto | Cámbialo en ambos sistemas a la vez |

- Los tokens se comparan en tiempo constante (`hash_equals`).
- **Nunca** subas el `.env` al repositorio (ya está en `.gitignore`) y no reutilices valores de ejemplo.
- La API no debe llamarse desde el navegador de tus usuarios: el token quedaría expuesto.

## Servicio facial

- Escucha solo en `127.0.0.1`; **no lo publiques** en internet ni en la red local.
- Exige `X-Face-Token` en cada petición.
- Solo acepta rutas de archivos dentro de `storage/app/private`, para que nadie lo use para leer otros archivos del servidor.

## Límites

| Límite | Valor |
|---|---|
| Intentos de login | 5 por correo + IP; luego bloqueo temporal |
| API | `FACE_API_RATE_LIMIT` peticiones/min por token (60) |
| Tamaño de imagen | 5 MB (ajusta también `upload_max_filesize` y `post_max_size` de PHP) |

## Lista de verificación para producción

- [ ] `APP_ENV=production` y `APP_DEBUG=false`.
- [ ] HTTPS con certificado válido; `APP_URL` con `https://`.
- [ ] `SESSION_SECURE_COOKIE=true`.
- [ ] Tokens generados con `php artisan facematch:tokens`.
- [ ] `face:serve` solo en `127.0.0.1` y supervisado (systemd, Supervisor o NSSM).
- [ ] Permisos de `storage/` solo para el usuario del servidor web.
- [ ] Copias de seguridad de la base de datos **y** de `storage/app/private`, cifradas.
- [ ] Política de retención de fotos y de registros definida.
- [ ] Aviso de privacidad y consentimiento de las personas fotografiadas conforme a la ley aplicable (GDPR, LFPDPPP, LGPD, BIPA…).

## Uso responsable

El reconocimiento facial puede fallar y tiene sesgos conocidos según iluminación, edad, tono de piel o calidad de la cámara. Úsalo como **apoyo** a una decisión, no como único criterio para negar un servicio o acusar a alguien, y ofrece siempre una vía de revisión humana.

## Reportar vulnerabilidades

Ver [SECURITY.md](../SECURITY.md).
