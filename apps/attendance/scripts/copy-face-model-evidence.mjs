import { createHash } from 'node:crypto'
import { mkdir, readFile, rm, writeFile } from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const packageName = '@vladmandic/face-api'
const packageVersion = '1.7.15'
const packageRoot = path.join(root, 'node_modules', packageName)
const output = path.join(root, 'public', 'models')
const assets = [
  ['model/tiny_face_detector_model-weights_manifest.json', 'tiny_face_detector_model-weights_manifest.json', '5d1af4849ac48d5b985f4a9b16010c512353ddd6fcc63d50fd0bc9e9e64296e5'],
  ['model/tiny_face_detector_model.bin', 'tiny_face_detector_model.bin', 'b7503ce7df31039b1c43316a9b865cab6a70dd748cc602d3fa28b551503c3871'],
]
const licenseSha256 = '9a3442b79acaf4fbc7c2e07b7e2d8f84af9bb3871bf08a69d80304ab00640e9a'
const sha256 = (payload) => createHash('sha256').update(payload).digest('hex')

const packageManifest = JSON.parse(await readFile(path.join(packageRoot, 'package.json'), 'utf8'))
if (packageManifest.version !== packageVersion) throw new Error('Face model package version mismatch')

const license = await readFile(path.join(packageRoot, 'LICENSE'))
if (sha256(license) !== licenseSha256) throw new Error('Face model license checksum mismatch')

const verifiedAssets = []
for (const [sourceRelative, outputName, expectedSha256] of assets) {
  const payload = await readFile(path.join(packageRoot, sourceRelative))
  if (sha256(payload) !== expectedSha256) throw new Error(`Face model checksum mismatch: ${sourceRelative}`)
  verifiedAssets.push({ sourceRelative, outputName, expectedSha256, payload })
}

await rm(output, { recursive: true, force: true })
await mkdir(path.join(output, 'licenses'), { recursive: true })
for (const asset of verifiedAssets) {
  await writeFile(path.join(output, asset.outputName), asset.payload, { flag: 'wx' })
}
await writeFile(path.join(output, 'licenses', 'face-api-LICENSE.txt'), license, { flag: 'wx' })
await writeFile(path.join(output, 'FACE-MODEL-EVIDENCE.json'), `${JSON.stringify({
  schema: 'ibe.local-face-model-assets',
  version: 1,
  package: { name: packageName, version: packageVersion, licenseSha256 },
  assets: verifiedAssets.map(({ sourceRelative, outputName, expectedSha256 }) => ({ sourceRelative, outputName, sha256: expectedSha256 })),
}, null, 2)}\n`, { flag: 'wx' })

console.log(`LOCAL_FACE_MODEL_EVIDENCE_READY assets=${verifiedAssets.length}`)
