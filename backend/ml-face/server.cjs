const http = require('node:http');
const fs = require('node:fs');
const fsp = require('node:fs/promises');
const path = require('node:path');
const { pathToFileURL } = require('node:url');
const jpeg = require('jpeg-js');
const { PNG } = require('pngjs');

const nativeFetch = global.fetch ? global.fetch.bind(global) : null;

function loadDotEnv(envPath) {
  if (!fs.existsSync(envPath)) {
    return;
  }

  const lines = fs.readFileSync(envPath, 'utf8').split(/\r?\n/);

  for (const line of lines) {
    const trimmed = line.trim();

    if (!trimmed || trimmed.startsWith('#')) {
      continue;
    }

    const separatorIndex = trimmed.indexOf('=');
    if (separatorIndex === -1) {
      continue;
    }

    const key = trimmed.slice(0, separatorIndex).trim();
    if (!key || process.env[key] !== undefined) {
      continue;
    }

    let value = trimmed.slice(separatorIndex + 1).trim();
    if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) {
      value = value.slice(1, -1);
    }

    process.env[key] = value;
  }
}

loadDotEnv(path.join(__dirname, '../.env'));

function normalizePositiveInteger(value, fallback) {
  const parsed = Number(value);
  return Number.isFinite(parsed) && parsed > 0 ? Math.round(parsed) : fallback;
}

function normalizePositiveNumber(value, fallback) {
  const parsed = Number(value);
  return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback;
}

function contentTypeFromPath(filePath) {
  const normalized = filePath.toLowerCase();

  if (normalized.endsWith('.json')) return 'application/json';
  if (normalized.endsWith('.bin')) return 'application/octet-stream';
  if (normalized.endsWith('.wasm')) return 'application/wasm';
  return 'application/octet-stream';
}

async function fetchWithFileSupport(input, init) {
  const resource = typeof input === 'string'
    ? input
    : input instanceof URL
      ? input.href
      : input?.url;

  if (typeof resource === 'string' && resource.startsWith('file://')) {
    const fileUrl = new URL(resource);

    try {
      const data = await fsp.readFile(fileUrl);
      return new Response(data, {
        status: 200,
        headers: { 'Content-Type': contentTypeFromPath(fileUrl.pathname) },
      });
    } catch {
      return new Response(null, { status: 404, statusText: 'Not Found' });
    }
  }

  if (!nativeFetch) {
    throw new Error('Fetch API tidak tersedia.');
  }

  return nativeFetch(input, init);
}

global.fetch = fetchWithFileSupport;

const Human = require(path.join(__dirname, '../node_modules/@vladmandic/human/dist/human.node-wasm.js'));

const host = process.env.FACE_ENGINE_HOST || '127.0.0.1';
const port = normalizePositiveInteger(process.env.FACE_ENGINE_PORT || 3899, 3899);
const thresholdDefault = normalizePositiveNumber(process.env.FACE_MATCH_SIMILARITY_THRESHOLD || 0.60, 0.60);
const maxImageSide = normalizePositiveInteger(process.env.FACE_ENGINE_MAX_IMAGE_SIDE || 320, 320);
const maxPayloadBytes = 3 * 1024 * 1024;
// Token rahasia antara ERP dan engine. Dulu tanpa autentikasi dan ambang
// diambil dari permintaan - apa pun di jaringan backend bisa memanggil
// /compare dengan threshold 0. Kosong = tanpa token (pengembangan lokal).
const engineToken = process.env.FACE_ENGINE_TOKEN || '';

function directoryUrl(dirPath) {
  const href = pathToFileURL(path.resolve(dirPath)).href;
  return href.endsWith('/') ? href : `${href}/`;
}

const modelBasePath = directoryUrl(path.join(__dirname, '../node_modules/@vladmandic/human/models'));
const wasmPath = directoryUrl(path.join(__dirname, '../node_modules/@tensorflow/tfjs-backend-wasm/dist'));

const antispoofAvailable = fs.existsSync(path.join(__dirname, '../node_modules/@vladmandic/human/models/antispoof.json'));

