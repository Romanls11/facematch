#!/usr/bin/env node
/**
 * FaceMatch · motor facial local (gratis, sin conexión, sin llaves).
 *
 * Un solo archivo con tres modos:
 *
 *   CLI       node face-engine.cjs origen.jpg destino.jpg [tiny|ssd]
 *             Imprime un JSON con los rostros de ambas imágenes y la distancia.
 *
 *   Servicio  node face-engine.cjs --serve [--host=127.0.0.1] [--port=3311] [--workers=2]
 *             Servidor HTTP persistente con un pool de worker_threads que cargan
 *             los modelos una sola vez.  Requiere FACE_SERVICE_TOKEN y
 *             FACE_SERVICE_ROOT en el entorno.
 *
 *   Worker    Este mismo archivo cuando se ejecuta dentro de un worker_thread.
 *
 * Stack: @vladmandic/face-api + @tensorflow/tfjs + @tensorflow/tfjs-backend-wasm
 * (binarios WASM locales, sin CDN) y sharp para decodificar las imágenes.
 */
'use strict';

const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const crypto = require('node:crypto');
const { Worker, isMainThread, parentPort, workerData } = require('node:worker_threads');

const ENGINE_NAME = 'face-api/tfjs-wasm';
const MAX_SIDE = 1024;
const SHARPNESS_SIZE = 112;
const EAR_OPEN = 0.2;
const ROOT_DIR = path.resolve(__dirname, '..');

// ---------------------------------------------------------------------------
// Utilidades
// ---------------------------------------------------------------------------

const round = (value, decimals = 2) => {
    const f = 10 ** decimals;
    return Math.round(value * f) / f;
};

const clamp = (value, min, max) => Math.min(max, Math.max(min, value));

const dist = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);

const deg = (rad) => (rad * 180) / Math.PI;

function euclidean(a, b) {
    let sum = 0;
    for (let i = 0; i < a.length; i++) {
        const d = a[i] - b[i];
        sum += d * d;
    }
    return Math.sqrt(sum);
}

function parseArgs(argv) {
    const flags = {};
    const positional = [];
    for (const arg of argv) {
        if (arg.startsWith('--')) {
            const [key, value] = arg.slice(2).split('=');
            flags[key] = value === undefined ? true : value;
        } else {
            positional.push(arg);
        }
    }
    return { flags, positional };
}

/** tfjs escribe avisos por consola; en modo CLI stdout debe quedar limpio para el JSON. */
function silenceStdout() {
    const toStderr = (...args) => process.stderr.write(args.map(String).join(' ') + '\n');
    console.log = toStderr;
    console.info = toStderr;
    console.warn = toStderr;
    console.debug = toStderr;
}

// ---------------------------------------------------------------------------
// Análisis de una imagen (se ejecuta en el worker o en el proceso CLI)
// ---------------------------------------------------------------------------

let runtime = null;

async function loadRuntime() {
    if (runtime) {
        return runtime;
    }

    const started = Date.now();
    const tf = require('@tensorflow/tfjs');
    const wasm = require('@tensorflow/tfjs-backend-wasm');
    const faceapi = require('@vladmandic/face-api/dist/face-api.node-wasm.js');
    const sharp = require('sharp');

    // En Windows sharp mantiene abiertos los archivos si usa caché.
    sharp.cache(false);
    sharp.concurrency(1);

    // Binarios WASM locales (nunca desde un CDN).
    const wasmDir = path.dirname(require.resolve('@tensorflow/tfjs-backend-wasm/package.json'));
    const dist = path.join(wasmDir, 'dist');
    wasm.setWasmPaths({
        'tfjs-backend-wasm.wasm': path.join(dist, 'tfjs-backend-wasm.wasm'),
        'tfjs-backend-wasm-simd.wasm': path.join(dist, 'tfjs-backend-wasm-simd.wasm'),
        'tfjs-backend-wasm-threaded-simd.wasm': path.join(dist, 'tfjs-backend-wasm-threaded-simd.wasm'),
    });
    tf.env().set('WASM_HAS_MULTITHREAD_SUPPORT', false);

    await tf.setBackend('wasm');
    await tf.ready();

    const modelDir = path.join(path.dirname(require.resolve('@vladmandic/face-api/package.json')), 'model');
    await Promise.all([
        faceapi.nets.ssdMobilenetv1.loadFromDisk(modelDir),
        faceapi.nets.tinyFaceDetector.loadFromDisk(modelDir),
        faceapi.nets.faceLandmark68Net.loadFromDisk(modelDir),
        faceapi.nets.faceRecognitionNet.loadFromDisk(modelDir),
    ]);

    runtime = { tf, faceapi, sharp, loadMs: Date.now() - started };
    return runtime;
}

