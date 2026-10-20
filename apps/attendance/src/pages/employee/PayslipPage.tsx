import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { downloadMyPayrollSlip, getMyPayroll, listMyPayrolls } from '@/api/payroll'
import FeedbackAlert from '@/components/FeedbackAlert'
import Pagination from '@/components/Pagination'
import { usePageTitle } from '@/hooks/usePageTitle'

function formatCurrency(value: number) {
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
  }).format(value)
}

export default function PayslipPage() {
  usePageTitle('Slip Gaji')
  const [page, setPage] = useState(1)
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [message, setMessage] = useState('')
  const [errorMsg, setErrorMsg] = useState('')

  const payrolls = useQuery({
    queryKey: ['self', 'payrolls', page],
    queryFn: () => listMyPayrolls({ page: String(page) }),
  })

  const detail = useQuery({
    queryKey: ['self', 'payroll', selectedId],
    queryFn: () => getMyPayroll(selectedId as number),
    enabled: selectedId !== null,
  })

  async function handleDownload(payrollId: number, payrollNumber: string) {
    try {
      setErrorMsg('')
      const blob = await downloadMyPayrollSlip(payrollId)
      const url = window.URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = `${payrollNumber}.pdf`
      document.body.appendChild(link)
      link.click()
      link.remove()
      window.URL.revokeObjectURL(url)
      setMessage('Slip gaji berhasil diunduh.')
    } catch (error: any) {
      setErrorMsg(error?.response?.data?.message ?? 'Gagal mengunduh slip gaji.')
      setMessage('')
    }
  }

  function handlePrint() {
    window.print()
  }

  return (
    <>
      <style>{`
        @media print {
          .payslip-print-hide {
            display: none !important;
          }

          .payslip-print-col {
            width: 100% !important;
          }

          .payslip-print-card {
            border: 0 !important;
            box-shadow: none !important;
          }

          .payslip-print-card .card-header {
            display: none !important;
          }

          .payslip-print-card .card-body {
            padding: 0 !important;
          }

          .payslip-print-title {
            display: block !important;
          }
        }
      `}</style>

      <div className="d-flex align-items-start justify-content-between mb-4 gap-3 payslip-print-hide">
        <div>
          <h5 className="mb-1" style={{ fontWeight: 700 }}>Slip Gaji</h5>
          <p className="text-muted mb-0" style={{ fontSize: 13 }}>
            Riwayat slip gaji yang sudah disetujui, lengkap dengan detail komponen dan unduhan PDF.
          </p>
        </div>
      </div>

      <FeedbackAlert type="success" message={message} className="payslip-print-hide" />
      <FeedbackAlert type="danger" message={errorMsg} className="payslip-print-hide" />

      <div className="row g-3">
        <div className="col-lg-5 payslip-print-hide">
          <div className="card">
            <div className="card-header">Riwayat Slip</div>
            <div className="card-body p-0">
              {payrolls.isLoading ? (
                <div className="d-flex justify-content-center py-5"><div className="spinner-border text-primary spinner-border-sm" /></div>
              ) : (
                <>
                  <div className="list-group list-group-flush">
                    {payrolls.data?.data.length === 0 && (
                      <div className="text-center text-muted py-4">Belum ada slip gaji.</div>
                    )}
                    {payrolls.data?.data.map((payroll) => (
                      <button
                        key={payroll.id}
                        className={`list-group-item list-group-item-action ${selectedId === payroll.id ? 'active' : ''}`}
                        onClick={() => setSelectedId(payroll.id)}
                      >
                        <div className="d-flex justify-content-between gap-2">
                          <div className="text-start">
                            <div className="fw-medium">{payroll.period.month}/{payroll.period.year}</div>
                            <small>{payroll.period.label}</small>
                          </div>
                          <div className="text-end">
                            <div className="fw-medium">{formatCurrency(payroll.net_total)}</div>
                            <small>{payroll.status}</small>
                          </div>
                        </div>
                      </button>
                    ))}
                  </div>
                  {payrolls.data?.meta && (
                    <div className="px-3 py-3">
                      <Pagination meta={payrolls.data.meta} onPageChange={setPage} />
                    </div>
                  )}
                </>
              )}
            </div>
          </div>
        </div>

        <div className="col-lg-7 payslip-print-col">
          <div className="card payslip-print-card">
            <div className="card-header">Detail Slip</div>
            <div className="card-body">
              {!selectedId ? (
                <div className="text-muted text-center py-5">Pilih slip gaji untuk melihat detailnya.</div>
              ) : detail.isLoading ? (
                <div className="d-flex justify-content-center py-5"><div className="spinner-border text-primary spinner-border-sm" /></div>
              ) : detail.data ? (
                <>
                  <div className="payslip-print-title" style={{ display: 'none' }}>
                    <h5 className="mb-1" style={{ fontWeight: 700 }}>Slip Gaji</h5>
                    <div className="text-muted mb-4" style={{ fontSize: 13 }}>
                      {detail.data.period.label} · {detail.data.payroll_number}
                    </div>
                  </div>

                  <div className="d-flex justify-content-between align-items-start gap-3 mb-4">
                    <div>
                      <h6 className="mb-1" style={{ fontWeight: 700 }}>{detail.data.payroll_number}</h6>
                      <div className="text-muted" style={{ fontSize: 13 }}>{detail.data.period.label}</div>
                    </div>
                    <div className="d-flex gap-2 payslip-print-hide">
                      <button className="btn btn-outline-secondary btn-sm" onClick={handlePrint}>
                        Print
                      </button>
                      <button className="btn btn-dark btn-sm" onClick={() => handleDownload(detail.data!.id, detail.data!.payroll_number)}>
                        Download PDF
                      </button>
                    </div>
                  </div>

                  <div className="row g-3">
                    <div className="col-md-6"><div className="border rounded-3 p-3"><div className="text-muted" style={{ fontSize: 12 }}>Gaji Pokok</div><div className="fw-semibold">{formatCurrency(detail.data.base_salary)}</div></div></div>
                    <div className="col-md-6"><div className="border rounded-3 p-3"><div className="text-muted" style={{ fontSize: 12 }}>Gross Total</div><div className="fw-semibold">{formatCurrency(detail.data.gross_total)}</div></div></div>
                    <div className="col-md-6"><div className="border rounded-3 p-3"><div className="text-muted" style={{ fontSize: 12 }}>Total Potongan</div><div className="fw-semibold">{formatCurrency(detail.data.deduction_total)}</div></div></div>
                    <div className="col-md-6"><div className="border rounded-3 p-3"><div className="text-muted" style={{ fontSize: 12 }}>Take Home Pay</div><div className="fw-semibold">{formatCurrency(detail.data.net_total)}</div></div></div>
                    <div className="col-md-6"><div className="border rounded-3 p-3"><div className="text-muted" style={{ fontSize: 12 }}>Kehadiran</div><div className="fw-semibold">{detail.data.attendance_count} hari · {detail.data.attendance_percentage}%</div></div></div>
                    <div className="col-md-6"><div className="border rounded-3 p-3"><div className="text-muted" style={{ fontSize: 12 }}>Lembur</div><div className="fw-semibold">{formatCurrency(detail.data.overtime_total)}</div></div></div>
                    <div className="col-md-6"><div className="border rounded-3 p-3"><div className="text-muted" style={{ fontSize: 12 }}>Reimbursement</div><div className="fw-semibold">{formatCurrency(detail.data.reimbursement_total)}</div></div></div>
                    <div className="col-md-6"><div className="border rounded-3 p-3"><div className="text-muted" style={{ fontSize: 12 }}>Bayar Kasbon</div><div className="fw-semibold">{formatCurrency(detail.data.salary_advance_payment)}</div></div></div>
                  </div>
                  {(detail.data as any).attendance_bonus_note && (
                    <div className="alert alert-warning mt-3 mb-0 py-2 px-3" style={{ fontSize: 13 }}>
                      <strong>Catatan Bonus Kehadiran:</strong> {(detail.data as any).attendance_bonus_note}
                    </div>
                  )}
                </>
              ) : (
                <div className="text-danger text-center py-5">Gagal memuat detail slip gaji.</div>
              )}
            </div>
          </div>
        </div>
      </div>
    </>
  )
}
