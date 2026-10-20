import { readFile, readdir, stat } from 'node:fs/promises'
import { createHash } from 'node:crypto'
import path from 'node:path'

const distRoot = path.resolve(process.argv[2] ?? 'dist')
const allowedHosts = new Set([
  'app-erp.ibereality.com',
  'arxiv.org',
  'developer.mozilla.org',
  'emscripten.org',
  'fb.me',
  'getbootstrap.com',
  'github.com',
  'portal.ibereality.com',
  'react.dev',
  'reactrouter.com',
  'tailwindcss.com',
  'www.w3.org',
  'www.shadertoy.com',
])
const allowedInsecureMetadataHosts = new Set([
  // Static library/license namespace metadata only; never app endpoints.
  'fb.me',
  'www.w3.org',
])
const forbiddenEndpointPatterns = [
  /https?:\/\/(?:[^/]*\.)?localhost(?=[:/"'`]|$)/i,
  /https?:\/\/127(?:\.\d{1,3}){3}(?=[:/"'`]|$)/i,
  /https?:\/\/\[?::1\]?(?=[:/"'`]|$)/i,
  /(?:^|[^a-z0-9-])staging(?:[-.][a-z0-9-]+)*\.ibereality\.com/i,
  /(?:^|[^a-z0-9.-])ibereality\.id(?=[:/"'`]|$)/i,
  /(?:cdn\.jsdelivr\.net|unpkg\.com|raw\.githubusercontent\.com|fonts\.googleapis\.com|fonts\.gstatic\.com|ui-avatars\.com)/i,
  /@master(?:[/'"`]|$)/i,
]
const networkUrlPattern = /https?:\/\/([A-Za-z0-9.-]+)/g
const files = []

async function walk(directory) {
  for (const entry of await readdir(directory, { withFileTypes: true })) {
    const absolute = path.join(directory, entry.name)
    if (entry.isDirectory()) {
      if (entry.name === 'node_modules') {
        throw new Error('node_modules is forbidden in the production bundle')
      }
      await walk(absolute)
      continue
    }
    files.push(absolute)
  }
}

await walk(distRoot)

if (files.length === 0 || files.length > 1_000) {
  throw new Error(`unexpected bundle file count: ${files.length}`)
}
if (files.some((file) => file.endsWith('.map'))) {
  throw new Error('source maps are forbidden in the production bundle')
}

let totalBytes = 0
let apiHostSeen = false
let portalHostSeen = false
const observedHosts = new Set()

for (const file of files) {
  const metadata = await stat(file)
  totalBytes += metadata.size
  if (metadata.size > 8 * 1024 * 1024) {
    throw new Error(`oversized bundle member: ${path.relative(distRoot, file)}`)
  }
  if (!/\.(?:css|html|js|json|svg)$/i.test(file)) {
    continue
  }

  const contents = await readFile(file, 'utf8')
  for (const pattern of forbiddenEndpointPatterns) {
    if (pattern.test(contents)) {
      throw new Error(`forbidden endpoint in ${path.relative(distRoot, file)}`)
    }
  }
  for (const match of contents.matchAll(networkUrlPattern)) {
    const host = match[1].toLowerCase()
    observedHosts.add(host)
    if (!allowedHosts.has(host)) {
      throw new Error(`unapproved network host ${host} in ${path.relative(distRoot, file)}`)
    }
    if (match[0].startsWith('http://') && !allowedInsecureMetadataHosts.has(host)) {
      throw new Error(`insecure network URL in ${path.relative(distRoot, file)}`)
    }
    apiHostSeen ||= host === 'app-erp.ibereality.com'
    portalHostSeen ||= host === 'portal.ibereality.com'
  }
}

const expectedModels = new Map([
  ['models/tiny_face_detector_model-weights_manifest.json', '5d1af4849ac48d5b985f4a9b16010c512353ddd6fcc63d50fd0bc9e9e64296e5'],
  ['models/tiny_face_detector_model.bin', 'b7503ce7df31039b1c43316a9b865cab6a70dd748cc602d3fa28b551503c3871'],
])
for (const [relative, expected] of expectedModels) {
  const payload = await readFile(path.join(distRoot, relative))
  const actual = createHash('sha256').update(payload).digest('hex')
  if (actual !== expected) {
    throw new Error(`face model checksum mismatch: ${relative}`)
  }
}
const faceModelEvidence = JSON.parse(await readFile(path.join(distRoot, 'models/FACE-MODEL-EVIDENCE.json'), 'utf8'))
if (
  faceModelEvidence.schema !== 'ibe.local-face-model-assets'
  || faceModelEvidence.version !== 1
  || faceModelEvidence.package?.name !== '@vladmandic/face-api'
  || faceModelEvidence.package?.version !== '1.7.15'
  || faceModelEvidence.package?.licenseSha256 !== '9a3442b79acaf4fbc7c2e07b7e2d8f84af9bb3871bf08a69d80304ab00640e9a'
) {
  throw new Error('face model evidence contract mismatch')
}
for (const [relative, expected] of expectedModels) {
  const outputName = relative.replace(/^models\//, '')
  const evidenceEntry = faceModelEvidence.assets?.find((entry) => entry.outputName === outputName)
  if (evidenceEntry?.sha256 !== expected) throw new Error(`face model evidence checksum mismatch: ${relative}`)
}
const faceModelLicense = await readFile(path.join(distRoot, 'models/licenses/face-api-LICENSE.txt'))
if (createHash('sha256').update(faceModelLicense).digest('hex') !== '9a3442b79acaf4fbc7c2e07b7e2d8f84af9bb3871bf08a69d80304ab00640e9a') {
  throw new Error('face model license checksum mismatch')
}

if (totalBytes > 64 * 1024 * 1024) {
  throw new Error(`bundle exceeds total size limit: ${totalBytes}`)
}
if (!apiHostSeen || !portalHostSeen) {
  throw new Error('production API and Portal origins must be embedded')
}
const expectedFontHashes = new Set([
  '0e5f00fa0d53d31d213370e03af3899e809f04a921760c94a1825a376d971864',
  '82ac0e78285c43d0cd203427c57ab30fb07b18f8e6166045f729442eb1abfa26',
  '54ad302324aa8000449efd8643186deb46777f0ca4d97f10deb9333536933613',
  '6182043a20faf3fe1a8f0e2c7c964009e11de5e09852617fef3517cbf3c35513',
  'e423f8ae1668156addf5e32f1056d5b22b5b113a9a23afd364df84a6fa88602d',
])
const emittedFontHashes = new Set()
for (const file of files.filter((entry) => entry.endsWith('.woff2'))) {
  emittedFontHashes.add(createHash('sha256').update(await readFile(file)).digest('hex'))
}
for (const expected of expectedFontHashes) {
  if (!emittedFontHashes.has(expected)) throw new Error(`local Rubik font checksum missing: ${expected}`)
}
const fontEvidence = JSON.parse(await readFile(path.join(distRoot, 'fonts/FONT-ASSET-EVIDENCE.json'), 'utf8'))
const rubik = fontEvidence.packages?.find((entry) => entry.package === '@fontsource/rubik')
if (fontEvidence.schema !== 'ibe.local-font-assets' || fontEvidence.version !== 1 || rubik?.version !== '5.3.0') {
  throw new Error('Rubik font evidence contract mismatch')
}
const rubikLicense = await readFile(path.join(distRoot, 'fonts/licenses/rubik-OFL.txt'))
if (createHash('sha256').update(rubikLicense).digest('hex') !== '46a250495a963b0b84f2736c0185d0e3af8df9d23427bd425b92a8ece020283c') {
  throw new Error('Rubik license checksum mismatch')
}

console.log(`ATTENDANCE_BUNDLE_POLICY_PASS files=${files.length} bytes=${totalBytes} hosts=${[...observedHosts].sort().join(',')}`)