/** Decodifica con sharp: rotación EXIF, máximo 1024 px y RGB sin alfa. */
async function decodeImage(file) {
    const { sharp } = runtime;
    const input = fs.readFileSync(file);
    const { data, info } = await sharp(input, { failOn: 'error' })
        .rotate()
        .resize({ width: MAX_SIDE, height: MAX_SIDE, fit: 'inside', withoutEnlargement: true })
        .removeAlpha()
        .toColourspace('srgb')
        .raw()
        .toBuffer({ resolveWithObject: true });

    return { data, width: info.width, height: info.height, channels: info.channels };
}

function detectorOptions(detector) {
    const { faceapi } = runtime;
    if (detector === 'ssd') {
        return new faceapi.SsdMobilenetv1Options({ minConfidence: 0.5, maxResults: 10 });
    }
    return new faceapi.TinyFaceDetectorOptions({ inputSize: 416, scoreThreshold: 0.5 });
}

async function detectFaces(tensor, detector) {
    const { faceapi } = runtime;
    return faceapi
        .detectAllFaces(tensor, detectorOptions(detector))
        .withFaceLandmarks()
        .withFaceDescriptors();
}

/** Recorte del rostro (en píxeles) limitado a los bordes de la imagen. */
function cropRect(box, image) {
    const left = clamp(Math.floor(box.x), 0, image.width - 1);
    const top = clamp(Math.floor(box.y), 0, image.height - 1);
    const right = clamp(Math.ceil(box.x + box.width), left + 1, image.width);
    const bottom = clamp(Math.ceil(box.y + box.height), top + 1, image.height);
    return { left, top, width: right - left, height: bottom - top };
}

/** Brillo: gris promedio del recorte del rostro, en escala 0–100. */
function brightness(image, rect) {
    const { data, width, channels } = image;
    let sum = 0;
    let count = 0;
    for (let y = rect.top; y < rect.top + rect.height; y++) {
        for (let x = rect.left; x < rect.left + rect.width; x++) {
            const i = (y * width + x) * channels;
            sum += 0.299 * data[i] + 0.587 * data[i + 1] + 0.114 * data[i + 2];
            count++;
        }
    }
    return count ? (sum / count / 255) * 100 : 0;
}

/** Nitidez: varianza del Laplaciano / 10 sobre el rostro reescalado a 112×112. */
async function sharpness(image, rect) {
    const { sharp } = runtime;
    const gray = await sharp(image.data, {
        raw: { width: image.width, height: image.height, channels: image.channels },
    })
        .extract(rect)
        .resize(SHARPNESS_SIZE, SHARPNESS_SIZE, { fit: 'fill' })
        .greyscale()
        .raw()
        .toBuffer();

    const n = SHARPNESS_SIZE;
    const values = [];
    for (let y = 1; y < n - 1; y++) {
        for (let x = 1; x < n - 1; x++) {
            const c = gray[y * n + x];
            values.push(gray[(y - 1) * n + x] + gray[(y + 1) * n + x] + gray[y * n + x - 1] + gray[y * n + x + 1] - 4 * c);
        }
    }
    const mean = values.reduce((a, b) => a + b, 0) / values.length;
    const variance = values.reduce((a, b) => a + (b - mean) ** 2, 0) / values.length;
    return Math.min(100, variance / 10);
}

