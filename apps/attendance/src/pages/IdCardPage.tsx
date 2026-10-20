import { useEffect, useRef, useState } from 'react'
import { useAuth } from '@/context/AuthContext'
import { usePageTitle } from '@/hooks/usePageTitle'
import IdCardPreview from '@/components/IdCardPreview'
import { toPng } from 'html-to-image'
import client from '@/api/client'
import { Link } from 'react-router-dom'

export default function IdCardPage() {
  usePageTitle('Kartu Identitas')
  const { user } = useAuth()
  const cardRef = useRef<HTMLDivElement>(null)
  const [downloading, setDownloading] = useState(false)
  const [err, setErr] = useState<string | null>(null)
  const [photoDataUrl, setPhotoDataUrl] = useState<string | null>(null)

  // Fetch photo sebagai base64 supaya html-to-image bisa export tanpa CORS taint.
  useEffect(() => {
    let cancelled = false
    client.get<{ data: { data_url: string | null } }>('/auth/me/avatar-data')
      .then((r) => { if (!cancelled) setPhotoDataUrl(r.data.data?.data_url ?? null) })
      .catch(() => {})
    return () => { cancelled = true }
  }, [user?.avatar])

  const download = async (scale: number, suffix: string) => {
    if (!cardRef.current) return
    setDownloading(true)
    setErr(null)
    try {
      const dataUrl = await toPng(cardRef.current, {
        cacheBust: true,
        pixelRatio: scale,
        backgroundColor: '#054955',
      })
      const link = document.createElement('a')
      link.download = `kartu-identitas-${(user?.name ?? 'karyawan').toLowerCase().replace(/\s+/g, '-')}-${suffix}.png`
      link.href = dataUrl
      link.click()
    } catch (e) {
      setErr('Gagal generate PNG. Coba lagi atau pakai screenshot.')
      console.error(e)
    } finally {
      setDownloading(false)
    }
  }

  if (!user) return null

  return (
    <div style={{ maxWidth: 720, margin: '0 auto', padding: '0 4px' }}>
      <div style={{ marginBottom: 18 }}>
        <h5 style={{ margin: 0, fontWeight: 700, fontSize: 18 }}>Kartu Identitas Saya</h5>
        <p style={{ margin: '4px 0 0', fontSize: 13, color: '#64748b' }}>
          Unduh PNG untuk simpan di galeri / cetak ke kartu fisik.
        </p>
      </div>

      <div style={{ display: 'flex', justifyContent: 'center', marginBottom: 18 }}>
        <div style={{ transform: 'scale(0.55)', transformOrigin: 'top center', marginBottom: -460 }}>
          <IdCardPreview
            ref={cardRef}
            name={user.name}
            division={user.division?.name ?? null}
            employmentStatus={user.employment_status ?? null}
            photoUrl={photoDataUrl ?? user.avatar ?? null}
          />
        </div>
      </div>

      {err && (
        <div style={{
          padding: 10, background: '#fef2f2', color: '#b91c1c',
          borderRadius: 8, fontSize: 12, marginBottom: 12,
        }}>
          {err}
        </div>
      )}

      <div className="d-grid gap-2">
        <button
          className="btn btn-primary"
          onClick={() => download(2, 'standard')}
          disabled={downloading}
        >
          {downloading ? 'Memproses...' : '⬇ Unduh PNG (standard)'}
        </button>
        <button
          className="btn btn-outline-primary"
          onClick={() => download(3, 'hires')}
          disabled={downloading}
        >
          ⬇ Unduh PNG resolusi tinggi (cocok untuk cetak)
        </button>
      </div>

      <p style={{ marginTop: 14, fontSize: 11, color: '#94a3b8', textAlign: 'center' }}>
        Data: <strong>{user.name}</strong> · Divisi: <strong>{user.division?.name ?? '-'}</strong> · Status: <strong>{user.employment_status ?? '-'}</strong>
        <br />
        Kalau divisi belum benar, hubungi HR untuk update profil.
      </p>

      {!photoDataUrl && (
        <div className="alert alert-warning mt-3" style={{ fontSize: 13 }}>
          📷 Belum ada foto profil. <Link to="/profile/edit" className="alert-link">Upload foto sekarang</Link> supaya kartu kamu lebih bagus.
        </div>
      )}
    </div>
  )
}
