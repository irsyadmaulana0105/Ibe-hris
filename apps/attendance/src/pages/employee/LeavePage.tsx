import { usePageTitle } from '@/hooks/usePageTitle'
import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { listLeaves, submitLeave, cancelLeave } from '@/api/leave'
import StatusBadge from '@/components/StatusBadge'
import Pagination from '@/components/Pagination'
import Modal from '@/components/Modal'
import { getValidationErrors } from '@/utils/apiError'

const LEAVE_TYPES = [
  { value: 'annual',          label: 'Cuti Tahunan' },
  { value: 'sick',            label: 'Sakit' },
  { value: 'personal',        label: 'Keperluan Pribadi' },
  { value: 'medical_checkup', label: 'Cek Kesehatan' },
  { value: 'other',           label: 'Lainnya' },
]

export default function LeavePage() {
  usePageTitle('Cuti')
  const qc = useQueryClient()
  const [page, setPage] = useState(1)
  const [showForm, setShowForm] = useState(false)
  const [form, setForm] = useState({ leave_type: 'annual', start_date: '', end_date: '', reason: '' })
  const [formErrors, setFormErrors] = useState<Record<string, string[]>>({})

  const { data, isLoading } = useQuery({
    queryKey: ['leaves', page],
    queryFn: () => listLeaves(page),
  })

  const submitMutation = useMutation({
    mutationFn: () => submitLeave(form),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['leaves'] })
      setShowForm(false)
      setForm({ leave_type: 'annual', start_date: '', end_date: '', reason: '' })
      setFormErrors({})
    },
    onError: (err: unknown) => {
      setFormErrors(getValidationErrors(err))
    },
  })

  const cancelMutation = useMutation({
    mutationFn: cancelLeave,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['leaves'] }),
  })

  return (
    <>
      <div className="d-flex align-items-center justify-content-between mb-4">
        <div>
          <h5 className="mb-0" style={{ fontWeight: 600 }}>Cuti</h5>
          {data && (
            <p className="text-muted mb-0" style={{ fontSize: 13 }}>
              Sisa cuti tahunan: <strong>{data.annual_balance} hari</strong>
            </p>
          )}
        </div>
        <button className="btn btn-primary btn-sm" onClick={() => setShowForm(true)}>
          + Ajukan Cuti
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
              {/* DESKTOP TABLE - hidden on mobile */}
              <div className="d-none d-md-block table-responsive">
                <table className="table table-hover mb-0">
                  <thead>
                    <tr>
                      <th>Jenis</th>
                      <th>Tanggal</th>
                      <th>Hari</th>
                      <th>Status</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    {data?.data.length === 0 && (
                      <tr>
                        <td colSpan={5} className="text-center text-muted py-4">
                          Belum ada pengajuan cuti.
                        </td>
                      </tr>
                    )}
                    {data?.data.map((leave) => (
                      <tr key={leave.id}>
                        <td>{leave.leave_type.label}</td>
                        <td>
                          {leave.start_date === leave.end_date
                            ? leave.start_date
                            : `${leave.start_date} – ${leave.end_date}`}
                        </td>
                        <td>{leave.days_count} hari</td>
                        <td><StatusBadge status={leave.status} /></td>
                        <td className="text-end">
                          {leave.status === 'pending' && (
                            <button
                              onClick={() => cancelMutation.mutate(leave.id)}
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

              {/* MOBILE CARD LIST - hidden on desktop */}
              <div className="d-block d-md-none">
                {data?.data.length === 0 && (
                  <div className="text-center text-muted py-4 px-3" style={{ fontSize: 13 }}>
                    Belum ada pengajuan cuti.
                  </div>
                )}
                {data?.data.map((leave) => (
                  <div key={leave.id} className="border-bottom px-3 py-3">
                    <div className="d-flex justify-content-between align-items-start mb-2 gap-2">
                      <div style={{ minWidth: 0, flex: 1 }}>
                        <p className="mb-0 fw-semibold text-truncate" style={{ fontSize: 14 }}>
                          {leave.leave_type.label}
                        </p>
                        <small className="text-muted">
                          {leave.start_date === leave.end_date
                            ? leave.start_date
                            : `${leave.start_date} → ${leave.end_date}`}
                        </small>
                      </div>
                      <StatusBadge status={leave.status} />
                    </div>
                    <div className="d-flex justify-content-between align-items-center">
                      <small className="text-muted">{leave.days_count} hari</small>
                      {leave.status === 'pending' && (
                        <button
                          onClick={() => cancelMutation.mutate(leave.id)}
                          className="btn btn-link btn-sm text-danger p-0"
                          style={{ fontSize: 12 }}
                        >
                          Batalkan
                        </button>
                      )}
                    </div>
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
        <Modal title="Ajukan Cuti" onClose={() => { setShowForm(false); setFormErrors({}) }}>
          <form onSubmit={(e) => { e.preventDefault(); submitMutation.mutate() }}>
            <div className="mb-3">
              <label className="form-label">Jenis Cuti</label>
              <select
                className="form-select"
                value={form.leave_type}
                onChange={(e) => setForm({ ...form, leave_type: e.target.value })}
              >
                {LEAVE_TYPES.map((t) => (
                  <option key={t.value} value={t.value}>{t.label}</option>
                ))}
              </select>
            </div>
            <div className="row g-3 mb-3">
              <div className="col-6">
                <label className="form-label">Mulai</label>
                <input
                  type="date"
                  className={`form-control ${formErrors.start_date ? 'is-invalid' : ''}`}
                  value={form.start_date}
                  onChange={(e) => setForm({ ...form, start_date: e.target.value })}
                  required
                />
                {formErrors.start_date && <div className="invalid-feedback">{formErrors.start_date[0]}</div>}
              </div>
              <div className="col-6">
                <label className="form-label">Selesai</label>
                <input
                  type="date"
                  className={`form-control ${formErrors.end_date ? 'is-invalid' : ''}`}
                  value={form.end_date}
                  onChange={(e) => setForm({ ...form, end_date: e.target.value })}
                  required
                />
                {formErrors.end_date && <div className="invalid-feedback">{formErrors.end_date[0]}</div>}
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