/** Eye Aspect Ratio de un ojo (6 puntos). */
function eyeAspectRatio(p) {
    return (dist(p[1], p[5]) + dist(p[2], p[4])) / (2 * dist(p[0], p[3]));
}

/**
 * Pose aproximada (grados) a partir de los 68 puntos:
 *  - roll: inclinación de la línea de los ojos.
 *  - yaw: asimetría de la punta de la nariz respecto a los bordes de la mandíbula.
 *  - pitch: posición vertical de la nariz entre la línea de los ojos y el mentón.
 */
function estimatePose(points) {
    const eyeL = centroid(points.slice(36, 42));
    const eyeR = centroid(points.slice(42, 48));
    const nose = points[30];
    const chin = points[8];

    const roll = deg(Math.atan2(eyeR.y - eyeL.y, eyeR.x - eyeL.x));

    const dl = dist(nose, points[0]);
    const dr = dist(nose, points[16]);
    const yaw = deg(Math.atan((2 * (dl - dr)) / (dl + dr)));

    // Proyección sobre el eje perpendicular a la línea de los ojos (corrige el roll).
    const eyeMid = { x: (eyeL.x + eyeR.x) / 2, y: (eyeL.y + eyeR.y) / 2 };
    const angle = Math.atan2(eyeR.y - eyeL.y, eyeR.x - eyeL.x);
    const axis = { x: -Math.sin(angle), y: Math.cos(angle) };
    const proj = (p) => (p.x - eyeMid.x) * axis.x + (p.y - eyeMid.y) * axis.y;
    const ratio = proj(nose) / Math.max(1e-6, proj(chin));
    const pitch = deg(Math.atan((ratio - 0.34) * 3)); // 0.34 ≈ rostro frontal

    return { pitch: round(pitch, 1), roll: round(roll, 1), yaw: round(yaw, 1) };
}

function centroid(points) {
    const sum = points.reduce((acc, p) => ({ x: acc.x + p.x, y: acc.y + p.y }), { x: 0, y: 0 });
    return { x: sum.x / points.length, y: sum.y / points.length };
}

async function describeFace(detection, image) {
    const box = detection.detection.box;
    const points = detection.landmarks.positions;
    const rect = cropRect(box, image);

    const ear = (eyeAspectRatio(points.slice(36, 42)) + eyeAspectRatio(points.slice(42, 48))) / 2;
    const width = rect.width / image.width;
    const height = rect.height / image.height;

    return {
        confidence: round(detection.detection.score * 100, 2),
        bounding_box: {
            width: round(width, 4),
            height: round(height, 4),
            left: round(rect.left / image.width, 4),
            top: round(rect.top / image.height, 4),
            area_percentage: round(width * height * 100, 2),
        },
        pose: estimatePose(points),
        quality: {
            sharpness: round(await sharpness(image, rect), 2),
            brightness: round(brightness(image, rect), 2),
        },
        eyes_open: { value: ear > EAR_OPEN, ear: round(ear, 3) },
        descriptor: Array.from(detection.descriptor),
    };
}

/**
 * Analiza una imagen.  detector: 'tiny' (con reintento en 'ssd' si no hay rostro) o 'ssd'.
 * Devuelve los rostros (con descriptor) ordenados por área de mayor a menor.
 */
async function analyzeImage(file, detector = 'tiny') {
    await loadRuntime();
    const { tf } = runtime;
    const started = Date.now();

    const image = await decodeImage(file);
    const tensor = tf.tensor3d(image.data, [image.height, image.width, 3], 'int32');

    let used = detector === 'ssd' ? 'ssd' : 'tiny';
    let detections;
    try {
        detections = await detectFaces(tensor, used);
        if (detections.length === 0 && used === 'tiny') {
            used = 'ssd';
            detections = await detectFaces(tensor, used);
        }
    } finally {
        tensor.dispose();
    }

    const faces = [];
    for (const detection of detections) {
        faces.push(await describeFace(detection, image));
    }
    faces.sort((a, b) => b.bounding_box.area_percentage - a.bounding_box.area_percentage);

    return { width: image.width, height: image.height, detector: used, elapsed_ms: Date.now() - started, faces };
}

