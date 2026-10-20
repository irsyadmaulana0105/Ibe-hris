import { forwardRef } from 'react'

interface Props {
  name: string
  division: string | null
  employmentStatus: string | null  // 'permanent' | 'intern_campus' | 'intern_hub' | etc.
  photoUrl: string | null
}

/**
 * Kartu Identitas IBE Reality - match template PDF (vertical lanyard card).
 * Variants:
 *   - permanent → header "STAFF"
 *   - intern_*   → header "Peserta / MAGANGHUB" with Kemnaker co-branding
 *
 * Render at 600×1000 logical pixels (3:5 aspect); for high-res print export,
 * the export helper renders at 3× pixel ratio (1800×3000 ≈ 300 DPI on 6×10cm).
 */
const IdCardPreview = forwardRef<HTMLDivElement, Props>(function IdCardPreview(
  { name, division, employmentStatus, photoUrl },
  ref,
) {
  const isIntern = employmentStatus !== 'permanent' && employmentStatus !== null
  const TEAL_DARK = '#054955'
  const TEAL_MID = '#0f6677'

  return (
    <div
      ref={ref}
      style={{
        width: 600,
        height: 1000,
        position: 'relative',
        background: TEAL_DARK,
        fontFamily: '"Inter", -apple-system, "Segoe UI", sans-serif',
        color: '#fff',
        overflow: 'hidden',
        boxShadow: '0 12px 40px rgba(0,0,0,.18)',
        userSelect: 'none',
      }}
    >
      {/* ── Header (logo + role) ───────────────────────────────────── */}
      <div style={{
        position: 'absolute', top: 0, left: 0, right: 0,
        padding: '36px 32px 22px', textAlign: 'center',
        background: `linear-gradient(180deg, ${TEAL_DARK} 0%, ${TEAL_MID} 100%)`,
      }}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', gap: isIntern ? 28 : 12 }}>
          <IbeLogo />
          {isIntern && <KemnakerLogo />}
        </div>

        <div style={{ marginTop: 14 }}>
          {isIntern && (
            <div style={{ fontSize: 22, fontWeight: 500, letterSpacing: '.02em', marginBottom: 0 }}>Peserta</div>
          )}
          <div style={{
            fontSize: isIntern ? 46 : 56,
            fontWeight: 900,
            letterSpacing: '.02em',
            lineHeight: 1.05,
          }}>
            {isIntern ? 'MAGANGHUB' : 'STAFF'}
          </div>
        </div>
      </div>

      {/* ── Photo area dengan chevron background ──────────────────── */}
      <div style={{
        position: 'absolute', top: 270, left: 0, right: 0, height: 580,
        background: '#e8eaec',
      }}>
        {/* Chevron teal kiri */}
        <div style={{
          position: 'absolute', top: 0, left: 0, width: '50%', height: '100%',
          background: TEAL_MID,
          clipPath: 'polygon(0 0, 100% 0, 100% 18%, 50% 28%, 0 18%)',
        }} />
        {/* Chevron teal kanan (slightly darker untuk kedalaman) */}
        <div style={{
          position: 'absolute', top: 0, right: 0, width: '50%', height: '100%',
          background: TEAL_DARK,
          clipPath: 'polygon(0 0, 100% 0, 100% 18%, 50% 28%, 0 18%)',
        }} />

        {/* Foto wajah karyawan */}
        <div style={{
          position: 'absolute', top: 30, left: '50%', transform: 'translateX(-50%)',
          width: 380, height: 540, display: 'flex', alignItems: 'flex-end', justifyContent: 'center',
        }}>
          {photoUrl ? (
            <img
              src={photoUrl}
              alt={name}
              crossOrigin="anonymous"
              style={{
                maxWidth: '100%', maxHeight: '100%', objectFit: 'contain', objectPosition: 'bottom',
              }}
            />
          ) : (
            <div style={{
              width: 220, height: 220, borderRadius: '50%', background: '#cbd5e1',
              display: 'grid', placeItems: 'center', color: '#475569', fontSize: 60, fontWeight: 700,
              marginBottom: 60,
            }}>
              {(name || '?').charAt(0)}
            </div>
          )}
        </div>
      </div>

      {/* ── Bottom info: divisi + nama ─────────────────────────────── */}
      <div style={{
        position: 'absolute', bottom: 0, left: 0, right: 0, height: 230,
        background: TEAL_DARK, padding: '24px 30px 0',
        display: 'flex', flexDirection: 'column', justifyContent: 'flex-start',
      }}>
        <div style={{
          fontSize: 26, fontWeight: 800, letterSpacing: '.04em', textAlign: 'center',
        }}>
          DIVISI {(division ?? '-').toUpperCase()}
        </div>
        <div style={{
          height: 1, background: 'rgba(255,255,255,.35)',
          margin: '10px 30px 14px',
        }} />
        <div style={{
          fontSize: name.length > 18 ? 30 : 36,
          fontWeight: 900,
          letterSpacing: '.01em',
          textAlign: 'center',
          lineHeight: 1.1,
        }}>
          {name.toUpperCase()}
        </div>
      </div>

      {/* ── Pojok dekoratif kuning/putih ───────────────────────────── */}
      <CornerDecoration side="left" />
      <CornerDecoration side="right" />
    </div>
  )
})

