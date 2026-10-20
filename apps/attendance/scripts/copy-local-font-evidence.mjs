import { createHash } from 'node:crypto'
import { mkdir, readFile, rm, writeFile } from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const output = path.join(root, 'public', 'fonts')
const config = {
  package: '@fontsource/rubik',
  version: '5.3.0',
  licenseSha256: '46a250495a963b0b84f2736c0185d0e3af8df9d23427bd425b92a8ece020283c',
  fonts: [
    ['files/rubik-latin-300-normal.woff2', '0e5f00fa0d53d31d213370e03af3899e809f04a921760c94a1825a376d971864'],
    ['files/rubik-latin-400-normal.woff2', '82ac0e78285c43d0cd203427c57ab30fb07b18f8e6166045f729442eb1abfa26'],
    ['files/rubik-latin-500-normal.woff2', '54ad302324aa8000449efd8643186deb46777f0ca4d97f10deb9333536933613'],
    ['files/rubik-latin-600-normal.woff2', '6182043a20faf3fe1a8f0e2c7c964009e11de5e09852617fef3517cbf3c35513'],
    ['files/rubik-latin-700-normal.woff2', 'e423f8ae1668156addf5e32f1056d5b22b5b113a9a23afd364df84a6fa88602d'],
  ],
}

const sha256 = (payload) => createHash('sha256').update(payload).digest('hex')
const packageRoot = path.join(root, 'node_modules', config.package)
const manifest = JSON.parse(await readFile(path.join(packageRoot, 'package.json'), 'utf8'))
if (manifest.version !== config.version) throw new Error('Rubik package version mismatch')
const license = await readFile(path.join(packageRoot, 'LICENSE'))
if (sha256(license) !== config.licenseSha256) throw new Error('Rubik license checksum mismatch')
for (const [relative, expected] of config.fonts) {
  if (sha256(await readFile(path.join(packageRoot, relative))) !== expected) {
    throw new Error(`Rubik font checksum mismatch: ${relative}`)
  }
}
await rm(output, { recursive: true, force: true })
await mkdir(path.join(output, 'licenses'), { recursive: true })
await writeFile(path.join(output, 'licenses', 'rubik-OFL.txt'), license, { flag: 'wx' })
await writeFile(path.join(output, 'FONT-ASSET-EVIDENCE.json'), `${JSON.stringify({
  schema: 'ibe.local-font-assets', version: 1, packages: [config],
}, null, 2)}\n`, { flag: 'wx' })
console.log(`LOCAL_FONT_EVIDENCE_READY fonts=${config.fonts.length}`)
