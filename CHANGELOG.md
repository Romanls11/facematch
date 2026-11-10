# Cambios

Todos los cambios relevantes se documentan aquí. El formato sigue [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/) y el proyecto usa [versionado semántico](https://semver.org/lang/es/).

## [Sin publicar]

## [1.0.0] - 2026-11-10

Primera versión pública.

### Comparación facial
- Modos **Comparar** (similitud) y **Verificar** (similitud + 8 controles de calidad por foto).
- Motor **local** gratuito y sin conexión: `@vladmandic/face-api` + TensorFlow.js con backend WASM, decodificación con `sharp`.
- Servicio persistente opcional `php artisan face:serve` (pool de `worker_threads`) con caída automática al modo proceso.
- Motor **Amazon Rekognition** opcional, con modo simulado para trabajar sin AWS.

### Interfaz web
- Bootstrap 5.3 con modo claro y oscuro, todo en español.
- Arrastrar, pegar o elegir imágenes; intercambio de fotos; umbral ajustable.
- Detalle con medidor de similitud, recuadros de los rostros y controles de calidad.
- Historial paginado, reenvío manual y eliminación.
- Página **Registros** con filtros y página **API** con ejemplos listos para copiar.

### Seguridad
- Login obligatorio para la web, sin registro público; usuarios con `php artisan facematch:user`.
- Bloqueo tras 5 intentos fallidos de acceso.
- Imágenes en disco privado, servidas solo con sesión o mediante URLs firmadas que caducan.
- Tokens generados con `php artisan facematch:tokens`.

### API e integración
- API REST con token Bearer: comparar, listar, consultar y estado del servicio.
- Límite de peticiones por token y cabecera `X-Request-Id`.
- Envío automático del resultado y ambas imágenes a otro proyecto (multipart + Bearer).

### Operación
- Registro de actividad en JSON por línea con rotación diaria.
- Pruebas automatizadas (feature, unitarias e integración con el motor real) y CI en GitHub Actions.

[Sin publicar]: https://github.com/Romanls11/facematch/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/Romanls11/facematch/releases/tag/v1.0.0
