import { usePageTitle } from '@/hooks/usePageTitle'
import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { listOvertimes, submitOvertime, cancelOvertime } from '@/api/overtime'
import StatusBadge from '@/components/StatusBadge'
import Pagination from '@/components/Pagination'
import Modal from '@/components/Modal'
import { getValidationErrors } from '@/utils/apiError'

export default function OvertimePage() {
  usePageTitle('Lembur')
  const qc = useQueryClient()
  const [page, setPage] = useState(1)
  const [showForm, setShowForm] = useState(false)
  const [form, setForm] = useState({ date: '', start_time: '', end_time: '', reason: '' })
  const [formErrors, setFormErrors] = useState<Record<string, string[]>>({})

  const { data, isLoading } = useQuery({
    queryKey: ['overtimes', page],
    queryFn: () => listOvertimes(page),
  })

  const submitMutation = useMutation({
    mutationFn: () => submitOvertime(form),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['overtimes'] })
      setShowForm(false)
      setForm({ date: '', start_time: '', end_time: '', reason: '' })
      setFormErrors({})
    },
    onError: (err: unknown) => {
      setFormErrors(getValidationErrors(err))
    },
  })

  const cancelMutation = useMutation({
    mutationFn: cancelOvertime,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['overtimes'] }),
  })

  return (
    <>
      <div className="d-flex align-items-center justify-content-between mb-4">
        <h5 className="mb-0" style={{ fontWeight: 600 }}>Lembur</h5>
        <button className="btn btn-primary btn-sm" onClick={() => setShowForm(true)}>
          + Ajukan Lembur
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
                      <th>Jam</th>
                      <th>Total</th>
                      <th>Alasan</th>
                      <th>Status</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    {data?.data.length === 0 && (
                      <tr>
                        <td colSpan={6} className="text-center text-muted py-4">
                          Belum ada pengajuan lembur.
                        </td>
                      </tr>
                    )}
                    {data?.data.map((ot) => (
                      <tr key={ot.id}>
                        <td>{ot.date}</td>
                        <td className="text-muted">{ot.start_time} – {ot.end_time}</td>
                        <td>{ot.total_hours} jam</td>
                        <td style={{ maxWidth: 200, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{ot.reason}</td>
                        <td><StatusBadge status={ot.status} /></td>
                        <td className="text-end">
                          {ot.status === 'pending' && (
                            <button
                              onClick={() => cancelMutation.mutate(ot.id)}
                              className="btn btn-link btn-sm text-danger p-0"
                            >
                              Batalkan
                            </button>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              {/* MOBILE CARD LIST */}
              <div className="d-block d-md-none">
                {data?.data.length === 0 && (
                  <div className="text-center text-muted py-4 px-3" style={{ fontSize: 13 }}>
                    Belum ada pengajuan lembur.
                  </div>
                )}
                {data?.data.map((ot) => (
                  <div key={ot.id} className="border-bottom px-3 py-3">
                    <div className="d-flex justify-content-between align-items-start mb-2 gap-2">
                      <div style={{ minWidth: 0, flex: 1 }}>
                        <p className="mb-0 fw-semibold" style={{ fontSize: 14 }}>{ot.date}</p>
                        <small className="text-muted">{ot.start_time} – {ot.end_time} ({ot.total_hours} jam)</small>
                      </div>
                      <StatusBadge status={ot.status} />
                    </div>
                    {ot.reason && (
                      <p className="text-muted mb-2" style={{ fontSize: 12.5, lineHeight: 1.45 }}>
                        {ot.reason}
                      </p>
                    )}
                    {ot.status === 'pending' && (
                      <div className="d-flex justify-content-end">
                        <button
                          onClick={() => cancelMutation.mutate(ot.id)}
                          className="btn btn-link btn-sm text-danger p-0"
                          style={{ fontSize: 12 }}
                        >
                          Batalkan
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

      {showForm && (
        <Modal title="Ajukan Lembur" onClose={() => { setShowForm(false); setFormErrors({}) }}>
          <form onSubmit={(e) => { e.preventDefault(); submitMutation.mutate() }}>
            <div className="mb-3">
              <label className="form-label">Tanggal</label>
              <input
                type="date"
                className={`form-control ${formErrors.date ? 'is-invalid' : ''}`}
                value={form.date}
                onChange={(e) => setForm({ ...form, date: e.target.value })}
                required
              />
              {formErrors.date && <div className="invalid-feedback">{formErrors.date[0]}</div>}
            </div>
            <div className="row g-3 mb-3">
              <div className="col-6">
                <label className="form-label">Jam Mulai</label>
                <input
                  type="time"
                  className={`form-control ${formErrors.start_time ? 'is-invalid' : ''}`}
                  value={form.start_time}
                  onChange={(e) => setForm({ ...form, start_time: e.target.value })}
                  required
                />
                {formErrors.start_time && <div className="invalid-feedback">{formErrors.start_time[0]}</div>}
              </div>
              <div className="col-6">
                <label className="form-label">Jam Selesai</label>
                <input
                  type="time"
                  className={`form-control ${formErrors.end_time ? 'is-invalid' : ''}`}
                  value={form.end_time}
                  onChange={(e) => setForm({ ...form, end_time: e.target.value })}
                  required
                />
                {formErrors.end_time && <div className="invalid-feedback">{formErrors.end_time[0]}</div>}
              </div>
            </div>
            <div className="mb-4">
              <label className="form-label">Alasan</label>
              <textarea
                className={`form-control ${formErrors.reason ? 'is-invalid' : ''}`}
                value={form.reason}
                onChange={(e) => setForm({ ...form, reason: e.target.value })}
                required
                rows={3}
              />
              {formErrors.reason && <div className="invalid-feedback">{formErrors.reason[0]}</div>}
            </div>
            <div className="d-flex justify-content-end gap-2">
              <button type="button" className="btn btn-light" onClick={() => setShowForm(false)}>Batal</button>
              <button type="submit" className="btn btn-primary" disabled={submitMutation.isPending}>
                {submitMutation.isPending
                  ? <><span className="spinner-border spinner-border-sm me-1" />Menyimpan...</>
                  : 'Ajukan'}
              </button>
            </div>
          </form>
        </Modal>
      )}
    </>
  )
}
