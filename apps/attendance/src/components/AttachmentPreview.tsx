import { useEffect } from 'react'

interface Props {
  url: string | null
  title?: string
  onClose: () => void
}

export default function AttachmentPreview({ url, title, onClose }: Props) {
  useEffect(() => {
    if (!url) return
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', onKey)
    document.body.style.overflow = 'hidden'
    return () => {
      document.removeEventListener('keydown', onKey)
      document.body.style.overflow = ''
    }
  }, [url, onClose])

  if (!url) return null

  const isPdf = /\.pdf(\?|$)/i.test(url)

  return (
    <div
      role="dialog"
      aria-modal="true"
      onClick={(e) => { if (e.target === e.currentTarget) onClose() }}
      style={{
        position: 'fixed', inset: 0, zIndex: 9999,
        background: 'rgba(15,23,42,.85)', backdropFilter: 'blur(4px)',
        display: 'flex', flexDirection: 'column',
      }}
    >
      <div
        style={{
          display: 'flex', alignItems: 'center', justifyContent: 'space-between',
          padding: '12px 16px', color: '#fff', gap: 12,
        }}
      >
        <div style={{ minWidth: 0, fontWeight: 600, fontSize: 14, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
          {title ?? 'Bukti Lampiran'}
        </div>
        <div style={{ display: 'flex', gap: 8, flexShrink: 0 }}>
          <a
            href={url}
            target="_blank"
            rel="noreferrer"
            style={{
              padding: '6px 12px', background: 'rgba(255,255,255,.15)', color: '#fff',
              border: '1px solid rgba(255,255,255,.25)', borderRadius: 8,
              fontSize: 12, fontWeight: 600, textDecoration: 'none',
            }}
          >
            Buka di tab baru
          </a>
          <button
            type="button"
            onClick={onClose}
            aria-label="Tutup"
            style={{
              width: 36, height: 36, borderRadius: 8, border: 'none',
              background: 'rgba(255,255,255,.15)', color: '#fff', cursor: 'pointer',
              fontSize: 18, lineHeight: 1, fontWeight: 700,
            }}
          >
            ✕
          </button>
        </div>
      </div>

      <div
        style={{
          flex: 1, display: 'flex', alignItems: 'center', justifyContent: 'center',
          padding: 16, overflow: 'auto',
        }}
        onClick={(e) => { if (e.target === e.currentTarget) onClose() }}
      >
        {isPdf ? (
          <iframe
            src={url}
            title={title ?? 'Lampiran PDF'}
            style={{ width: '100%', height: '100%', maxWidth: 900, border: 'none', background: '#fff', borderRadius: 8 }}
          />
        ) : (
          <img
            src={url}
            alt={title ?? 'Lampiran'}
            style={{ maxWidth: '100%', maxHeight: '100%', objectFit: 'contain', borderRadius: 8 }}
          />
        )}
      </div>
    </div>
  )
}
