import { useState, useEffect, useCallback } from 'react'

interface InboxRow {
  id: string
  subject: string
  body: string
  priority: 'normal' | 'important' | 'urgent'
  sent_at: string | null
  read_at: string | null
  sender: { name: string; group: string } | null
}

interface InboxResponse {
  rows: InboxRow[]
  unread_count: number
}

const PRIORITY_COLORS: Record<InboxRow['priority'], { bg: string; fg: string; label: string }> = {
  normal:    { bg: '#dbeafe', fg: '#1e40af', label: 'Pengumuman' },
  important: { bg: '#fef3c7', fg: '#92400e', label: 'Penting' },
  urgent:    { bg: '#fee2e2', fg: '#991b1b', label: '🚨 Urgent' },
}

export default function BroadcastInbox() {
  const [data, setData] = useState<InboxResponse | null>(null)
  const [open, setOpen] = useState(false)
  const apiBase = import.meta.env.VITE_API_BASE_URL ?? ''

  const fetchInbox = useCallback(async () => {
    const token = localStorage.getItem('absen_token')
    if (!token) return
    try {
      const res = await fetch(`${apiBase}/api/v1/me/broadcasts`, {
        headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
      })
      if (!res.ok) return
      const j = await res.json()
      setData(j?.data ?? null)
    } catch {
      // best-effort, ignore
    }
  }, [apiBase])

  useEffect(() => {
    fetchInbox()
    const id = setInterval(fetchInbox, 60_000)
    return () => clearInterval(id)
  }, [fetchInbox])

  async function markRead(id: string) {
    const token = localStorage.getItem('absen_token')
    if (!token) return
    await fetch(`${apiBase}/api/v1/me/broadcasts/${id}/read`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    })
    fetchInbox()
  }

  async function markAllRead() {
    const token = localStorage.getItem('absen_token')
    if (!token) return
    await fetch(`${apiBase}/api/v1/me/broadcasts/read-all`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    })
    fetchInbox()
  }

  if (!data) return null
  const unread = data.unread_count
  const topUrgent = data.rows.find((r) => !r.read_at && r.priority === 'urgent')

  return (
    <>
      {/* Banner urgent - selalu tampil sampai dibaca */}
      {topUrgent && !open && (
        <div
          onClick={() => setOpen(true)}
          style={{
            margin: '12px 16px 0',
            padding: '12px 14px',
            background: 'linear-gradient(135deg, #dc2626, #991b1b)',
            color: '#fff',
            borderRadius: 12,
            display: 'flex',
            alignItems: 'center',
            gap: 12,
            cursor: 'pointer',
            boxShadow: '0 8px 24px rgba(220, 38, 38, .3)',
          }}
        >
          <div style={{ fontSize: 26 }}>🚨</div>
          <div style={{ flex: 1, minWidth: 0 }}>
            <div style={{ fontWeight: 700, fontSize: 13 }}>{topUrgent.subject}</div>
            <div style={{ fontSize: 11, opacity: 0.9 }}>
              {topUrgent.sender?.name} · klik untuk baca
            </div>
          </div>
          <div style={{ fontSize: 11, fontWeight: 700, opacity: 0.85 }}>BACA →</div>
        </div>
      )}

      {/* Floating badge button - kalau ada unread non-urgent */}
      {unread > 0 && !topUrgent && !open && (
        <button
          type="button"
          onClick={() => setOpen(true)}
          style={{
            position: 'fixed',
            top: 70,
            right: 16,
            zIndex: 1100,
            padding: '8px 14px',
            background: '#1a7a8c',
            color: '#fff',
            border: 'none',
            borderRadius: 999,
            fontSize: 12,
            fontWeight: 700,
            cursor: 'pointer',
            display: 'flex',
            alignItems: 'center',
            gap: 6,
            boxShadow: '0 4px 14px rgba(26,122,140,.4)',
          }}
        >
          📣 {unread} Pengumuman Baru
        </button>
      )}

      {/* Drawer inbox */}
      {open && (
        <div
          onClick={() => setOpen(false)}
          style={{
            position: 'fixed',
            inset: 0,
            zIndex: 1600,
            background: 'rgba(15,23,42,.5)',
            backdropFilter: 'blur(3px)',
            display: 'flex',
            alignItems: 'flex-end',
          }}
        >
          <div
            onClick={(e) => e.stopPropagation()}
            style={{
              background: '#fff',
              width: '100%',
              maxHeight: '85vh',
              overflowY: 'auto',
              borderRadius: '20px 20px 0 0',
              padding: '16px 0',
              boxShadow: '0 -10px 40px rgba(0,0,0,.2)',
            }}
          >
            <div style={{ width: 40, height: 4, background: '#cbd5e1', borderRadius: 2, margin: '0 auto 12px' }} />

            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '0 18px 12px', borderBottom: '1px solid #e2e8f0' }}>
              <div>
                <div style={{ fontWeight: 800, fontSize: 16, color: '#0f172a' }}>Pengumuman</div>
                <div style={{ fontSize: 11, color: '#64748b' }}>
                  {unread > 0 ? `${unread} belum dibaca` : 'Semua sudah dibaca'}
                </div>
              </div>
              {unread > 0 && (
                <button
                  type="button"
                  onClick={() => markAllRead()}
                  style={{
                    padding: '6px 12px',
                    background: '#f1f5f9',
                    color: '#334155',
                    border: 'none',
                    borderRadius: 8,
                    fontSize: 12,
                    fontWeight: 600,
                    cursor: 'pointer',
                  }}
                >
                  Tandai semua
                </button>
              )}
            </div>

            <div style={{ padding: '12px 16px' }}>
              {data.rows.length === 0 && (
                <div style={{ textAlign: 'center', padding: '32px 0', color: '#94a3b8', fontSize: 13 }}>
                  Belum ada pengumuman.
                </div>
              )}
              {data.rows.map((r) => {
                const cfg = PRIORITY_COLORS[r.priority]
                const isUnread = !r.read_at
                return (
                  <div
                    key={r.id}
                    onClick={() => isUnread && markRead(r.id)}
                    style={{
                      padding: '12px 14px',
                      marginBottom: 8,
                      background: isUnread ? '#f8fafc' : '#fff',
                      border: '1px solid ' + (isUnread ? '#cbd5e1' : '#e2e8f0'),
                      borderLeft: '4px solid ' + cfg.fg,
                      borderRadius: 10,
                      cursor: isUnread ? 'pointer' : 'default',
                    }}
                  >
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 8, marginBottom: 6 }}>
                      <div style={{ flex: 1, minWidth: 0 }}>
                        <div style={{
                          fontWeight: isUnread ? 800 : 600,
                          fontSize: 13,
                          color: '#0f172a',
                        }}>
                          {r.subject}
                        </div>
                        <div style={{ fontSize: 10.5, color: '#64748b', marginTop: 2 }}>
                          {r.sender?.name ?? 'Sistem'} · {r.sent_at ? new Date(r.sent_at).toLocaleString('id-ID', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : ''}
                        </div>
                      </div>
                      <span style={{
                        fontSize: 10,
                        fontWeight: 700,
                        padding: '3px 8px',
                        background: cfg.bg,
                        color: cfg.fg,
                        borderRadius: 999,
                        whiteSpace: 'nowrap',
                      }}>{cfg.label}</span>
                    </div>
                    <div style={{ fontSize: 12.5, color: '#475569', whiteSpace: 'pre-wrap', lineHeight: 1.5 }}>
                      {r.body}
                    </div>
                  </div>
                )
              })}
            </div>

            <div style={{ padding: '0 16px 8px' }}>
              <button
                type="button"
                onClick={() => setOpen(false)}
                style={{
                  width: '100%',
                  padding: '12px',
                  background: '#f1f5f9',
                  color: '#334155',
                  border: 'none',
                  borderRadius: 12,
                  fontSize: 14,
                  fontWeight: 600,
                  cursor: 'pointer',
                }}
              >
                Tutup
              </button>
            </div>
          </div>
        </div>
      )}
    </>
  )
}
