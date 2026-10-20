import { usePageTitle } from '@/hooks/usePageTitle'
import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { listSalaryAdvances, submitSalaryAdvance, cancelSalaryAdvance } from '@/api/salaryAdvance'
import StatusBadge from '@/components/StatusBadge'
import Pagination from '@/components/Pagination'
import Modal from '@/components/Modal'
import { getValidationErrors } from '@/utils/apiError'

function formatRp(amount: number) {
  return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(amount)
}

function todayIso() {
  const d = new Date()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${d.getFullYear()}-${m}-${day}`
}

export default function SalaryAdvancePage() {
  usePageTitle('Kasbon')
  const qc = useQueryClient()
  const [page, setPage] = useState(1)
  const [showForm, setShowForm] = useState(false)
  const [form, setForm] = useState({ date: todayIso(), amount: '', purpose: '' })
  const [formErrors, setFormErrors] = useState<Record<string, string[]>>({})

  const { data, isLoading } = useQuery({
    queryKey: ['salary-advances', page],
    queryFn: () => listSalaryAdvances(page),
  })

  const submitMutation = useMutation({
    mutationFn: () => submitSalaryAdvance({ date: form.date, amount: Number(form.amount), purpose: form.purpose }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['salary-advances'] })
      setShowForm(false)
      setForm({ date: todayIso(), amount: '', purpose: '' })
      setFormErrors({})
    },
    onError: (err: unknown) => {
      setFormErrors(getValidationErrors(err))
    },
  })

  const cancelMutation = useMutation({
    mutationFn: cancelSalaryAdvance,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['salary-advances'] }),
  })

  return (
    <>
      <div className="d-flex align-items-center justify-content-between mb-4">
        <h5 className="mb-0" style={{ fontWeight: 600 }}>Kasbon</h5>
        <button className="btn btn-primary btn-sm" onClick={() => setShowForm(true)}>
          + Ajukan Kasbon
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
                      <th>Jumlah</th>
                      <th>Alasan</th>
                      <th>Tanggal</th>
                      <th>Status</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    {data?.data.length === 0 && (
                      <tr>
                        <td colSpan={5} className="text-center text-muted py-4">
                          Belum ada pengajuan kasbon.
                        </td>
                      </tr>
                    )}
                    {data?.data.map((sa) => (
                      <tr key={sa.id}>
                        <td className="fw-medium">{formatRp(sa.amount)}</td>
                        <td style={{ maxWidth: 200, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }} className="text-muted">{sa.reason}</td>
                        <td className="text-muted">{sa.created_at.slice(0, 10)}</td>
                        <td><StatusBadge status={sa.status} /></td>
                        <td className="text-end">
                          {sa.status === 'pending' && (
                            <button
                              onClick={() => cancelMutation.mutate(sa.id)}
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
                    Belum ada pengajuan kasbon.
                  </div>
                )}
                {data?.data.map((sa) => (
                  <div key={sa.id} className="border-bottom px-3 py-3">
                    <div className="d-flex justify-content-between align-items-start mb-2 gap-2">
                      <div style={{ minWidth: 0, flex: 1 }}>
                        <p className="mb-0 fw-semibold" style={{ fontSize: 14 }}>
                          {formatRp(sa.amount)}
                        </p>
                        <small className="text-muted">{sa.created_at.slice(0, 10)}</small>
                      </div>
                      <StatusBadge status={sa.status} />
                    </div>
                    {sa.reason && (
                      <p className="text-muted mb-2" style={{ fontSize: 12.5, lineHeight: 1.45 }}>
                        {sa.reason}
                      </p>
                    )}
                    {sa.status === 'pending' && (
                      <div className="d-flex justify-content-end">
                        <button
                          onClick={() => cancelMutation.mutate(sa.id)}
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
        <Modal title="Ajukan Kasbon" onClose={() => { setShowForm(false); setFormErrors({}) }}>
          <form onSubmit={(e) => { e.preventDefault(); submitMutation.mutate() }}>
            <div className="mb-3">
              <label className="form-label">Tanggal</label>
              <input
                type="date"
                className={`form-control ${formErrors.date ? 'is-invalid' : ''}`}
                value={form.date}
                onChange={(e) => setForm({ ...form, date: e.target.value })}
                max={todayIso()}
                required
              />
              {formErrors.date && <div className="invalid-feedback">{formErrors.date[0]}</div>}
            </div>
            <div className="mb-3">
              <label className="form-label">Jumlah (Rp)</label>
              <input
                type="number"
                className={`form-control ${formErrors.amount ? 'is-invalid' : ''}`}
                value={form.amount}
                onChange={(e) => setForm({ ...form, amount: e.target.value })}
                required
                min="1"
                placeholder="500000"
              />
              {formErrors.amount && <div className="invalid-feedback">{formErrors.amount[0]}</div>}
            </div>
            <div className="mb-4">
              <label className="form-label">Tujuan / Alasan</label>
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
