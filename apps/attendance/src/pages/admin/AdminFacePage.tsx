import { usePageTitle } from '@/hooks/usePageTitle'
import { useRef, useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { adminListFaceApprovals, adminApproveFace, adminRejectFace } from '@/api/admin'
import type { FacePendingUser } from '@/api/admin'
import AdminPageHero from '@/components/admin/AdminPageHero'
import AdminTableCard from '@/components/admin/AdminTableCard'
import EmptyState from '@/components/EmptyState'
import { enrollmentWarnings } from '@/utils/faceCheck'

/**
 * URL foto bertanda tangan dibuat ulang tiap polling. Simpan URL pertama per
 * pendaftaran (id + submitted_at) supaya foto tidak diunduh ulang & berkedip;
 * diperbarui tiap 60 menit sebelum tanda tangannya kedaluwarsa.
 */
function useStablePhotoUrl() {
  const cache = useRef(new Map<string, { url: string; at: number }>())
  return (key: string, url: string | null | undefined) => {
    if (!url) return null
    const hit = cache.current.get(key)
    if (hit && Date.now() - hit.at < 60 * 60_000) return hit.url
    cache.current.set(key, { url, at: Date.now() })
    return url
  }
}

export default function AdminFacePage() {
  usePageTitle('Approval Wajah')
  const qc = useQueryClient()
  const [actionMsg, setActionMsg] = useState('')
  const [lastAction, setLastAction] = useState<{name: string, type: 'approve'|'reject'} | null>(null)
  const stableUrl = useStablePhotoUrl()

  const { data: pending = [], isLoading, error } = useQuery({
    queryKey: ['admin', 'face-approvals'],
    queryFn: adminListFaceApprovals,
    refetchInterval: 3_000,
    refetchIntervalInBackground: false,
  })

  const approve = useMutation({
    mutationFn: (id: string) => adminApproveFace(id),
    onSuccess: (_, id) => {
      const u = pending.find(p => p.id === id)
      setLastAction({ name: u?.name ?? '', type: 'approve' })
      setActionMsg(`Wajah ${u?.name ?? ''} berhasil disetujui ✅`)
      qc.invalidateQueries({ queryKey: ['admin', 'face-approvals'] })
      if (typeof BroadcastChannel !== 'undefined') {
        const bc = new BroadcastChannel('face_approval')
        bc.postMessage({ type: 'approved', userId: id })
        bc.close()
      }
    },
  })

  const reject = useMutation({
    mutationFn: (id: string) => adminRejectFace(id),
    onSuccess: (_, id) => {
      const u = pending.find(p => p.id === id)
      setLastAction({ name: u?.name ?? '', type: 'reject' })
      setActionMsg(`Pendaftaran wajah ${u?.name ?? ''} ditolak.`)
      qc.invalidateQueries({ queryKey: ['admin', 'face-approvals'] })
    },
  })

  const handleApprove = (u: FacePendingUser) => {
    if (!confirm(`Setujui wajah ${u.name}?`)) return
    setActionMsg('')
    setLastAction(null)
    approve.mutate(u.id)
  }

  const handleReject = (u: FacePendingUser) => {
    if (!confirm(`Tolak pendaftaran wajah ${u.name}?`)) return
    setActionMsg('')
    setLastAction(null)
    reject.mutate(u.id)
  }

  return (
    <div className="admin-page-shell">
      <AdminPageHero
        eyebrow="Workforce Workspace"
        title="Approval Wajah"
        subtitle="Pastikan foto yang didaftarkan memang wajah karyawan tersebut - bukan foto dari layar atau orang lain - sebelum dipakai untuk absensi."
        variant="workforce"
        action={(
          <div className="d-flex align-items-center gap-2">
            <span className="badge" style={{ background: 'var(--primary)', fontSize: 13 }}>
              {pending.length} Pending
            </span>
            <span style={{ fontSize: 11, color: '#6c757d', display: 'flex', alignItems: 'center', gap: 4 }}>
              <span style={{
                width: 7, height: 7, borderRadius: '50%', background: '#22c55e',
                display: 'inline-block', animation: 'pulse 2s infinite',
              }} />
              Live
            </span>
          </div>
        )}
      />

      {actionMsg && lastAction?.type === 'approve' && (
        <div className="card mb-3 border-0" style={{ background: 'linear-gradient(135deg,#f0fdf4,#dcfce7)', borderRadius: 14 }}>
          <div className="card-body d-flex align-items-center gap-3 py-3">
            <div style={{ width: 44, height: 44, borderRadius: '50%', background: '#22c55e', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 20, flexShrink: 0 }}>✅</div>
            <div style={{ flex: 1 }}>
              <div style={{ fontWeight: 700, fontSize: 14, color: '#166534' }}>{actionMsg}</div>
              <div style={{ fontSize: 12, color: '#4ade80', marginTop: 2 }}>Karyawan dapat langsung melakukan absensi sekarang.</div>
            </div>
          </div>
        </div>
      )}
      {actionMsg && lastAction?.type === 'reject' && (
        <div className="alert alert-warning py-2 px-3 mb-3" style={{ fontSize: 13 }}>{actionMsg}</div>
      )}

      {isLoading && <div className="text-center py-5 text-muted">Memuat data...</div>}
      {error && <div className="alert alert-danger py-2 px-3" style={{ fontSize: 13 }}>Gagal memuat data.</div>}

      <AdminTableCard
        title="Daftar Pending Face Approval"
        subtitle="Persetujuan wajah baru dan pembaruan wajah aktif karyawan."
      >
        {!isLoading && !error && pending.length === 0 ? (
          <EmptyState
            type="face"
            title="Tidak ada pendaftaran wajah yang menunggu persetujuan"
            subtitle="Semua request face approval sudah selesai diproses."
          />
        ) : (
          <div className="row g-3">
            {pending.map((u) => {
              const fotoBaru = stableUrl(`${u.id}:${u.submitted_at}:pending`, u.enrollment_photo_url)
              const fotoLama = stableUrl(`${u.id}:active`, u.active_photo_url)
              const warnings = enrollmentWarnings(u.enrollment_quality)
              return (
              <div key={u.id} className="col-lg-6">
                <div className="card h-100 border-0 shadow-sm">
                  <div className="card-body">
                    <div className="d-flex align-items-center gap-3">
                      {u.avatar ? (
                        <img src={u.avatar} alt={u.name} style={{ width: 48, height: 48, borderRadius: '50%', objectFit: 'cover', flexShrink: 0 }} />
                      ) : (
                        <div style={{ width: 48, height: 48, borderRadius: '50%', flexShrink: 0, background: 'var(--primary)', color: 'white', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 20, fontWeight: 700 }}>
                          {u.name.charAt(0).toUpperCase()}
                        </div>
                      )}
                      <div style={{ flex: 1, minWidth: 0 }}>
                        <div style={{ fontWeight: 600, fontSize: 15 }}>{u.name}</div>
                        <div className="text-muted" style={{ fontSize: 12 }}>{u.nip ? `NIP: ${u.nip} · ` : ''}{u.email}</div>
                        <span className="badge mt-1" style={{ background: u.has_active ? '#fff3cd' : '#e8f5e9', color: u.has_active ? '#856404' : '#2e7d32', fontSize: 11 }}>
                          {u.has_active ? '🔄 Perbarui wajah' : '🆕 Daftar baru'}
                        </span>
                      </div>
                    </div>
                    {fotoBaru ? (
                      <div className="d-flex align-items-end gap-2 mt-3">
                        <a href={fotoBaru} target="_blank" rel="noreferrer" title="Foto pendaftaran baru - klik untuk memperbesar">
                          <img src={fotoBaru} alt={`Wajah didaftarkan ${u.name}`} style={{ width: 120, height: 120, objectFit: 'cover', borderRadius: 10 }} />
                        </a>
                        {fotoLama && (
                          <a href={fotoLama} target="_blank" rel="noreferrer" title="Foto acuan yang sedang aktif">
                            <img src={fotoLama} alt={`Wajah aktif ${u.name}`} style={{ width: 64, height: 64, objectFit: 'cover', borderRadius: 8, opacity: 0.8 }} />
                          </a>
                        )}
                        <small className="text-muted ms-1">{fotoLama ? 'Baru · Aktif' : 'Foto didaftarkan'}</small>
                      </div>
                    ) : (
                      <div className="text-muted mt-3" style={{ fontSize: 12 }}>
                        Foto tidak tersedia (daftar sebelum 19 Okt 2026). Minta karyawan daftar ulang bila ragu.
                      </div>
                    )}
                    {warnings.map((w) => (
                      <div key={w} className="text-danger mt-1" style={{ fontSize: 12 }}>⚠ {w}</div>
                    ))}
                    <div className="d-flex gap-2 mt-3">
                      <button className="btn btn-success flex-fill py-2" style={{ fontWeight: 600, fontSize: 14 }} onClick={() => handleApprove(u)} disabled={approve.isPending || reject.isPending}>✓ Setujui</button>
                      <button className="btn btn-outline-danger flex-fill py-2" style={{ fontSize: 14 }} onClick={() => handleReject(u)} disabled={approve.isPending || reject.isPending}>✕ Tolak</button>
                    </div>
                  </div>
                </div>
              </div>
              )
            })}
          </div>
        )}
      </AdminTableCard>
    </div>
  )
}