export default IdCardPreview

function IbeLogo() {
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
      {/* Tear-drop / water-droplet logo */}
      <svg width="62" height="68" viewBox="0 0 62 68" fill="none">
        <path
          d="M31 4 C 31 4, 52 30, 52 46 C 52 58, 42 64, 31 64 C 20 64, 10 58, 10 46 C 10 30, 31 4, 31 4 Z"
          fill="#fff"
        />
        <circle cx="36" cy="40" r="8" fill="#0f6677" />
      </svg>
      <div style={{ textAlign: 'left', lineHeight: 1.05 }}>
        <div style={{ fontSize: 22, fontWeight: 800, letterSpacing: '.04em' }}>IBE</div>
        <div style={{ fontSize: 20, fontWeight: 800, letterSpacing: '.04em' }}>REALITY</div>
        <div style={{ fontSize: 8, fontWeight: 600, letterSpacing: '.12em', marginTop: 2, opacity: .85 }}>
          PT INTEGRASI BISNIS EKSEKUTIF
        </div>
      </div>
    </div>
  )
}

function KemnakerLogo() {
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
      <svg width="48" height="48" viewBox="0 0 48 48" fill="none">
        {/* Stylized "people" symbol - Kemnaker uses a unity icon */}
        <circle cx="24" cy="14" r="4" fill="#fff" />
        <circle cx="12" cy="22" r="4" fill="#fff" />
        <circle cx="36" cy="22" r="4" fill="#fff" />
        <circle cx="18" cy="34" r="4" fill="#fff" />
        <circle cx="30" cy="34" r="4" fill="#fff" />
        <path d="M24 18 L 16 26 L 14 26 M24 18 L 32 26 L 34 26 M16 28 L 20 32 M32 28 L 28 32"
              stroke="#fff" strokeWidth="2" strokeLinecap="round" fill="none" opacity=".6" />
      </svg>
      <div style={{ fontSize: 9, fontWeight: 700, letterSpacing: '.04em', lineHeight: 1.15 }}>
        KEMENTERIAN<br />KETENAGAKERJAAN<br />REPUBLIK<br />INDONESIA
      </div>
    </div>
  )
}

function CornerDecoration({ side }: { side: 'left' | 'right' }) {
  const flip = side === 'right'
  return (
    <div style={{
      position: 'absolute', bottom: 0,
      [side]: 0,
      width: 140, height: 80, pointerEvents: 'none',
      transform: flip ? 'scaleX(-1)' : 'none',
    } as React.CSSProperties}>
      <svg viewBox="0 0 140 80" width="100%" height="100%" preserveAspectRatio="none">
        <polygon points="0,80 80,80 0,30" fill="#f5b942" />
        <polygon points="0,80 50,80 0,55" fill="#ffd87a" />
        <polygon points="0,80 100,80 60,30 30,30" fill="#fff" fillOpacity=".15" />
      </svg>
    </div>
  )
}