const humanConfig = {
  backend: 'wasm',
  wasmPath,
  debug: false,
  async: false,
  warmup: 'none',
  cacheSensitivity: 0,
  body: { enabled: false },
  hand: { enabled: false },
  gesture: { enabled: false },
  object: { enabled: false },
  segmentation: { enabled: false },
  face: {
    enabled: true,
    detector: {
      enabled: true,
      rotation: false,
      // >1 supaya wajah kedua di bingkai benar-benar terdeteksi (dulu 1: tidak pernah).
      maxDetected: 3,
      skipFrames: 0,
      skipTime: 0,
      minConfidence: 0.35,
    },
    mesh: { enabled: false },
    iris: { enabled: false },
    emotion: { enabled: false },
    // Skor 0..1: antispoof = bukan foto cetak/layar, liveness = wajah hidup.
    // Engine hanya MELAPORKAN skor; keputusan (pantau/tolak) ada di ERP.
    antispoof: { enabled: antispoofAvailable, skipFrames: 0, skipTime: 0 },
    liveness: { enabled: true, skipFrames: 0, skipTime: 0 },
    description: {
      enabled: true,
      skipFrames: 0,
      skipTime: 0,
      minConfidence: 0.1,
      modelPath: 'faceres.json',
    },
  },
  modelBasePath,
};

const human = new Human.Human(humanConfig);
let ready = false;
let initError = null;

function sendJson(res, statusCode, payload) {
  res.writeHead(statusCode, { 'Content-Type': 'application/json; charset=utf-8' });
  res.end(JSON.stringify(payload));
}

function parseJson(req) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    let total = 0;

    req.on('data', (chunk) => {
      total += chunk.length;
      if (total > maxPayloadBytes) {
        reject(new Error('Payload terlalu besar.'));
        req.destroy();
        return;
      }
      chunks.push(chunk);
    });

    req.on('end', () => {
      try {
        const raw = Buffer.concat(chunks).toString('utf8');
        resolve(raw ? JSON.parse(raw) : {});
      } catch {
        reject(new Error('Payload JSON tidak valid.'));
      }
    });

    req.on('error', reject);
  });
}

function normalizeImagePayload(value) {
  if (typeof value !== 'string' || value.trim().length === 0) {
    throw new Error('Foto wajah wajib dikirim.');
  }

  const trimmed = value.trim();
  const dataUrlMatch = trimmed.match(/^data:(image\/[a-zA-Z0-9.+-]+);base64,(.+)$/i);
  const mimeType = dataUrlMatch ? dataUrlMatch[1].toLowerCase() : null;
  const base64Value = dataUrlMatch ? dataUrlMatch[2] : trimmed;
  const buffer = Buffer.from(base64Value, 'base64');

  if (buffer.length === 0) {
    throw new Error('Foto wajah tidak valid.');
  }

  return {
    mimeType: mimeType || detectMimeType(buffer),
    buffer,
  };
}

function detectMimeType(buffer) {
  if (buffer.length >= 3 && buffer[0] === 0xff && buffer[1] === 0xd8 && buffer[2] === 0xff) {
    return 'image/jpeg';
  }

  if (
    buffer.length >= 8 &&
    buffer[0] === 0x89 &&
    buffer[1] === 0x50 &&
    buffer[2] === 0x4e &&
    buffer[3] === 0x47 &&
    buffer[4] === 0x0d &&
    buffer[5] === 0x0a &&
    buffer[6] === 0x1a &&
    buffer[7] === 0x0a
  ) {
    return 'image/png';
  }

  throw new Error('Format gambar tidak didukung. Gunakan JPEG atau PNG.');
}

function decodeImage(buffer, mimeType) {
  if (mimeType === 'image/jpeg' || mimeType === 'image/jpg') {
    return jpeg.decode(buffer, { useTArray: true, formatAsRGBA: true });
  }

  if (mimeType === 'image/png') {
    return PNG.sync.read(buffer);
  }

  throw new Error('Format gambar tidak didukung. Gunakan JPEG atau PNG.');
}

