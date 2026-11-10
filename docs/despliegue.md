# Despliegue en producción

Guía para un servidor Linux (Ubuntu/Debian) con Nginx y PHP-FPM. Al final hay notas para Windows Server.

## 1. Paquetes

```bash
sudo apt install nginx postgresql php8.4-fpm php8.4-pgsql php8.4-mbstring php8.4-xml \
  php8.4-curl php8.4-gd php8.4-intl php8.4-zip unzip git
# Node 22 LTS (por ejemplo con NodeSource o nvm)
```

## 2. Código y dependencias

```bash
cd /var/www
sudo git clone https://github.com/Romanls11/facematch.git
cd facematch
composer install --no-dev --optimize-autoloader
npm ci && npm run build
```

`npm ci` también instala las dependencias de ejecución del motor (face-api, tfjs, sharp); no borres `node_modules` después del build.

## 3. Entorno

```bash
cp .env.example .env
php artisan key:generate
php artisan facematch:tokens
```

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://facematch.ejemplo.com
SESSION_SECURE_COOKIE=true
LOG_LEVEL=warning

DB_PASSWORD="…"
FACE_NODE_BINARY=/usr/bin/node
```

## 4. Base de datos, permisos y cachés

```bash
sudo -u postgres createuser --pwprompt facematch
sudo -u postgres createdb -O facematch facematch

php artisan migrate --force
php artisan facematch:user

sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache

php artisan config:cache
php artisan route:cache
php artisan view:cache
```

> Con `config:cache` activo, cada cambio del `.env` requiere volver a ejecutar `php artisan config:cache`.

## 5. Nginx

```nginx
server {
    listen 443 ssl http2;
    server_name facematch.ejemplo.com;
    root /var/www/facematch/public;

    ssl_certificate     /etc/letsencrypt/live/facematch.ejemplo.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/facematch.ejemplo.com/privkey.pem;

    client_max_body_size 12M;   # dos fotos de 5 MB + formulario

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";
    add_header Referrer-Policy "strict-origin-when-cross-origin";

    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known) { deny all; }
}

server {
    listen 80;
    server_name facematch.ejemplo.com;
    return 301 https://$host$request_uri;
}
```

En `php.ini` (FPM): `upload_max_filesize = 6M` y `post_max_size = 12M`.

Si Nginx está detrás de otro proxy o un balanceador, configura los *trusted proxies* de Laravel (`bootstrap/app.php` → `$middleware->trustProxies(at: '*')`) para que las URLs firmadas usen `https`.

## 6. Servicio facial con systemd

`/etc/systemd/system/facematch-face.service`:

```ini
[Unit]
Description=FaceMatch - servicio facial (face:serve)
After=network.target

[Service]
User=www-data
WorkingDirectory=/var/www/facematch
ExecStart=/usr/bin/php artisan face:serve --workers=2
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now facematch-face
sudo systemctl status facematch-face
```

Cada worker carga los modelos en memoria: con 2 workers el proceso ocupa ~400 MB. Ajusta `--workers` a los núcleos y la RAM disponibles.

## 7. Actualizar

```bash
cd /var/www/facematch
php artisan down
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo systemctl restart facematch-face php8.4-fpm
php artisan up
```

## 8. Copias de seguridad

Respalda juntos:

- La base de datos: `pg_dump -Fc facematch > facematch-$(date +%F).dump`
- Las fotos: `storage/app/private/comparisons/`
- El `.env` (en un lugar seguro, aparte)

Cifra las copias: contienen datos biométricos.

## Windows Server

- Sirve `public/` con IIS + PHP (FastCGI) o con Nginx para Windows.
- Usa `FACE_NODE_BINARY="C:/Program Files/nodejs/node.exe"`.
- Registra `php artisan face:serve` como servicio de Windows con [NSSM](https://nssm.cc/):

  ```powershell
  nssm install FaceMatchFace "C:\php84\php.exe" "artisan face:serve --workers=2"
  nssm set FaceMatchFace AppDirectory "C:\inetpub\facematch"
  nssm start FaceMatchFace
  ```
