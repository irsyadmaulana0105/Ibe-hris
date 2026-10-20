/**
 * Hasil verifikasi wajah satu absen (attendances.face_check_in/out), sejak
 * 19 Okt 2026. Hanya dikirim ke rute admin; absen lama bernilai null.
 */
export interface FaceCheck {
  similarity: number
  threshold: number
  liveness: number | null
  antispoof: number | null
  face_count: number
  face_size: number | null
  face_score: number | null
  flags: string[]
  mode: 'shadow' | 'enforce'
  checked_at: string
}

export interface EnrollmentQuality {
  liveness?: number | null
  antispoof?: number | null
  antispoofAvailable?: boolean
  faceSize?: number | null
  face_count?: number
}

export const FACE_FLAG_LABELS: Record<string, string> = {
  liveness_low: 'Wajah hidup rendah - mungkin foto/layar',
  antispoof_low: 'Dugaan foto palsu (antispoof)',
  multi_face: 'Lebih dari satu wajah di foto',
  borderline: 'Kecocokan tipis di atas ambang',
  small_face: 'Wajah terlalu kecil/jauh',
}

const SERIOUS = ['liveness_low', 'antispoof_low', 'multi_face']

export const pct = (v: number | null | undefined) => (v == null ? '-' : `${Math.round(v * 100)}%`)

export function isSeriousFaceCheck(c: FaceCheck): boolean {
  return c.flags.some((f) => SERIOUS.includes(f))
}

/** Teks tooltip: tanda + skor ringkas. */
export function faceCheckSummary(c: FaceCheck): string {
  const lines = c.flags.map((f) => `⚠ ${FACE_FLAG_LABELS[f] ?? f}`)
  lines.push(`Kecocokan ${pct(c.similarity)} (ambang ${pct(c.threshold)}) · Hidup ${pct(c.liveness)} · Antispoof ${pct(c.antispoof)}`)
  return lines.join('\n')
}

/** Peringatan kualitas foto pendaftaran (ambang sama dengan backend). */
export function enrollmentWarnings(q: EnrollmentQuality | null | undefined): string[] {
  if (!q) return []
  const w: string[] = []
  if (q.liveness != null && q.liveness < 0.5) w.push('Wajah hidup rendah - mungkin foto dari layar/cetakan')
  if (q.antispoofAvailable !== false && q.antispoof != null && q.antispoof < 0.5) w.push('Dugaan foto palsu (antispoof)')
  if ((q.face_count ?? 1) > 1) w.push(`Terdeteksi ${q.face_count} wajah - pastikan yang terdaftar orang yang benar`)
  if (q.faceSize != null && q.faceSize < 80) w.push('Wajah terlalu kecil/jauh')
  return w
}