function rgbaToRgb(data) {
  const rgb = new Uint8Array((data.length / 4) * 3);

  for (let sourceIndex = 0, targetIndex = 0; sourceIndex < data.length; sourceIndex += 4) {
    rgb[targetIndex++] = data[sourceIndex];
    rgb[targetIndex++] = data[sourceIndex + 1];
    rgb[targetIndex++] = data[sourceIndex + 2];
  }

  return rgb;
}

function resizeRgb(rgb, width, height, targetLongestSide) {
  const longestSide = Math.max(width, height);
  if (longestSide <= targetLongestSide) {
    return { rgb, width, height };
  }

  // Bilinear: tiap piksel tujuan = rata-rata berbobot 4 piksel sumber terdekat.
  const scale = targetLongestSide / longestSide;
  const targetWidth = Math.max(1, Math.round(width * scale));
  const targetHeight = Math.max(1, Math.round(height * scale));
  const resized = new Uint8Array(targetWidth * targetHeight * 3);
  const xRatio = (width - 1) / Math.max(1, targetWidth - 1);
  const yRatio = (height - 1) / Math.max(1, targetHeight - 1);

  for (let y = 0; y < targetHeight; y++) {
    const sy = y * yRatio;
    const y0 = Math.floor(sy);
    const y1 = Math.min(height - 1, y0 + 1);
    const fy = sy - y0;

    for (let x = 0; x < targetWidth; x++) {
      const sx = x * xRatio;
      const x0 = Math.floor(sx);
      const x1 = Math.min(width - 1, x0 + 1);
      const fx = sx - x0;
      const t = (y * targetWidth + x) * 3;

      for (let c = 0; c < 3; c++) {
        const a = rgb[(y0 * width + x0) * 3 + c];
        const b = rgb[(y0 * width + x1) * 3 + c];
        const d = rgb[(y1 * width + x0) * 3 + c];
        const e = rgb[(y1 * width + x1) * 3 + c];
        resized[t + c] = Math.round((a * (1 - fx) + b * fx) * (1 - fy) + (d * (1 - fx) + e * fx) * fy);
      }
    }
  }

  return { rgb: resized, width: targetWidth, height: targetHeight };
}

function imageToTensor(imageBase64) {
  const { buffer, mimeType } = normalizeImagePayload(imageBase64);
  const decoded = decodeImage(buffer, mimeType);

  if (!decoded || !decoded.width || !decoded.height || !decoded.data || decoded.data.length === 0) {
    throw new Error('Foto wajah tidak dapat diproses.');
  }

  const rgb = rgbaToRgb(decoded.data);
  const resized = resizeRgb(rgb, decoded.width, decoded.height, maxImageSide);

  return human.tf.tensor3d(resized.rgb, [resized.height, resized.width, 3], 'int32');
}

function normalizeReferenceDescriptor(value) {
  if (!Array.isArray(value) || value.length === 0) {
    throw new Error('Descriptor referensi tidak valid.');
  }

  const normalized = value.map((item) => Number(item));
  if (normalized.some((item) => !Number.isFinite(item))) {
    throw new Error('Descriptor referensi tidak valid.');
  }

  return normalized;
}

async function init() {
  try {
    await human.init();
    await human.load();
    ready = true;
    console.log(`[face-engine] ready on http://${host}:${port}`);
  } catch (error) {
    initError = error;
    console.error('[face-engine] failed to initialize', error);
  }
}

