import { useEffect, useState } from 'react'
import client from '@/api/client'

interface QuoteData {
  quote: string
  author: string
  extra?: { en?: { quote: string; author: string } }
}

const STORAGE_KEY = 'ibe_quote_seen_date'

/**
 * Tampilkan motivational quote sekali per hari saat karyawan baru buka aplikasi.
 * State disimpan di localStorage (per browser) - kalau buka di device baru juga
 * keluar lagi sekali. Tidak intrusif: kalau API gagal silent skip, kalau user
 * close auto-mark seen.
 */
export default function WelcomeQuoteModal() {
  const [data, setData] = useState<QuoteData | null>(null)
  const [open, setOpen] = useState(false)

  useEffect(() => {
    const today = new Date().toISOString().slice(0, 10) // YYYY-MM-DD
    const seen = localStorage.getItem(STORAGE_KEY)
    if (seen === today) return
    let cancelled = false
    client.get<{ data: QuoteData }>('/quote')
      .then((r) => {
        if (cancelled) return
        if (r.data?.data?.quote) {
          setData(r.data.data)
          setOpen(true)
        }
      })
      .catch(() => { /* silent */ })
    return () => { cancelled = true }
  }, [])

  const dismiss = () => {
    const today = new Date().toISOString().slice(0, 10)
    localStorage.setItem(STORAGE_KEY, today)
    setOpen(false)
  }

  if (!open || !data) return null

  return (
    <div
      onClick={dismiss}
      style={{
        position: 'fixed',
        inset: 0,
        zIndex: 2000,
        background: 'rgba(15,23,42,0.55)',
        backdropFilter: 'blur(4px)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: 16,
        animation: 'wqm-fade .25s ease-out',
      }}
    >
      <div
        onClick={(e) => e.stopPropagation()}
        style={{
          background: 'linear-gradient(135deg, #1a7a8c, #0d5a6a)',
          color: '#fff',
          borderRadius: 20,
          padding: '28px 22px 20px',
          maxWidth: 420,
          width: '100%',
          boxShadow: '0 20px 60px rgba(0,0,0,0.35)',
          position: 'relative',
          animation: 'wqm-pop .3s cubic-bezier(.22,1,.36,1)',
        }}
      >
        <button
          type="button"
          onClick={dismiss}
          aria-label="Tutup"
          style={{
            position: 'absolute',
            top: 10,
            right: 10,
            width: 30,
            height: 30,
            borderRadius: 999,
            background: 'rgba(255,255,255,0.15)',
            border: 'none',
            color: '#fff',
            fontSize: 18,
            cursor: 'pointer',
            display: 'grid',
            placeItems: 'center',
          }}
        >×</button>

        <div style={{ fontSize: 36, marginBottom: 4 }}>💡</div>
        <div style={{ fontSize: 11, opacity: 0.7, letterSpacing: '.08em', textTransform: 'uppercase', marginBottom: 14, fontWeight: 700 }}>
          Quote of the Day
        </div>

        <blockquote style={{
          margin: 0,
          fontSize: 16,
          lineHeight: 1.55,
          fontWeight: 600,
          fontStyle: 'italic',
          marginBottom: 16,
        }}>
          "{data.quote}"
        </blockquote>

        <div style={{ fontSize: 13, opacity: 0.85, fontWeight: 600 }}>
          - {data.author}
        </div>

        {data.extra?.en && (
          <div style={{
            marginTop: 14,
            paddingTop: 14,
            borderTop: '1px solid rgba(255,255,255,0.18)',
            fontSize: 11,
            lineHeight: 1.4,
            opacity: 0.7,
          }}>
            <div style={{ fontStyle: 'italic' }}>"{data.extra.en.quote}"</div>
            <div style={{ marginTop: 4, fontSize: 10 }}>- {data.extra.en.author}</div>
          </div>
        )}

        <button
          type="button"
          onClick={dismiss}
          style={{
            marginTop: 20,
            width: '100%',
            padding: '12px',
            background: '#fff',
            color: '#1a7a8c',
            border: 'none',
            borderRadius: 12,
            fontWeight: 700,
            fontSize: 14,
            cursor: 'pointer',
            letterSpacing: '.02em',
          }}
        >
          Semangat! 🚀
        </button>
      </div>

      <style>{`
        @keyframes wqm-fade { from { opacity: 0 } to { opacity: 1 } }
        @keyframes wqm-pop { from { opacity: 0; transform: scale(.92) translateY(8px); } to { opacity: 1; transform: scale(1) translateY(0); } }
      `}</style>
    </div>
  )
}