/** Une los análisis de origen y destino en la respuesta final (sin descriptores). */
function buildResult(source, target, started) {
    let distance = null;
    let matchIndex = null;

    if (source.faces.length && target.faces.length) {
        const reference = source.faces[0].descriptor;
        target.faces.forEach((face, index) => {
            const d = euclidean(reference, face.descriptor);
            if (distance === null || d < distance) {
                distance = d;
                matchIndex = index;
            }
        });
    }

    const strip = (img) => ({
        width: img.width,
        height: img.height,
        detector: img.detector,
        faces: img.faces.map(({ descriptor, ...rest }) => rest),
    });

    return {
        ok: true,
        engine: ENGINE_NAME,
        elapsed_ms: Date.now() - started,
        distance: distance === null ? null : round(distance, 4),
        match_index: matchIndex,
        source: strip(source),
        target: strip(target),
    };
}

// ---------------------------------------------------------------------------
// Modo CLI
// ---------------------------------------------------------------------------

async function cli() {
    silenceStdout();
    const { positional } = parseArgs(process.argv.slice(2));
    const [sourceFile, targetFile, detector = 'tiny'] = positional;

    const fail = (message, code = 1) => {
        process.stdout.write(JSON.stringify({ ok: false, error: message }) + '\n');
        process.exitCode = code;
    };

    if (!sourceFile || !targetFile) {
        return fail('Uso: node face-engine.cjs origen.jpg destino.jpg [tiny|ssd]  |  node face-engine.cjs --serve', 2);
    }
    for (const file of [sourceFile, targetFile]) {
        if (!fs.existsSync(file)) {
            return fail(`No existe el archivo: ${file}`, 2);
        }
    }

    try {
        const started = Date.now();
        await loadRuntime();
        const source = await analyzeImage(sourceFile, detector);
        const target = await analyzeImage(targetFile, detector);
        process.stdout.write(JSON.stringify(buildResult(source, target, started)) + '\n');
    } catch (error) {
        fail(`No se pudo analizar la imagen: ${error.message}`);
    }
}

// ---------------------------------------------------------------------------
// Modo servicio: pool de worker_threads
// ---------------------------------------------------------------------------

class WorkerPool {
    constructor(size) {
        this.size = size;
        this.workers = [];
        this.queue = [];
        this.pending = new Map();
        this.nextId = 1;
        this.loadTimes = [];
    }

    start() {
        const ready = [];
        for (let i = 0; i < this.size; i++) {
            ready.push(this.spawn());
        }
        return Promise.all(ready);
    }

    spawn() {
        return new Promise((resolve, reject) => {
            const worker = new Worker(__filename, { workerData: { role: 'face-worker' } });
            const slot = { worker, busy: false, task: null, ready: false };

            worker.on('message', (msg) => {
                if (msg.type === 'ready') {
                    slot.ready = true;
                    this.loadTimes.push(msg.loadMs);
                    this.workers.push(slot);
                    resolve();
                    this.drain();
                    return;
                }
                const task = this.pending.get(msg.id);
                if (!task) return;
                this.pending.delete(msg.id);
                slot.busy = false;
                slot.task = null;
                msg.ok ? task.resolve(msg.result) : task.reject(new Error(msg.error));
                this.drain();
            });

            const onDeath = (err) => {
                if (slot.dead) return; // 'error' y 'exit' pueden llegar ambos
                slot.dead = true;
                const wasReady = slot.ready;
                this.workers = this.workers.filter((s) => s !== slot);
                if (slot.task) {
                    this.pending.delete(slot.task.id);
                    slot.task.reject(err instanceof Error ? err : new Error('El worker terminó inesperadamente.'));
                }
                if (!wasReady) {
                    reject(err instanceof Error ? err : new Error('El worker no pudo iniciar.'));
                    return;
                }
                slot.ready = false;
                log(`worker caído (${err && err.message ? err.message : err}); creando uno nuevo…`);
                this.spawn().catch((e) => log(`no se pudo recrear el worker: ${e.message}`));
            };

            worker.on('error', onDeath);
            worker.on('exit', (code) => {
                if (!this.stopping) onDeath(new Error(`exit code ${code}`));
            });
        });
    }