async function extractDescriptor(imageBase64) {
  const tensor = imageToTensor(imageBase64);
  let result;

  try {
    result = await human.detect(tensor, humanConfig);
  } finally {
    human.tf.dispose(tensor);
  }

  const faces = result?.face || [];
  if (faces.length === 0) {
    throw new Error('Wajah tidak terdeteksi. Pastikan wajah terlihat jelas di kamera.');
  }

  // Wajah utama = yang terbesar (paling dekat kamera). Wajah lain di latar
  // (rekan kerja di belakang) tidak membuat absen ditolak; jumlahnya
  // dilaporkan supaya ERP bisa menandai.
  const area = (f) => (Array.isArray(f.box) ? f.box[2] * f.box[3] : 0);
  const face = faces.slice().sort((a, b) => area(b) - area(a))[0];

  const embedding = face?.embedding;
  const descriptor = Array.isArray(embedding)
    ? embedding.map((item) => Number(item))
    : ArrayBuffer.isView(embedding)
      ? Array.from(embedding, (item) => Number(item))
      : [];

  if (descriptor.length === 0 || descriptor.some((item) => !Number.isFinite(item))) {
    throw new Error('Descriptor wajah tidak berhasil dihitung.');
  }

  const num = (v) => (typeof v === 'number' && Number.isFinite(v) ? Math.round(v * 1000) / 1000 : null);

  return {
    descriptor,
    faceCount: faces.length,
    quality: {
      // boxScore = keyakinan detektor; faceScore butuh model mesh (dimatikan) sehingga selalu 0.
      faceScore: num(face.boxScore ?? face.score),
      faceSize: Array.isArray(face.box) ? Math.round(Math.min(face.box[2], face.box[3])) : null,
      liveness: num(face.live),
      antispoof: num(face.real),
      antispoofAvailable,
    },
  };
}

async function handleExtract(body, res) {
  try {
    const payload = await extractDescriptor(body.imageBase64);
    sendJson(res, 200, payload);
  } catch (error) {
    sendJson(res, 422, { message: error.message || 'Gagal mengekstrak descriptor wajah.' });
  }
}

async function handleCompare(body, res) {
  let detected;
  let reference;

  try {
    detected = await extractDescriptor(body.imageBase64);
    reference = normalizeReferenceDescriptor(body.referenceDescriptor);
  } catch (error) {
    sendJson(res, 422, { message: error.message || 'Gagal membandingkan wajah.' });
    return;
  }

  if (reference.length !== detected.descriptor.length) {
    sendJson(res, 422, { message: 'Descriptor referensi tidak cocok. Silakan daftar ulang wajah Anda.' });
    return;
  }

  // Ambang hanya dari konfigurasi engine - nilai dari permintaan diabaikan.
  const normalizedThreshold = thresholdDefault;
  const similarity = human.match.similarity(reference, detected.descriptor, { order: 2 });

  sendJson(res, 200, {
    matched: similarity >= normalizedThreshold,
    similarity,
    threshold: normalizedThreshold,
    faceCount: detected.faceCount,
    quality: detected.quality,
  });
}

const server = http.createServer(async (req, res) => {
  if (req.method === 'GET' && req.url === '/health') {
    sendJson(res, ready ? 200 : 503, {
      status: ready ? 'ok' : 'starting',
      error: initError ? String(initError.message || initError) : null,
      backend: humanConfig.backend,
      maxImageSide,
      liveness: true,
      antispoof: antispoofAvailable,
      tokenRequired: Boolean(engineToken),
    });
    return;
  }

  if (req.method !== 'POST') {
    sendJson(res, 404, { message: 'Not found.' });
    return;
  }

  if (!ready) {
    sendJson(res, 503, {
      message: 'Engine Human belum siap dipakai.',
      error: initError ? String(initError.message || initError) : null,
    });
    return;
  }

  if (engineToken && req.headers['x-face-engine-token'] !== engineToken) {
    sendJson(res, 401, { message: 'Token engine tidak valid.' });
    return;
  }

  let body;
  try {
    body = await parseJson(req);
  } catch (error) {
    sendJson(res, 400, { message: error.message || 'Request tidak valid.' });
    return;
  }

  if (req.url === '/extract') {
    await handleExtract(body, res);
    return;
  }

  if (req.url === '/compare') {
    await handleCompare(body, res);
    return;
  }

  sendJson(res, 404, { message: 'Not found.' });
});

server.listen(port, host, () => {
  console.log(`[face-engine] booting on http://${host}:${port}`);
});

init();
