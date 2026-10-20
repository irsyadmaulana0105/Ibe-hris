import { usePageTitle } from '@/hooks/usePageTitle'
import { useEffect, useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  listBusinessTrips,
  listBusinessTripShifts,
  submitBusinessTrip,
  cancelBusinessTrip,
} from '@/api/businessTrip'
import StatusBadge from '@/components/StatusBadge'
import Pagination from '@/components/Pagination'
import Modal from '@/components/Modal'
import { getValidationErrors } from '@/utils/apiError'

const todayIso = () => new Date().toISOString().slice(0, 10)

export default function BusinessTripPage() {
  usePageTitle('Perjalanan Dinas')
  const qc = useQueryClient()
  const [page, setPage] = useState(1)
  const [showForm, setShowForm] = useState(false)
  const [form, setForm] = useState({ date: todayIso(), shift_id: 0, destination: '', purpose: '' })
  const [formErrors, setFormErrors] = useState<Record<string, string[]>>({})

  const { data, isLoading } = useQuery({
    queryKey: ['business-trips', page],
    queryFn: () => listBusinessTrips(page),
  })

  const { data: shifts = [] } = useQuery({
    queryKey: ['business-trips/shifts'],
    queryFn: listBusinessTripShifts,
    enabled: showForm,
  })

  useEffect(() => {
    if (showForm && shifts.length > 0 && form.shift_id === 0) {
      setForm((f) => ({ ...f, shift_id: shifts[0].id }))
    }
  }, [showForm, shifts, form.shift_id])

  const submitMutation = useMutation({
    mutationFn: () => submitBusinessTrip({
      date: form.date,
      shift_id: form.shift_id,
      destination: form.destination,
      purpose: form.purpose,
    }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['business-trips'] })
      setShowForm(false)
      setForm({ date: todayIso(), shift_id: 0, destination: '', purpose: '' })
      setFormErrors({})
    },
    onError: (err: unknown) => setFormErrors(getValidationErrors(err)),
  })

  const cancelMutation = useMutation({
    mutationFn: cancelBusinessTrip,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['business-trips'] }),
  })

  return (
    <>
      <div className="d-flex align-items-center justify-content-between mb-4">
        <h5 className="mb-0" style={{ fontWeight: 600 }}>Perjalanan Dinas</h5>
        <button className="btn btn-primary btn-sm" onClick={() => setShowForm(true)}>
          + Ajukan Perjalanan
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
                      <th>Tujuan</th>
                      <th>Keperluan</th>
                      <th>Status</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    {data?.data.length === 0 && (
                      <tr>
                        <td colSpan={5} className="text-center text-muted py-4">
                          Belum ada pengajuan perjalanan dinas.
                        </td>
                      </tr>
                    )}
                    {data?.data.map((t) => (
                      <tr key={t.id}>
                        <td>{t.date}</td>
                        <td style={{ maxWidth: 220, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{t.destination}</td>
                        <td style={{ maxWidth: 280, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{t.purpose}</td>
                        <td><StatusBadge status={t.status} /></td>
                        <td className="text-end">
                          {t.status === 'pending' && (
                            <button
                              onClick={() => cancelMutation.mutate(t.id)}
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

              <div className="d-block d-md-none">
                {data?.data.length === 0 && (
                  <div className="text-center text-muted py-4 px-3" style={{ fontSize: 13 }}>
                    Belum ada pengajuan perjalanan dinas.
                  </div>
                )}
                {data?.data.map((t) => (
                  <div key={t.id} className="border-bottom px-3 py-3">
                    <div className="d-flex justify-content-between align-items-start mb-2 gap-2">
                      <div style={{ minWidth: 0, flex: 1 }}>
                        <p className="mb-0 fw-semibold" style={{ fontSize: 14 }}>{t.date}</p>
                        <small className="text-muted">{t.destination}</small>
                      </div>
                      <StatusBadge status={t.status} />
                    </div>
                    <p className="text-muted mb-2" style={{ fontSize: 12.5, lineHeight: 1.45 }}>{t.purpose}</p>
                    {t.status === 'pending' && (
                      <div className="d-flex justify-content-end">
                        <button
                          onClick={() => cancelMutation.mutate(t.id)}
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
        <Modal title="Ajukan Perjalanan Dinas" onClose={() => { setShowForm(false); setFormErrors({}) }}>
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
            <div className="mb-3">
              <label className="form-label">Shift</label>
              <select
                className={`form-select ${formErrors.shift_id ? 'is-invalid' : ''}`}
                value={form.shift_id}
                onChange={(e) => setForm({ ...form, shift_id: Number(e.target.value) })}
                required
              >
                <option value={0} disabled>Pilih shift...</option>
                {shifts.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.name}{s.start_time && s.end_time ? ` (${s.start_time}–${s.end_time})` : ''}
                  </option>
                ))}
              </select>
              {formErrors.shift_id && <div className="invalid-feedback">{formErrors.shift_id[0]}</div>}
            </div>
            <div className="mb-3">
              <label className="form-label">Tujuan</label>
              <input
                type="text"
                className={`form-control ${formErrors.destination ? 'is-invalid' : ''}`}
                value={form.destination}
                onChange={(e) => setForm({ ...form, destination: e.target.value })}
                placeholder="Kantor klien / kota tujuan"
                required
              />
              {formErrors.destination && <div className="invalid-feedback">{formErrors.destination[0]}</div>}
            </div>
            <div className="mb-4">
              <label className="form-label">Keperluan</label>
              <textarea
                className={`form-control ${formErrors.purpose ? 'is-invalid' : ''}`}
                value={form.purpose}
                onChange={(e) => setForm({ ...form, purpose: e.target.value })}
                required
                rows={3}
              />
              {formErrors.purpose && <div className="invalid-feedback">{formErrors.purpose[0]}</div>}
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