    run(file, detector) {
        return new Promise((resolve, reject) => {
            const task = { id: this.nextId++, file, detector, resolve, reject };
            this.queue.push(task);
            this.drain();
        });
    }

    drain() {
        while (this.queue.length) {
            const slot = this.workers.find((s) => s.ready && !s.busy);
            if (!slot) return;
            const task = this.queue.shift();
            slot.busy = true;
            slot.task = task;
            this.pending.set(task.id, task);
            slot.worker.postMessage({ id: task.id, file: task.file, detector: task.detector });
        }
    }
}

function log(message) {
    process.stdout.write(`[face-service ${new Date().toISOString()}] ${message}\n`);
}

function readDotEnv(key) {
    try {
        const content = fs.readFileSync(path.join(ROOT_DIR, '.env'), 'utf8');
        const match = content.match(new RegExp(`^${key}=(.*)$`, 'm'));
        return match ? match[1].trim().replace(/^["']|["']$/g, '') : null;
    } catch {
        return null;
    }
}

function sendJson(res, status, body) {
    const payload = JSON.stringify(body);
    res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', 'Content-Length': Buffer.byteLength(payload) });
    res.end(payload);
}

function readBody(req, limit = 64 * 1024) {
    return new Promise((resolve, reject) => {
        let size = 0;
        const chunks = [];
        req.on('data', (chunk) => {
            size += chunk.length;
            if (size > limit) {
                reject(Object.assign(new Error('Cuerpo demasiado grande.'), { status: 413 }));
                req.destroy();
                return;
            }
            chunks.push(chunk);
        });
        req.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
        req.on('error', reject);
    });
}

function safeEqual(a, b) {
    const ha = crypto.createHash('sha256').update(String(a)).digest();
    const hb = crypto.createHash('sha256').update(String(b)).digest();
    return crypto.timingSafeEqual(ha, hb);
}

/** Solo se aceptan archivos que estén dentro de FACE_SERVICE_ROOT. */
function resolveInsideRoot(root, file) {
    if (typeof file !== 'string' || file === '') return null;
    let real;
    try {
        real = fs.realpathSync(path.resolve(file));
    } catch {
        return null;
    }
    const norm = (p) => (process.platform === 'win32' ? p.toLowerCase() : p);
    const rootWithSep = root.endsWith(path.sep) ? root : root + path.sep;
    return norm(real).startsWith(norm(rootWithSep)) ? real : null;
}

async function serve() {
    const { flags } = parseArgs(process.argv.slice(2));
    const host = flags.host || process.env.FACE_SERVICE_HOST || '127.0.0.1';
    const port = Number(flags.port || process.env.FACE_SERVICE_PORT || 3311);
    const size = Math.max(1, Number(flags.workers || process.env.FACE_SERVICE_WORKERS || 2));
    // `php artisan face:serve` pasa el token; con `npm run face-service` se lee del .env.
    const token = process.env.FACE_SERVICE_TOKEN || readDotEnv('FACE_SERVICE_TOKEN') || '';
    const rootEnv = process.env.FACE_SERVICE_ROOT || path.join(ROOT_DIR, 'storage', 'app', 'private');

    if (!token) {
        log('ERROR: falta FACE_SERVICE_TOKEN en el entorno.');
        process.exit(1);
    }
    if (!fs.existsSync(rootEnv)) {
        fs.mkdirSync(rootEnv, { recursive: true });
    }
    const root = fs.realpathSync(rootEnv);

    log(`cargando modelos en ${size} worker(s)…`);
    const pool = new WorkerPool(size);
    await pool.start();
    const modelsLoadMs = Math.max(...pool.loadTimes);
    let handled = 0;

    const server = http.createServer(async (req, res) => {
        const url = new URL(req.url, `http://${req.headers.host || 'localhost'}`);
        try {
            if (req.method === 'GET' && url.pathname === '/health') {
                return sendJson(res, 200, {
                    ok: true,
                    engine: ENGINE_NAME,
                    workers: pool.workers.filter((w) => w.ready).length,
                    models_load_ms: modelsLoadMs,
                    handled,
                });
            }

            if (req.method === 'POST' && url.pathname === '/compare') {
                if (!safeEqual(req.headers['x-face-token'] || '', token)) {
                    return sendJson(res, 401, { ok: false, error: 'Token inválido.' });
                }

                let body;
                try {
                    body = JSON.parse(await readBody(req));
                } catch (e) {
                    return sendJson(res, e.status || 400, { ok: false, error: e.status ? e.message : 'JSON inválido.' });
                }

                const source = resolveInsideRoot(root, body.source);
                const target = resolveInsideRoot(root, body.target);
                if (!source || !target) {
                    return sendJson(res, 422, { ok: false, error: 'Las rutas deben existir y estar dentro de FACE_SERVICE_ROOT.' });
                }
                const detector = body.detector === 'ssd' ? 'ssd' : 'tiny';

                const started = Date.now();
                const [a, b] = await Promise.all([pool.run(source, detector), pool.run(target, detector)]);
                handled++;
                const result = buildResult(a, b, started);
                log(`compare ${detector} → ${result.elapsed_ms} ms (d=${result.distance})`);
                return sendJson(res, 200, result);
            }

            return sendJson(res, 404, { ok: false, error: 'Ruta no encontrada.' });
        } catch (error) {
            log(`error: ${error.message}`);
            return sendJson(res, 500, { ok: false, error: `No se pudo analizar la imagen: ${error.message}` });
        }
    });

    server.listen(port, host, () => {
        log(`listo en http://${host}:${port} · ${pool.workers.length} workers · modelos en ${modelsLoadMs} ms · raíz ${root}`);
    });

    const shutdown = () => {
        log('deteniendo…');
        server.close();
        pool.stopping = true;
        pool.workers.forEach((w) => w.worker.terminate());
        process.exit(0);
    };
    process.on('SIGINT', shutdown);
    process.on('SIGTERM', shutdown);
}

// ---------------------------------------------------------------------------
// Modo worker
// ---------------------------------------------------------------------------

async function workerMain() {
    silenceStdout();
    const rt = await loadRuntime();
    parentPort.postMessage({ type: 'ready', loadMs: rt.loadMs });

    parentPort.on('message', async ({ id, file, detector }) => {
        try {
            const result = await analyzeImage(file, detector);
            parentPort.postMessage({ id, ok: true, result });
        } catch (error) {
            parentPort.postMessage({ id, ok: false, error: error.message });
        }
    });
}

// ---------------------------------------------------------------------------
// Arranque — DEBE ir al final del archivo: las clases (WorkerPool) no existen
// antes de su declaración ("Cannot access 'WorkerPool' before initialization").
// ---------------------------------------------------------------------------

if (isMainThread) {
    if (process.argv.includes('--serve')) {
        serve().catch((error) => {
            log(`no se pudo iniciar: ${error.stack || error.message}`);
            process.exit(1);
        });
    } else {
        cli();
    }
} else if (workerData && workerData.role === 'face-worker') {
    workerMain().catch((error) => {
        process.stderr.write(`worker: ${error.stack || error.message}\n`);
        process.exit(1);
    });
}
