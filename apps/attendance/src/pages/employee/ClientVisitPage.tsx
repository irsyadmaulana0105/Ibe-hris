import { usePageTitle } from '@/hooks/usePageTitle'
import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  listClientVisits,
  startClientVisit,
  endClientVisit,
  type ClientVisit,
} from '@/api/clientVisit'
import Pagination from '@/components/Pagination'
import Modal from '@/components/Modal'
import { getValidationErrors } from '@/utils/apiError'
import { releaseFeatureFlags } from '@/lib/releaseFeatureFlags'

const todayIso = () => new Date().toISOString().slice(0, 10)

function VisitStatusBadge({ status }: { status: string }) {
  const map: Record<string, { label: string; bg: string; color: string }> = {
    ongoing:     { label: 'Berlangsung', bg: '#fff3cd', color: '#856404' },
    completed:   { label: 'Selesai',     bg: '#d1e7dd', color: '#0a3622' },
  }
  const s = map[status] ?? { label: status, bg: '#e9ecef', color: '#495057' }
  return (
    <span className="badge" style={{ background: s.bg, color: s.color, fontSize: 11, fontWeight: 600 }}>
      {s.label}
    </span>
  )
}

export default function ClientVisitPage() {
  usePageTitle('Kunjungan Klien')
  const qc = useQueryClient()
  const [page, setPage] = useState(1)

  // Visit-In form
  const [showIn, setShowIn] = useState(false)
  const [formIn, setFormIn] = useState({ date: todayIso(), notes_in: '' })
  const [errIn, setErrIn] = useState<Record<string, string[]>>({})

  // Visit-Out modal
  const [outVisit, setOutVisit] = useState<ClientVisit | null>(null)
  const [formOut, setFormOut] = useState({ notes_out: '' })
  const [errOut, setErrOut] = useState<Record<string, string[]>>({})

  const { data, isLoading } = useQuery({
    queryKey: ['client-visits', page],
    queryFn: () => listClientVisits(page),
  })

  const startMutation = useMutation({
    mutationFn: () => startClientVisit(formIn),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['client-visits'] })
      setShowIn(false)
      setFormIn({ date: todayIso(), notes_in: '' })
      setErrIn({})
    },
    onError: (err: unknown) => setErrIn(getValidationErrors(err)),
  })

  const endMutation = useMutation({
    mutationFn: () => endClientVisit(outVisit!.id, formOut),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['client-visits'] })
      setOutVisit(null)
      setFormOut({ notes_out: '' })
      setErrOut({})
    },
    onError: (err: unknown) => setErrOut(getValidationErrors(err)),
  })

  const formatTime = (iso: string | null) => iso ? new Date(iso).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }) : '-'

  return (
    <>
      <div className="d-flex align-items-center justify-content-between mb-4">
        <h5 className="mb-0" style={{ fontWeight: 600 }}>Kunjungan Klien</h5>
        <button className="btn btn-primary btn-sm" onClick={() => setShowIn(true)}>
          + Mulai Kunjungan
        </button>
      </div>

      <div className="card">
        <div className="card-body p-0">
          {isLoading ? (
            <div className="d-flex justify-content-center py-5">
              <div className="spinner-border text-primary spinner-border-sm" role="status" />
            </div>
          ) : (
            <>
              <div className="d-none d-md-block table-responsive">
                <table className="table table-hover mb-0">
                  <thead>
                    <tr>
                      <th>Tanggal</th>
                      <th>Visit In</th>
                      <th>Visit Out</th>
                      <th>Durasi</th>
                      <th>Catatan</th>
                      <th>Status</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    {data?.data.length === 0 && (
                      <tr>
                        <td colSpan={7} className="text-center text-muted py-4">
                          Belum ada kunjungan klien.
                        </td>
                      </tr>
                    )}
                    {data?.data.map((v) => (
                      <tr key={v.id}>
                        <td>{v.date}</td>
                        <td className="text-muted">{formatTime(v.visit_in)}</td>
                        <td className="text-muted">{formatTime(v.visit_out)}</td>
                        <td>{v.duration_minutes != null ? `${v.duration_minutes} m` : '-'}</td>
                        <td style={{ maxWidth: 240, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                          {v.notes_in}
                          {v.notes_out && <small className="text-muted d-block">↪ {v.notes_out}</small>}
                        </td>
                        <td><VisitStatusBadge status={v.status} /></td>
                        <td className="text-end">
                          {!v.visit_out && (
                            <button
                              onClick={() => setOutVisit(v)}
                              className="btn btn-link btn-sm p-0"
                            >
                              Selesaikan
                            </button>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              <div className="d-block d-md-none">
                {data?.data.length === 0 && (
                  <div className="text-center text-muted py-4 px-3" style={{ fontSize: 13 }}>
                    Belum ada kunjungan klien.
                  </div>
                )}
                {data?.data.map((v) => (
                  <div key={v.id} className="border-bottom px-3 py-3">
                    <div className="d-flex justify-content-between align-items-start mb-2 gap-2">
                      <div style={{ minWidth: 0, flex: 1 }}>
                        <p className="mb-0 fw-semibold" style={{ fontSize: 14 }}>{v.date}</p>
                        <small className="text-muted">
                          In {formatTime(v.visit_in)}{v.visit_out ? ` · Out ${formatTime(v.visit_out)}` : ''}
                        </small>
                      </div>
                      <VisitStatusBadge status={v.status} />
                    </div>
                    {v.notes_in && (
                      <p className="text-muted mb-1" style={{ fontSize: 12.5, lineHeight: 1.45 }}>{v.notes_in}</p>
                    )}
                    {v.notes_out && (
                      <p className="text-muted mb-2" style={{ fontSize: 12.5, lineHeight: 1.45 }}>↪ {v.notes_out}</p>
                    )}
                    {!v.visit_out && (
                      <div className="d-flex justify-content-end">
                        <button
                          onClick={() => setOutVisit(v)}
                          className="btn btn-link btn-sm p-0"
                          style={{ fontSize: 12 }}
                        >
                          Selesaikan
                        </button>
                      </div>
                    )}
                  </div>
                ))}
              </div>

              {data?.meta && (
                <div className="px-3 pb-3 pt-2">
                  <Pagination meta={data.meta} onPageChange={setPage} />
                </div>
              )}
            </>
          )}
        </div>
      </div>

      {showIn && (
        <Modal title="Mulai Kunjungan Klien" onClose={() => { setShowIn(false); setErrIn({}) }}>
          <form onSubmit={(e) => { e.preventDefault(); startMutation.mutate() }}>
            <div className="mb-3">
              <label className="form-label">Tanggal</label>
              <input
                type="date"
                className={`form-control ${errIn.date ? 'is-invalid' : ''}`}
                value={formIn.date}
                onChange={(e) => setFormIn({ ...formIn, date: e.target.value })}
                required
              />
              {errIn.date && <div className="invalid-feedback">{errIn.date[0]}</div>}
            </div>
            <div className="mb-3">
              <label className="form-label">Catatan Visit-In</label>
              <textarea
                className={`form-control ${errIn.notes_in ? 'is-invalid' : ''}`}
                value={formIn.notes_in}
                onChange={(e) => setFormIn({ ...formIn, notes_in: e.target.value })}
                placeholder="Nama klien / lokasi / tujuan"
                rows={3}
                required
              />
              {errIn.notes_in && <div className="invalid-feedback">{errIn.notes_in[0]}</div>}
            </div>
            {!releaseFeatureFlags.clientVisitPhotoUpload && (
              <div className="alert alert-light border mb-4" role="status">
                Unggah foto kunjungan sementara tidak tersedia selama migrasi penyimpanan privat.
              </div>
            )}
            <div className="d-flex justify-content-end gap-2">
              <button type="button" className="btn btn-light" onClick={() => setShowIn(false)}>Batal</button>
              <button type="submit" className="btn btn-primary" disabled={startMutation.isPending}>
                {startMutation.isPending
                  ? <><span className="spinner-border spinner-border-sm me-1" />Mulai...</>
                  : 'Mulai Kunjungan'}
              </button>
            </div>
          </form>
        </Modal>
      )}

      {outVisit && (
        <Modal title={`Selesaikan kunjungan ${outVisit.date}`} onClose={() => { setOutVisit(null); setErrOut({}) }}>
          <form onSubmit={(e) => { e.preventDefault(); endMutation.mutate() }}>
            <div className="mb-3">
              <label className="form-label">Catatan Visit-Out</label>
              <textarea
                className={`form-control ${errOut.notes_out ? 'is-invalid' : ''}`}
                value={formOut.notes_out}
                onChange={(e) => setFormOut({ ...formOut, notes_out: e.target.value })}
                placeholder="Hasil pertemuan / kesimpulan kunjungan"
                rows={3}
                required
              />
              {errOut.notes_out && <div className="invalid-feedback">{errOut.notes_out[0]}</div>}
            </div>
            {!releaseFeatureFlags.clientVisitPhotoUpload && (
              <div className="alert alert-light border mb-4" role="status">
                Unggah foto kunjungan sementara tidak tersedia selama migrasi penyimpanan privat.
              </div>
            )}
            <div className="d-flex justify-content-end gap-2">
              <button type="button" className="btn btn-light" onClick={() => setOutVisit(null)}>Batal</button>
              <button type="submit" className="btn btn-success" disabled={endMutation.isPending}>
                {endMutation.isPending
                  ? <><span className="spinner-border spinner-border-sm me-1" />Menyimpan...</>
                  : 'Selesaikan Kunjungan'}
              </button>
            </div>
          </form>
        </Modal>
      )}
    </>
  )
}
