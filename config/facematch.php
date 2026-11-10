<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Motor de comparación facial
    |--------------------------------------------------------------------------
    |
    | local        Gratis y sin conexión: face-api + TensorFlow.js (WASM) en Node.
    | rekognition  Amazon Rekognition (olorunda/laravel-rekognition-face).
    |              Con REKOGNITION_TEST_MODE=true simula las respuestas sin AWS.
    |
    */

    'engine' => env('FACE_ENGINE', 'local'),

    'default_threshold' => (float) env('FACE_DEFAULT_THRESHOLD', 80),

    /*
    |--------------------------------------------------------------------------
    | Motor local (Node)
    |--------------------------------------------------------------------------
    |
    | En Windows, `php artisan serve` no pasa el PATH a los procesos hijos:
    | usa la ruta completa, p. ej. FACE_NODE_BINARY="C:/Program Files/nodejs/node.exe".
    |
    */

    'local' => [
        'node_binary' => env('FACE_NODE_BINARY', 'node'),
        'script' => base_path('scripts/face-engine.cjs'),
        'process_timeout' => (int) env('FACE_PROCESS_TIMEOUT', 120),

        // Distancia euclidiana que equivale al 80 % de similitud.
        'match_distance' => (float) env('FACE_LOCAL_MATCH_DISTANCE', 0.6),

        // Detector del modo Comparar (Verificar siempre usa ssd).
        'detector' => env('FACE_LOCAL_DETECTOR', 'tiny'),

        // Servicio persistente (php artisan face:serve).
        'service_url' => env('FACE_SERVICE_URL', 'http://127.0.0.1:3311'),
        'service_token' => env('FACE_SERVICE_TOKEN'),
        // El servicio solo lee imágenes dentro de esta carpeta (raíz del disco privado).
        'service_root' => storage_path('app/private'),
        'service_timeout' => (int) env('FACE_SERVICE_TIMEOUT', 60),

        // Límites de calidad del modo Verificar.
        'limits' => [
            'min_confidence' => 90.0,
            'strict_single_face' => true,
            'min_sharpness' => 8.0,
            'min_brightness' => 20.0,
            'max_brightness' => 90.0,
            'max_yaw' => 35.0,
            'max_pitch' => 35.0,
            'max_roll' => 25.0,
            'require_eyes_open' => true,
            'min_face_area' => 1.5,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Envío del resultado al otro proyecto
    |--------------------------------------------------------------------------
    */

    'forward' => [
        'url' => env('FACE_FORWARD_URL'),
        'token' => env('FACE_FORWARD_TOKEN'),
        'only_matches' => (bool) env('FACE_FORWARD_ONLY_MATCHES', true),
        'timeout' => (int) env('FACE_FORWARD_TIMEOUT', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | API (Bearer token)
    |--------------------------------------------------------------------------
    */

    'api_token' => env('FACE_API_TOKEN'),

    // Peticiones por minuto permitidas por token (o IP si no hay token).
    'api_rate_limit' => (int) env('FACE_API_RATE_LIMIT', 60),

    /*
    |--------------------------------------------------------------------------
    | Registro de actividad
    |--------------------------------------------------------------------------
    |
    | Una línea JSON por evento en storage/logs/facematch-AAAA-MM-DD.log.
    | Se consulta en /registros. FACE_LOG_CHANNEL=null lo desactiva.
    |
    */

    'log' => [
        'channel' => env('FACE_LOG_CHANNEL', 'facematch'),
        'days' => (int) env('FACE_LOG_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Almacenamiento de las imágenes
    |--------------------------------------------------------------------------
    |
    | Las fotos se guardan en el disco privado «local» (storage/app/private) y
    | solo se sirven a usuarios con sesión o mediante URLs firmadas que caducan
    | (las que reciben la API y el otro proyecto).
    |
    */

    'disk' => 'local',

    // Minutos de validez de las URLs firmadas de las imágenes.
    'image_url_ttl' => (int) env('FACE_IMAGE_URL_TTL', 1440),

];
