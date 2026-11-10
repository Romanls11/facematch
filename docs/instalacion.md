# Instalación

Guía paso a paso para tener FaceMatch funcionando en tu equipo. Para un servidor de producción, después de esto sigue [despliegue.md](despliegue.md).

## 1. Requisitos

| Herramienta | Versión | Notas |
|---|---|---|
| PHP | **8.3 o superior** | Extensiones: `pdo_pgsql`, `mbstring`, `openssl`, `curl`, `fileinfo`, `gd`, `intl`, `zip` |
| Composer | 2.x | |
| Node.js | **18 o superior** | Probado con 22 y 24. Ejecuta el motor facial local |
| PostgreSQL | 14 o superior | Probado con 18 |
| Git | cualquiera | |

> **¿Por qué no `@tensorflow/tfjs-node`?** No tiene binarios precompilados para todas las combinaciones de sistema operativo y versión de Node (por ejemplo, Windows con Node 22). FaceMatch usa el backend **WASM** de TensorFlow.js: es portátil, no necesita compilar nada y sus archivos `.wasm` se cargan desde `node_modules`, no desde un CDN.

### Comprobar versiones

```bash
php -v
php -m | grep -i pdo_pgsql     # en PowerShell: php -m | Select-String pdo_pgsql
composer -V
node -v
psql --version
```

### Windows: varias versiones de PHP

Si tu `php` del `PATH` es antiguo, puedes instalar PHP 8.4 en otra carpeta (por ejemplo `C:\php84`) y usar la ruta completa sin tocar el `PATH`:

```powershell
C:\php84\php.exe -v
C:\php84\php.exe C:\composer\composer.phar install
C:\php84\php.exe artisan migrate
```

En el `php.ini` de esa instalación habilita `extension=pdo_pgsql`, `pgsql`, `mbstring`, `openssl`, `curl`, `fileinfo`, `gd`, `intl` y `zip`, y configura `curl.cainfo` con un `cacert.pem` para que Composer pueda descargar paquetes por HTTPS.

## 2. Obtener el código

```bash
git clone https://github.com/Romanls11/facematch.git
cd facematch
```

## 3. Dependencias

```bash
composer install
npm install
```

`npm install` descarga los modelos de face-api (≈ 12 MB, dentro de `node_modules/@vladmandic/face-api/model`) y `sharp`.

## 4. Configuración

```bash
cp .env.example .env          # PowerShell: Copy-Item .env.example .env
php artisan key:generate
php artisan facematch:tokens  # genera FACE_SERVICE_TOKEN y FACE_API_TOKEN
```

Edita el `.env` con los datos de tu base de datos:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=facematch
DB_USERNAME=postgres
DB_PASSWORD="tu-contraseña"     # entre comillas si tiene * # $ o espacios
```

**Solo en Windows:** `php artisan serve` no pasa el `PATH` a los procesos hijos, así que indica la ruta completa de Node:

```dotenv
FACE_NODE_BINARY="C:/Program Files/nodejs/node.exe"
```

Todas las variables están explicadas en [configuracion.md](configuracion.md).

## 5. Base de datos

```bash
createdb -U postgres facematch     # o CREATE DATABASE facematch; desde psql o pgAdmin
php artisan migrate
php artisan db:show                # comprueba la conexión
```

Si PostgreSQL rechaza la contraseña (`password authentication failed`), revisa el usuario y la contraseña en pgAdmin o con `psql -U postgres -h localhost`. El host y el puerto suelen estar bien si el error menciona la autenticación.

## 6. Compilar la interfaz

```bash
npm run build        # producción
# o
npm run dev          # desarrollo, con recarga en caliente
```

## 7. Crear tu usuario

La interfaz web requiere sesión y **no tiene registro público**. Crea el primer usuario:

```bash
php artisan facematch:user
```

El comando pide correo, nombre y contraseña (mínimo 10 caracteres, se escribe oculta). Otras opciones en [seguridad.md](seguridad.md#usuarios).

## 8. Arrancar

En dos terminales:

```bash
php artisan serve       # http://127.0.0.1:8000
php artisan face:serve  # motor facial en modo rápido (127.0.0.1:3311)
```

Abre <http://127.0.0.1:8000>, inicia sesión y compara dos fotos. Puedes usar las de `tests/Fixtures/faces`:

| Prueba | Resultado esperado |
|---|---|
| `person_a_1.jpg` vs `person_a_2.jpg` | Coinciden (~88 %) |
| `person_a_1.jpg` vs `person_b.jpg` | No coinciden (~30 %) |
| `blurry.jpg` en modo **Verificar** | Rechazada por nitidez |

`face:serve` es opcional: sin él FaceMatch funciona igual, pero cada análisis tarda más porque arranca Node y carga los modelos (ver [motores.md](motores.md#modo-rápido-face-serve)). La portada indica si estás en **Modo rápido** o **Modo lento**.

## Problemas frecuentes

| Síntoma | Causa y solución |
|---|---|
| `Vite manifest not found` | Falta `npm run build` (o `npm run dev` en marcha). |
| «Modo lento» en la portada | `face:serve` no está corriendo o `FACE_SERVICE_TOKEN` no coincide; reinícialo tras cambiar el token. |
| `El motor facial local falló: … node` | Laravel no encuentra Node: define `FACE_NODE_BINARY` con la ruta completa. |
| `could not find driver` | Falta la extensión `pdo_pgsql` en el `php.ini`. |
| Las fotos no se ven en el historial | Inicia sesión; las imágenes son privadas y solo se sirven con sesión. |
| `Las rutas deben existir y estar dentro de FACE_SERVICE_ROOT` | El servicio solo lee `storage/app/private`. Reinicia `face:serve` si actualizaste desde una versión anterior. |
| API responde `503` | Falta `FACE_API_TOKEN`: ejecuta `php artisan facematch:tokens`. |

## Actualizar desde una versión anterior

```bash
git pull
composer install
npm install && npm run build
php artisan migrate      # mueve las fotos antiguas de storage/app/public al disco privado
php artisan config:clear
```

Después reinicia `face:serve`.
