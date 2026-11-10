# Cómo contribuir

¡Gracias por tu interés en FaceMatch! Estas son las pautas para que tu contribución se integre sin fricción.

## Antes de empezar

- **Errores:** abre un *issue* con los pasos para reproducirlo, lo que esperabas, lo que ocurrió, tu sistema operativo y las versiones de PHP, Node y PostgreSQL. Si el problema está en una foto, **no subas fotos de personas reales sin su permiso**: usa las de `tests/Fixtures/faces`.
- **Vulnerabilidades:** no abras un issue público; sigue [SECURITY.md](SECURITY.md).
- **Funciones nuevas:** abre primero un issue para comentarlo; así evitamos trabajo duplicado.

## Entorno de desarrollo

Sigue [docs/instalacion.md](docs/instalacion.md). Para desarrollar conviene tener tres terminales:

```bash
php artisan serve        # aplicación
php artisan face:serve   # motor facial (modo rápido)
npm run dev              # Vite con recarga en caliente
```

Crea tu usuario con `php artisan facematch:user`.

## Flujo de trabajo

1. Haz un *fork* y crea una rama desde `main`: `git checkout -b mejora/descripcion-corta`.
2. Haz cambios pequeños y enfocados.
3. Agrega o actualiza pruebas.
4. Comprueba que todo pasa:

   ```bash
   vendor/bin/pint                         # formato (PSR-12 + reglas de Laravel)
   php artisan test                        # unitarias y de feature
   php artisan test --group=integration    # motor real (requiere npm install)
   ```

5. Actualiza la documentación (`README.md`, `docs/`) y el `CHANGELOG.md` en la sección *Sin publicar*.
6. Abre el *pull request* explicando **qué** cambia y **por qué**.

## Convenciones

- **Idioma:** la interfaz, los mensajes de error, los registros y la documentación están en **español**. Los nombres de clases, métodos y variables, en inglés.
- **Código PHP:** estilo de Laravel, validado con Pint. Tipos explícitos en parámetros y retornos.
- **Motor Node** (`scripts/face-engine.cjs`): CommonJS, sin dependencias nativas además de `sharp`. **No** agregues `@tensorflow/tfjs-node`: no tiene binarios para todas las plataformas (ver [docs/motores.md](docs/motores.md)).
- **Mensajes de commit:** en imperativo y breves, por ejemplo `Agrega filtro por origen en /registros`.
- **Privacidad:** nunca registres imágenes, descriptores faciales ni tokens en los logs.

## Pruebas

- Las pruebas de feature no ejecutan el motor real: usan `Http::fake()` para el servicio y `Process::fake()` para el proceso Node (ver `tests/Concerns/FakesFaceEngine.php`).
- Las de integración (`#[Group('integration')]`) ejecutan el motor real con las fotos de dominio público de `tests/Fixtures/faces`.
- Rekognition se prueba en modo simulado (`REKOGNITION_TEST_MODE=true`); no hacen falta credenciales de AWS.

## Licencia

Al contribuir aceptas que tu código se publique bajo la [licencia MIT](LICENSE) del proyecto.
