import { usePageTitle } from '@/hooks/usePageTitle'
import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { listReimbursements, submitReimbursement, cancelReimbursement, downloadReceipt } from '@/api/reimbursement'
import { useAuth } from '@/context/AuthContext'
import StatusBadge from '@/components/StatusBadge'
import Pagination from '@/components/Pagination'
import Modal from '@/components/Modal'
import AttachmentPreview from '@/components/AttachmentPreview'
import { getValidationErrors } from '@/utils/apiError'
import { INDONESIAN_BANKS, validateBankAccount } from '@/data/banks'

function formatRp(amount: number) {
  return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(amount)
}

function todayIso() {
  const d = new Date()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${d.getFullYear()}-${m}-${day}`
}

export default function ReimbursementPage() {
  usePageTitle('Reimburse')
  const qc = useQueryClient()
  const { user } = useAuth()
  const [page, setPage] = useState(1)
  const [showForm, setShowForm] = useState(false)
  const [form, setForm] = useState({
    date: todayIso(),
    category_id: '',
    event: '',
    unit_price: '',
    quantity: '1',
    bank_name: '',
    bank_account_number: '',
    bank_account_holder: user?.name ?? '',
  })
  const [attachment, setAttachment] = useState<File | null>(null)
  const [formErrors, setFormErrors] = useState<Record<string, string[]>>({})
  const [preview, setPreview] = useState<{ url: string; title: string } | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['reimbursements', page],
    queryFn: () => listReimbursements(page),
  })

  const categories = data?.categories ?? []
  const totalPreview = (Number(form.unit_price) || 0) * (Number(form.quantity) || 0)

  const submitMutation = useMutation({
    mutationFn: () => {
      const fd = new FormData()
      fd.append('date', form.date)
      fd.append('category_id', form.category_id)
      fd.append('event', form.event)
      fd.append('unit_price', form.unit_price)
      fd.append('quantity', form.quantity)
      fd.append('bank_name', form.bank_name)
      fd.append('bank_account_number', form.bank_account_number.replace(/[\s-]/g, ''))
      fd.append('bank_account_holder', form.bank_account_holder)
      if (attachment) fd.append('attachment', attachment)
      return submitReimbursement(fd)
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['reimbursements'] })
      setShowForm(false)
      setForm({
        date: todayIso(), category_id: '', event: '', unit_price: '', quantity: '1',
        bank_name: '', bank_account_number: '', bank_account_holder: user?.name ?? '',
      })
      setAttachment(null)
      setFormErrors({})
    },
    onError: (err: unknown) => {
      setFormErrors(getValidationErrors(err))
    },
  })

  const cancelMutation = useMutation({
    mutationFn: cancelReimbursement,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['reimbursements'] }),
  })

  return (
    <>
      <div className="d-flex align-items-center justify-content-between mb-4">
        <h5 className="mb-0" style={{ fontWeight: 600 }}>Reimbursement</h5>
        <button className="btn btn-primary btn-sm" onClick={() => setShowForm(true)}>
          + Ajukan Reimburse
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
                {/* Hitung starting row number berdasarkan halaman (page 2 = mulai dari 21 kalau per_page=20) */}
                {(() => null)()}
                <table className="table table-hover align-middle mb-0">
                  <thead style={{ background: '#f8fafc' }}>
                    <tr style={{ fontSize: 12, letterSpacing: '.04em', textTransform: 'uppercase', color: '#64748b' }}>
                      <th style={{ width: 56, fontWeight: 700, textAlign: 'center' }}>No</th>
                      <th style={{ minWidth: 240, fontWeight: 700 }}>Keterangan</th>
                      <th className="text-end" style={{ width: 130, fontWeight: 700 }}>Jumlah</th>
                      <th className="text-end" style={{ width: 130, fontWeight: 700 }}>Disetujui</th>
                      <th style={{ width: 200, fontWeight: 700 }}>Progress</th>
                      <th style={{ width: 140, fontWeight: 700 }}>Status</th>
                      <th className="text-end" style={{ width: 170, fontWeight: 700 }}>Aksi</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data?.data.length === 0 && (
                      <tr>
                        <td colSpan={7} className="text-center text-muted py-4">
                          Belum ada pengajuan reimbursement.
                        </td>
                      </tr>
                    )}
                    {data?.data.map((r, idx) => {
                      // Row number = global index berdasarkan pagination meta
                      const startIdx = ((data?.meta?.current_page ?? 1) - 1) * (data?.meta?.per_page ?? 20)
                      const rowNo = startIdx + idx + 1
                      return (
                      <tr key={r.id}>
                        <td style={{ textAlign: 'center', fontFamily: 'monospace', color: '#94a3b8', fontSize: 12 }}>
                          {rowNo}
                        </td>
                        <td>
                          <div className="d-flex align-items-start gap-2">
                            <div style={{ minWidth: 0, flex: 1 }}>
                              <p className="mb-0 fw-semibold" style={{ fontSize: 13.5 }}>{r.event}</p>
                              <small className="text-muted" style={{ fontSize: 11.5 }}>
                                {r.category && <>{r.category.name} · </>}
                                {r.date} · {r.quantity}× {formatRp(r.unit_price)}
                              </small>
                              {r.bank_name && (
                                <div className="text-muted" style={{ fontSize: 11, marginTop: 2 }}>
                                  💳 {r.bank_name} · <span style={{ fontFamily: 'monospace' }}>{r.bank_account_number}</span>
                                </div>
                              )}
                            </div>
                            {/* Bukti lampiran - icon clip kecil di samping keterangan (opsi A) */}
                            {r.attachment_url && (
                              <button
                                type="button"
                                title="Lihat lampiran bukti yang Anda upload"
                                onClick={() => setPreview({ url: r.attachment_url!, title: r.event })}
                                style={{
                                  background: '#f1f5f9', border: '1px solid #e2e8f0', borderRadius: 6,
                                  padding: '4px 8px', cursor: 'pointer', fontSize: 11, color: '#64748b',
                                  display: 'flex', alignItems: 'center', gap: 4, flexShrink: 0,
                                }}
                                onMouseOver={(e) => (e.currentTarget.style.background = '#e0f2fe')}
                                onMouseOut={(e) => (e.currentTarget.style.background = '#f1f5f9')}
                              >
                                📎 Bukti
                              </button>
                            )}
                          </div>
                        </td>
                        <td className="text-end" style={{ fontVariantNumeric: 'tabular-nums' }}>{formatRp(r.total_amount)}</td>
                        <td className="text-end" style={{ fontVariantNumeric: 'tabular-nums', color: r.approved_amount ? '#16a34a' : '#94a3b8', fontWeight: 600 }}>
                          {r.approved_amount != null ? formatRp(r.approved_amount) : '-'}
                        </td>
                        <td style={{ fontSize: 11.5 }}>
                          {/* Progress timeline mini */}
                          {r.hr_approved_at && (
                            <div className="d-flex align-items-center gap-1" style={{ color: '#22c55e' }}>
                              <span>✓</span>
                              <span>HR {new Date(r.hr_approved_at).toLocaleDateString('id-ID', { day: '2-digit', month: 'short' })}</span>
                            </div>
                          )}
                          {r.settled_at && (
                            <div className="d-flex align-items-center gap-1" style={{ color: '#16a34a', fontWeight: 600 }}>
                              <span>💰</span>
                              <span>Cair {new Date(r.settled_at).toLocaleDateString('id-ID', { day: '2-digit', month: 'short' })}</span>
                            </div>
                          )}
                          {r.receipt_number && (
                            <div className="text-muted" style={{ fontSize: 10, fontFamily: 'monospace', marginTop: 2 }}>
                              {r.receipt_number}
                            </div>
                          )}
                          {!r.hr_approved_at && !r.settled_at && r.status === 'pending' && (
                            <span className="text-muted">Menunggu...</span>
                          )}
                          {r.status === 'rejected' && (
                            <span style={{ color: '#dc2626' }}>✗ Ditolak</span>
                          )}
                        </td>
                        <td><StatusBadge status={r.status} /></td>
                        <td className="text-end">
                          <div className="d-flex justify-content-end gap-2 flex-wrap">
                            {r.status === 'pending' && (
                              <button
                                onClick={() => { if (confirm(`Batalkan pengajuan "${r.event}"?`)) cancelMutation.mutate(r.id) }}
                                className="btn btn-link btn-sm text-danger p-0"
                                style={{ fontSize: 12 }}
                              >
                                Batalkan
                              </button>
                            )}
                            {r.status === 'settled' && (
                              <button
                                type="button"
                                onClick={async () => {
                                  try {
                                    const blob = await downloadReceipt(r.id)
                                    const url = URL.createObjectURL(blob)
                                    window.open(url, '_blank')
                                    setTimeout(() => URL.revokeObjectURL(url), 60_000)
                                  } catch {}
                                }}
                                className="btn btn-sm btn-success"
                                style={{ fontSize: 11, padding: '2px 10px', fontWeight: 600 }}
                              >
                                📄 Kwitansi
                              </button>
                            )}
                            {r.status === 'settled' && r.payment_proof_url && (
                              <button
                                type="button"
                                onClick={() => setPreview({ url: r.payment_proof_url!, title: 'Bukti Transfer dari Finance' })}
                                className="btn btn-link btn-sm text-info p-0"
                                style={{ fontSize: 12 }}
                                title="Bukti transfer dari Finance - verifikasi uang sudah masuk rekening Anda"
                              >
                                💸 Bukti Transfer
                              </button>
                            )}
                          </div>
                        </td>
                      </tr>
                      )
                    })}
                  </tbody>
                </table>
              </div>

              {/* MOBILE CARD LIST */}
              <div className="d-block d-md-none">
                {data?.data.length === 0 && (
                  <div className="text-center text-muted py-4 px-3" style={{ fontSize: 13 }}>
                    Belum ada pengajuan reimbursement.
                  </div>
                )}
                {data?.data.map((r) => (
                  <div key={r.id} className="border-bottom px-3 py-3">
                    <div className="d-flex justify-content-between align-items-start mb-2 gap-2">
                      <div style={{ minWidth: 0, flex: 1 }}>
                        <p className="mb-0 fw-semibold text-truncate" style={{ fontSize: 14 }}>
                          {r.event}
                        </p>
                        <small className="text-muted">
                          {r.category && <>{r.category.name} · </>}
                          {r.date}
                        </small>
                      </div>
                      <StatusBadge status={r.status} />
                    </div>
                    <div className="d-flex justify-content-between align-items-baseline mb-2">
                      <small className="text-muted">Diajukan</small>
                      <span className="fw-medium" style={{ fontSize: 13 }}>{formatRp(r.total_amount)}</span>
                    </div>
                    {r.approved_amount != null && (
                      <div className="d-flex justify-content-between align-items-baseline mb-2">
                        <small className="text-muted">Disetujui</small>
                        <span className="fw-medium text-success" style={{ fontSize: 13 }}>{formatRp(r.approved_amount)}</span>
                      </div>
                    )}

                    {/* Timeline approval - tampilkan tahap-tahap yang sudah dilewati */}
                    {(r.hr_approved_at || r.settled_at) && (
                      <div className="mt-2 pt-2 border-top" style={{ fontSize: 11.5 }}>
                        {r.hr_approved_at && (
                          <div className="d-flex align-items-center gap-2 mb-1">
                            <span style={{ color: '#22c55e' }}>✓</span>
                            <span className="text-muted">Disetujui HR pada</span>
                            <span className="fw-medium">{new Date(r.hr_approved_at).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' })}</span>
                          </div>
                        )}
                        {r.settled_at && (
                          <div className="d-flex align-items-center gap-2 mb-1">
                            <span style={{ color: '#16a34a' }}>💰</span>
                            <span className="text-muted">Dicairkan pada</span>
                            <span className="fw-medium">{new Date(r.settled_at).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' })}</span>
                          </div>
                        )}
                        {r.receipt_number && (
                          <div className="d-flex align-items-center gap-2">
                            <span style={{ color: '#0ea5e9' }}>📄</span>
                            <span className="text-muted">Kwitansi</span>
                            <span className="fw-medium" style={{ fontFamily: 'monospace', fontSize: 11 }}>{r.receipt_number}</span>
                          </div>
                        )}
                      </div>
                    )}

                    <div className="d-flex justify-content-end gap-3 mt-2 flex-wrap">
                      {r.attachment_url && (
                        <button
                          type="button"
                          onClick={() => setPreview({ url: r.attachment_url!, title: r.event })}
                          className="btn btn-link btn-sm text-primary p-0"
                          style={{ fontSize: 12 }}
                        >
                          Lihat Bukti
                        </button>
                      )}
                      {r.status === 'settled' && r.receipt_pdf_url && (
                        <button
                          type="button"
                          onClick={async () => {
                            try {
                              const blob = await downloadReceipt(r.id)
                              const url = URL.createObjectURL(blob)
                              window.open(url, '_blank')
                              setTimeout(() => URL.revokeObjectURL(url), 60_000)
                            } catch {
                              // silent - error udah handled di axios interceptor
                            }
                          }}
                          className="btn btn-link btn-sm text-success p-0 fw-semibold"
                          style={{ fontSize: 12 }}
                        >
                          📄 Download Kwitansi
                        </button>
                      )}
                      {r.status === 'settled' && r.payment_proof_url && (
                        <button
                          type="button"
                          onClick={() => setPreview({ url: r.payment_proof_url!, title: 'Bukti Transfer dari Finance' })}
                          className="btn btn-link btn-sm text-info p-0"
                          style={{ fontSize: 12 }}
                        >
                          💸 Bukti Transfer
                        </button>
                      )}
                      {r.status === 'pending' && (
                        <button
                          onClick={() => cancelMutation.mutate(r.id)}
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
        <Modal title="Ajukan Reimbursement" onClose={() => { setShowForm(false); setFormErrors({}) }}>
          <form onSubmit={(e) => {
            e.preventDefault()
            // JS-level guard - HTML5 `required` on file input is unreliable on mobile Safari/Chrome,
            // so we validate explicitly before firing the request.
            const errs: Record<string, string[]> = {}
            if (!form.date) errs.date = ['Tanggal wajib diisi.']
            if (!form.category_id) errs.category_id = ['Pilih kategori reimbursement.']
            if (!form.event || form.event.length < 3) errs.event = ['Keterangan/acara wajib diisi (min 3 karakter).']
            if (!form.unit_price || Number(form.unit_price) < 1) errs.unit_price = ['Harga satuan wajib diisi.']
            if (!form.quantity || Number(form.quantity) < 1) errs.quantity = ['Kuantitas wajib diisi.']
            if (!attachment) errs.attachment = ['Lampiran bukti (PDF/JPG/PNG, max 3MB) wajib diunggah.']
            if (!form.bank_name) errs.bank_name = ['Pilih bank tujuan transfer.']
            if (!form.bank_account_number) errs.bank_account_number = ['Nomor rekening wajib diisi.']
            else {
              const err = validateBankAccount(form.bank_name, form.bank_account_number)
              if (err) errs.bank_account_number = [err]
            }
            if (!form.bank_account_holder) errs.bank_account_holder = ['Nama pemilik rekening wajib diisi.']
            if (Object.keys(errs).length > 0) { setFormErrors(errs); return }
            setFormErrors({})
            submitMutation.mutate()
          }}>
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
              <label className="form-label">Kategori</label>
              <select
                className={`form-select ${formErrors.category_id ? 'is-invalid' : ''}`}
                value={form.category_id}
                onChange={(e) => setForm({ ...form, category_id: e.target.value })}
                required
              >
                <option value="">Pilih kategori...</option>
                {categories.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.name}{c.limit_amount ? ` (limit ${formatRp(c.limit_amount)})` : ''}
                  </option>
                ))}
              </select>
              {formErrors.category_id && <div className="invalid-feedback">{formErrors.category_id[0]}</div>}
            </div>
            <div className="mb-3">
              <label className="form-label">Keterangan / Acara</label>
              <input
                type="text"
                className={`form-control ${formErrors.event ? 'is-invalid' : ''}`}
                value={form.event}
                onChange={(e) => setForm({ ...form, event: e.target.value })}
                placeholder="Contoh: Tiket KA Jakarta-Bandung untuk meeting klien"
                minLength={3}
                maxLength={255}
                required
              />
              {formErrors.event && <div className="invalid-feedback">{formErrors.event[0]}</div>}
            </div>
            <div className="row g-2 mb-3">
              <div className="col-7">
                <label className="form-label">Harga Satuan (Rp)</label>
                <input
                  type="number"
                  className={`form-control ${formErrors.unit_price ? 'is-invalid' : ''}`}
                  value={form.unit_price}
                  onChange={(e) => setForm({ ...form, unit_price: e.target.value })}
                  min="1"
                  required
                />
                {formErrors.unit_price && <div className="invalid-feedback">{formErrors.unit_price[0]}</div>}
              </div>
              <div className="col-5">
                <label className="form-label">Kuantitas</label>
                <input
                  type="number"
                  className={`form-control ${formErrors.quantity ? 'is-invalid' : ''}`}
                  value={form.quantity}
                  onChange={(e) => setForm({ ...form, quantity: e.target.value })}
                  min="1"
                  required
                />
                {formErrors.quantity && <div className="invalid-feedback">{formErrors.quantity[0]}</div>}
              </div>
            </div>
            {totalPreview > 0 && (
              <div className="mb-3 p-2 rounded" style={{ background: '#f1f5f9', fontSize: 13 }}>
                <span className="text-muted">Total: </span>
                <strong>{formatRp(totalPreview)}</strong>
              </div>
            )}
            <div className="mb-3">
              <label className="form-label">Lampiran Bukti</label>
              <input
                type="file"
                accept="application/pdf,image/jpeg,image/jpg,image/png"
                onChange={(e) => setAttachment(e.target.files?.[0] ?? null)}
                className={`form-control ${formErrors.attachment ? 'is-invalid' : ''}`}
                required
              />
              <div className="form-text" style={{ fontSize: 11 }}>PDF, JPG, atau PNG · maks 3 MB</div>
              {formErrors.attachment && <div className="invalid-feedback d-block">{formErrors.attachment[0]}</div>}
            </div>

            {/* Bank info - Finance perlu data ini untuk transfer */}
            <div className="mb-3 p-3 rounded" style={{ background: '#f0f9ff', border: '1px solid #bae6fd' }}>
              <div className="mb-2 d-flex align-items-center" style={{ fontSize: 12, fontWeight: 600, color: '#075985' }}>
                <span className="me-1">💳</span>
                <span>Rekening Tujuan Transfer (Finance akan transfer kesini)</span>
              </div>
              <div className="mb-2">
                <label className="form-label" style={{ fontSize: 13 }}>Bank</label>
                <select
                  className={`form-select ${formErrors.bank_name ? 'is-invalid' : ''}`}
                  value={form.bank_name}
                  onChange={(e) => setForm({ ...form, bank_name: e.target.value })}
                  required
                >
                  <option value="">Pilih bank...</option>
                  {INDONESIAN_BANKS.map((b) => (
                    <option key={b.code + b.name} value={b.name}>{b.name}</option>
                  ))}
                  <option value="Lainnya">Bank Lainnya</option>
                </select>
                {formErrors.bank_name && <div className="invalid-feedback">{formErrors.bank_name[0]}</div>}
              </div>
              <div className="mb-2">
                <label className="form-label" style={{ fontSize: 13 }}>Nomor Rekening</label>
                <input
                  type="text"
                  inputMode="numeric"
                  className={`form-control ${formErrors.bank_account_number ? 'is-invalid' : ''}`}
                  value={form.bank_account_number}
                  onChange={(e) => setForm({ ...form, bank_account_number: e.target.value })}
                  placeholder="contoh: 1234567890"
                  maxLength={30}
                  required
                />
                {formErrors.bank_account_number && (
                  <div className="invalid-feedback d-block">{formErrors.bank_account_number[0]}</div>
                )}
                <div className="form-text" style={{ fontSize: 11 }}>Hanya angka, tanpa spasi atau tanda baca.</div>
              </div>
              <div className="mb-0">
                <label className="form-label" style={{ fontSize: 13 }}>Nama Pemilik Rekening</label>
                <input
                  type="text"
                  className={`form-control ${formErrors.bank_account_holder ? 'is-invalid' : ''}`}
                  value={form.bank_account_holder}
                  onChange={(e) => setForm({ ...form, bank_account_holder: e.target.value })}
                  placeholder="Sesuai buku tabungan / KTP"
                  maxLength={200}
                  required
                />
                {formErrors.bank_account_holder && (
                  <div className="invalid-feedback">{formErrors.bank_account_holder[0]}</div>
                )}
              </div>
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

      <AttachmentPreview
        url={preview?.url ?? null}
        title={preview?.title}
        onClose={() => setPreview(null)}
      />
    </>
  )
}
