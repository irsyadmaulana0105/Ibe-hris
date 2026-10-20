import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { usePageTitle } from '@/hooks/usePageTitle'
import client from '@/api/client'

interface PendingContract {
  contract_id: number
  contract_number: string
  contract_type?: string
  status?: string
  issued_at: string
  end_date?: string
  placeholders: { name: string; address: string | null; nik: string | null }
  pdf_url: string
}

export default function ContractSignPage() {
  usePageTitle('Tanda Tangan Kontrak')
  const navigate = useNavigate()
  const [pending, setPending] = useState<PendingContract | null>(null)
  const [latest, setLatest] = useState<PendingContract | null>(null)
  const [pdfBlobUrl, setPdfBlobUrl] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)
  const [consent, setConsent] = useState(false)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState<string | null>(null)
  const [signed, setSigned] = useState<{ pdf_url: string } | null>(null)

  const canvasRef = useRef<HTMLCanvasElement>(null)
  const drawingRef = useRef(false)
  const lastPoint = useRef<{ x: number; y: number } | null>(null)
  const hasInk = useRef(false)

  useEffect(() => {
    client.get<{ data: { pending: PendingContract | null; latest: PendingContract | null } }>('/me/contract')
      .then(async (r) => {
        const p = r.data.data.pending
        const l = r.data.data.latest
        setPending(p)
        setLatest(l)

        // Prioritas PDF: pending > latest (untuk view/download archived)
        const targetPdfUrl = p?.pdf_url ?? l?.pdf_url
        if (targetPdfUrl) {
          try {
            const token = localStorage.getItem('absen_token')
            const resp = await fetch(targetPdfUrl, { headers: { Authorization: `Bearer ${token}` } })
            if (resp.ok) {
              const blob = await resp.blob()
              setPdfBlobUrl(URL.createObjectURL(blob))
            }
          } catch { /* fallback silent */ }
        }
      })
      .catch(() => setErr('Gagal load kontrak.'))
      .finally(() => setLoading(false))
  }, [])

  useEffect(() => {
    const c = canvasRef.current
    if (!c) return
    const ctx = c.getContext('2d')!
    ctx.lineCap = 'round'
    ctx.lineJoin = 'round'
    ctx.lineWidth = 2.5
    ctx.strokeStyle = '#0f172a'
    ctx.fillStyle = '#fff'
    ctx.fillRect(0, 0, c.width, c.height)
  }, [pending])

  const getPos = (e: React.PointerEvent<HTMLCanvasElement>) => {
    const rect = e.currentTarget.getBoundingClientRect()
    return {
      x: (e.clientX - rect.left) * (e.currentTarget.width / rect.width),
      y: (e.clientY - rect.top) * (e.currentTarget.height / rect.height),
    }
  }

  const start = (e: React.PointerEvent<HTMLCanvasElement>) => {
    e.preventDefault()
    drawingRef.current = true
    lastPoint.current = getPos(e)
  }
  const move = (e: React.PointerEvent<HTMLCanvasElement>) => {
    if (!drawingRef.current || !canvasRef.current) return
    const ctx = canvasRef.current.getContext('2d')!
    const p = getPos(e)
    if (lastPoint.current) {
      ctx.beginPath()
      ctx.moveTo(lastPoint.current.x, lastPoint.current.y)
      ctx.lineTo(p.x, p.y)
      ctx.stroke()
      hasInk.current = true
    }
    lastPoint.current = p
  }
  const end = () => { drawingRef.current = false; lastPoint.current = null }

  const clear = () => {
    const c = canvasRef.current
    if (!c) return
    const ctx = c.getContext('2d')!
    ctx.fillStyle = '#fff'
    ctx.fillRect(0, 0, c.width, c.height)
    hasInk.current = false
  }

  const submit = async () => {
    if (!pending || !canvasRef.current) return
    if (!hasInk.current) { setErr('Tanda tangan dulu di area kotak.'); return }
    if (!consent) { setErr('Centang persetujuan dulu.'); return }
    setErr(null)
    setBusy(true)
    try {
      const dataUrl = canvasRef.current.toDataURL('image/png')
      const { data } = await client.post<{ data: { pdf_url: string } }>(
        `/me/contract/${pending.contract_id}/sign`,
        { signature: dataUrl, consent: true },
      )
      setSigned(data.data)
    } catch (e: any) {
      setErr(e?.response?.data?.message ?? 'Gagal menyimpan tanda tangan.')
    } finally {
      setBusy(false)
    }
  }

  if (loading) return <div className="text-center text-muted py-5">Memuat kontrak...</div>

  if (!pending) {
    // Kalau tidak ada pending tapi ada latest → tampilkan preview + download
    if (latest) {
      const statusLabel: Record<string, string> = {
        signed: 'Sudah Ditandatangani',
        signed_karyawan: 'Menunggu Tanda Tangan Direktur',
        pending_direktur: 'Menunggu Tanda Tangan Direktur',
        signed_direktur: 'Menunggu E-Materai',
        pending_materai: 'Menunggu E-Materai oleh HR',
        archived: 'Arsip Final',
      }
      return (
        <div style={{ maxWidth: 720, margin: '0 auto' }}>
          <h5 style={{ fontWeight: 700 }}>Kontrak Kerja Anda</h5>
          <p style={{ fontSize: 13, color: '#64748b' }}>
            Nomor: <strong>{latest.contract_number}</strong> · Diterbitkan {latest.issued_at}
            {latest.end_date && <> · Berakhir {latest.end_date}</>}
          </p>
          <div className="alert alert-success" style={{ fontSize: 13 }}>
            Status: <strong>{statusLabel[latest.status ?? ''] ?? latest.status ?? '-'}</strong>
          </div>
          {pdfBlobUrl && (
            <div style={{ height: 600, border: '1px solid #e2e8f0', borderRadius: 10, overflow: 'hidden', marginBottom: 14 }}>
              <iframe
                src={`${pdfBlobUrl}#toolbar=1`}
                title="Kontrak Kerja"
                style={{ width: '100%', height: '100%', border: 'none' }}
              />
            </div>
          )}
          <div className="d-grid gap-2 mt-3">
            {pdfBlobUrl && (
              <a className="btn btn-primary" href={pdfBlobUrl} download={`kontrak-${latest.contract_number.replace(/\//g, '-')}.pdf`}>
                📥 Download PDF Kontrak
              </a>
            )}
            <button className="btn btn-outline-secondary" onClick={() => navigate('/dashboard')}>
              Kembali ke Beranda
            </button>
          </div>
        </div>
      )
    }
    return (
      <div className="text-center py-5">
        <div style={{ fontSize: 56 }}>📄</div>
        <h5 className="mt-2">Belum ada kontrak</h5>
        <p className="text-muted" style={{ fontSize: 13 }}>
          Kontrak akan terbit setelah HR menerbitkan dan mengirim untuk Anda tanda tangan.
        </p>
        <button className="btn btn-outline-primary" onClick={() => navigate('/dashboard')}>Kembali ke Beranda</button>
      </div>
    )
  }

  if (signed) {
    return (
      <div style={{ maxWidth: 540, margin: '0 auto', textAlign: 'center', padding: 20 }}>
        <div style={{
          width: 80, height: 80, borderRadius: '50%', margin: '20px auto 14px',
          background: '#dcfce7', color: '#15803d', display: 'grid', placeItems: 'center',
          fontSize: 42, fontWeight: 900,
        }}>✓</div>
        <h4>Kontrak Tertandatangani</h4>
        <p style={{ fontSize: 13, color: '#475569' }}>
          Terima kasih sudah menandatangani kontrak kerja. Salinan tersimpan di akun kamu dan
          juga diterima oleh HR.
        </p>
        <div className="d-grid gap-2 mt-4">
          <a className="btn btn-primary" href={signed.pdf_url} target="_blank" rel="noreferrer">
            📥 Lihat / Unduh PDF Kontrak
          </a>
          <button className="btn btn-outline-secondary" onClick={() => navigate('/dashboard')}>
            Kembali ke Beranda
          </button>
        </div>
      </div>
    )
  }

  return (
    <div style={{ maxWidth: 720, margin: '0 auto' }}>
      <h5 style={{ fontWeight: 700 }}>Tanda Tangan Kontrak Kerja</h5>
      <p style={{ fontSize: 13, color: '#64748b' }}>
        Nomor kontrak: <strong>{pending.contract_number}</strong> · Diterbitkan {pending.issued_at}
      </p>

      <div className="alert alert-info" style={{ fontSize: 13 }}>
        Silakan baca isi kontrak terlebih dahulu sebelum tanda tangan.
      </div>

      <div style={{ height: 600, border: '1px solid #e2e8f0', borderRadius: 10, overflow: 'hidden', marginBottom: 14 }}>
        <iframe
          src={`${pdfBlobUrl ?? pending.pdf_url}#toolbar=1`}
          title="Kontrak Kerja"
          style={{ width: '100%', height: '100%', border: 'none' }}
        />
      </div>

      <div className="card" style={{ borderRadius: 12 }}>
        <div className="card-body">
          <h6 style={{ fontWeight: 700 }}>Tanda Tangan</h6>
          <p style={{ fontSize: 12, color: '#64748b' }}>
            Tarik dengan jari (HP) atau mouse di kotak putih di bawah.
          </p>

          <div style={{
            border: '2px dashed #cbd5e1', borderRadius: 10, overflow: 'hidden',
            background: '#fff', touchAction: 'none',
          }}>
            <canvas
              ref={canvasRef}
              width={800}
              height={280}
              onPointerDown={start}
              onPointerMove={move}
              onPointerUp={end}
              onPointerLeave={end}
              style={{ width: '100%', height: 220, display: 'block', cursor: 'crosshair' }}
            />
          </div>

          <button className="btn btn-link btn-sm text-muted p-0 mt-1" type="button" onClick={clear}>
            ↺ Hapus & ulang
          </button>

          <div className="form-check mt-3">
            <input
              type="checkbox"
              className="form-check-input"
              id="consent"
              checked={consent}
              onChange={(e) => setConsent(e.target.checked)}
            />
            <label htmlFor="consent" className="form-check-label" style={{ fontSize: 12 }}>
              Saya telah membaca, memahami, dan menyetujui seluruh isi kontrak kerja ini.
            </label>
          </div>

          {err && (
            <div className="alert alert-danger mt-3" style={{ fontSize: 12 }}>{err}</div>
          )}

          <button
            className="btn btn-primary w-100 mt-3"
            disabled={busy}
            onClick={submit}
          >
            {busy ? 'Memproses...' : '✍️ Tanda Tangani Kontrak'}
          </button>
        </div>
      </div>
    </div>
  )
}
