import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  adminApprovePayroll,
  adminBulkGeneratePayroll,
  adminExportPayrollReport,
  adminDownloadPayrollSlip,
  adminGeneratePayroll,
  adminListPayrolls,
  adminMarkPayrollPaid,
  adminSendPayrollSlipEmail,
} from '@/api/admin'
import AdminPageHero from '@/components/admin/AdminPageHero'
import AdminPanelCard from '@/components/admin/AdminPanelCard'
import AdminSummaryCard from '@/components/admin/AdminSummaryCard'
import AdminTableCard from '@/components/admin/AdminTableCard'
import FeedbackAlert from '@/components/FeedbackAlert'
import Pagination from '@/components/Pagination'
import { usePageTitle } from '@/hooks/usePageTitle'

function monthString() {
  return new Date().toISOString().slice(0, 7)
}

function formatCurrency(value: number) {
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
  }).format(value)
}

export default function AdminPayrollPage() {
  usePageTitle('Payroll')
  const qc = useQueryClient()
  const [page, setPage] = useState(1)
  const [month, setMonth] = useState(monthString())
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [employeeId, setEmployeeId] = useState('')
  const [bonusExtra, setBonusExtra] = useState('')
  const [successMsg, setSuccessMsg] = useState('')
  const [errorMsg, setErrorMsg] = useState('')

  const { data, isLoading, isError } = useQuery({
    queryKey: ['admin', 'payrolls', page, month, search, status],
    queryFn: () => adminListPayrolls({
      page: String(page),
      month,
      ...(search ? { search } : {}),
      ...(status ? { status } : {}),
    }),
  })

  const generate = useMutation({
    mutationFn: adminGeneratePayroll,
    onSuccess: () => {
      setEmployeeId('')
      setSuccessMsg('Payroll berhasil digenerate.')
      setErrorMsg('')
      qc.invalidateQueries({ queryKey: ['admin', 'payrolls'] })
    },
    onError: (error: any) => {
      setErrorMsg(error?.response?.data?.message ?? 'Gagal generate payroll.')
      setSuccessMsg('')
    },
  })

  const bulkGenerate = useMutation({
    mutationFn: adminBulkGeneratePayroll,
    onSuccess: (result) => {
      setSuccessMsg(`${result.generated_count} payroll berhasil digenerate.`)
      setErrorMsg('')
      qc.invalidateQueries({ queryKey: ['admin', 'payrolls'] })
    },
    onError: (error: any) => {
      setErrorMsg(error?.response?.data?.message ?? 'Gagal bulk generate payroll.')
      setSuccessMsg('')
    },
  })

  const approve = useMutation({
    mutationFn: adminApprovePayroll,
    onSuccess: () => {
      setSuccessMsg('Payroll berhasil disetujui.')
      setErrorMsg('')
      qc.invalidateQueries({ queryKey: ['admin', 'payrolls'] })
    },
    onError: (error: any) => {
      setErrorMsg(error?.response?.data?.message ?? 'Gagal approve payroll.')
      setSuccessMsg('')
    },
  })

  const emailSlip = useMutation({
    mutationFn: adminSendPayrollSlipEmail,
    onSuccess: (result) => {
      setSuccessMsg(`Email slip diantrikan ke ${result.recipient_email}.`)
      setErrorMsg('')
    },
    onError: (error: any) => {
      setErrorMsg(error?.response?.data?.message ?? 'Gagal mengirim email slip.')
      setSuccessMsg('')
    },
  })

  const markPaid = useMutation({
    mutationFn: adminMarkPayrollPaid,
    onSuccess: () => {
      setSuccessMsg('Payroll berhasil ditandai paid.')
      setErrorMsg('')
      qc.invalidateQueries({ queryKey: ['admin', 'payrolls'] })
    },
    onError: (error: any) => {
      setErrorMsg(error?.response?.data?.message ?? 'Gagal menandai payroll sebagai paid.')
      setSuccessMsg('')
    },
  })

  async function handleDownloadSlip(payrollId: number, payrollNumber: string) {
    try {
      setErrorMsg('')
      const blob = await adminDownloadPayrollSlip(payrollId)
      const url = window.URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = `${payrollNumber}.pdf`
      document.body.appendChild(link)
      link.click()
      link.remove()
      window.URL.revokeObjectURL(url)
      setSuccessMsg('Slip payroll berhasil diunduh.')
    } catch (error: any) {
      setErrorMsg(error?.response?.data?.message ?? 'Gagal mengunduh slip payroll.')
      setSuccessMsg('')
    }
  }

  async function handleExportReport() {
    try {
      setErrorMsg('')
      const blob = await adminExportPayrollReport({ month })
      const url = window.URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = `payroll-report-${month}.csv`
      document.body.appendChild(link)
      link.click()
      link.remove()
      window.URL.revokeObjectURL(url)
      setSuccessMsg('Report payroll berhasil diunduh.')
    } catch (error: any) {
      setErrorMsg(error?.response?.data?.message ?? 'Gagal mengunduh report payroll.')
      setSuccessMsg('')
    }
  }

  function submitGenerate() {
    if (!employeeId) {
      setErrorMsg('Karyawan wajib dipilih.')
      setSuccessMsg('')
      return
    }
    generate.mutate({
      user_id: employeeId,
      month,
      ...(bonusExtra ? { bonus_extra: Number(bonusExtra) } : {}),
    })
  }

  function submitBulkGenerate() {
    bulkGenerate.mutate({
      month,
      ...(bonusExtra ? { bonus_extra: Number(bonusExtra) } : {}),
    })
  }

  return (
    <div className="admin-page-shell">
      <AdminPageHero
        eyebrow="Finance Workspace"
        title="Payroll Control Center"
        subtitle="Generate, approve, tandai paid, kirim slip, dan ekspor payroll dari satu permukaan kerja."
        variant="finance"
        action={(
          <button className="btn btn-outline-dark btn-sm" onClick={handleExportReport}>
            Export CSV
          </button>
        )}
      />

      <FeedbackAlert type="success" message={successMsg} />
      <FeedbackAlert type="danger" message={errorMsg} />

      <AdminPanelCard
        title="Generate Payroll"
        subtitle="Atur periode payroll dan jalankan generate per karyawan atau bulk."
        className="mb-3"
      >
          <div className="row g-3">
            <div className="col-md-2">
              <label className="form-label">Periode</label>
              <input className="form-control" type="month" value={month} onChange={(e) => { setMonth(e.target.value); setPage(1) }} />
            </div>
            <div className="col-md-3">
              <label className="form-label">Karyawan</label>
              <select className="form-select" value={employeeId} onChange={(e) => setEmployeeId(e.target.value)}>
                <option value="">Pilih untuk generate</option>
                {data?.references.employees.map((employee) => (
                  <option key={employee.id} value={employee.id}>
                    {employee.name}{employee.nip ? ` - ${employee.nip}` : ''}
                  </option>
                ))}
              </select>
            </div>
            <div className="col-md-2">
              <label className="form-label">Bonus Extra</label>
              <input className="form-control" type="number" value={bonusExtra} onChange={(e) => setBonusExtra(e.target.value)} />
            </div>
            <div className="col-md-5 d-flex align-items-end gap-2 admin-form-actions">
              <button className="btn btn-dark btn-sm" onClick={submitGenerate} disabled={generate.isPending}>
                {generate.isPending ? 'Generate...' : 'Generate Terpilih'}
              </button>
              <button className="btn btn-outline-dark btn-sm" onClick={submitBulkGenerate} disabled={bulkGenerate.isPending}>
                {bulkGenerate.isPending ? 'Generate...' : 'Bulk Generate'}
              </button>
            </div>
          </div>
      </AdminPanelCard>

      <AdminPanelCard
        title="Filter & Pencarian"
        subtitle="Persempit payroll berdasarkan identitas karyawan dan status workflow."
        className="mb-3"
      >
          <div className="row g-3">
            <div className="col-md-4">
              <label className="form-label">Cari</label>
              <input
                className="form-control"
                value={search}
                onChange={(e) => { setSearch(e.target.value); setPage(1) }}
                placeholder="Nama, NIP, email"
              />
            </div>
            <div className="col-md-3">
              <label className="form-label">Status</label>
              <select className="form-select" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}>
                <option value="">Semua</option>
                {Object.entries(data?.references.statuses ?? {}).map(([value, label]) => (
                  <option key={value} value={value}>{label}</option>
                ))}
              </select>
            </div>
          </div>
      </AdminPanelCard>

      {data?.summary && (
        <div className="row g-3 mb-3">
          <div className="col-md-3">
            <AdminSummaryCard
              label="Total Gross"
              value={formatCurrency(data.summary.gross_total)}
              meta={`${data.summary.payroll_count} payroll periode ini`}
              variant="navy"
            />
          </div>
          <div className="col-md-3">
            <AdminSummaryCard
              label="Total Potongan"
              value={formatCurrency(data.summary.deduction_total)}
              meta="Akumulasi deduction payroll"
              variant="rose"
            />
          </div>
          <div className="col-md-3">
            <AdminSummaryCard
              label="Total Net Payroll"
              value={formatCurrency(data.summary.net_total)}
              meta="Take home pay periode terpilih"
              variant="emerald"
            />
          </div>
          <div className="col-md-3">
            <AdminSummaryCard
              label="Status Payroll"
              value={`${data.summary.status_counts.draft} Draft · ${data.summary.status_counts.approved} Approved · ${data.summary.status_counts.paid} Paid`}
              meta="Komposisi status periode terpilih"
              variant="amber"
              compactValue
            />
          </div>
        </div>
      )}

      <AdminTableCard
        title="Daftar Payroll"
        subtitle="Kelola lifecycle payroll dari draft sampai paid, termasuk distribusi slip."
        bodyClassName="p-0"
      >
          {isLoading ? (
            <div className="d-flex justify-content-center py-5">
              <div className="spinner-border text-primary spinner-border-sm" role="status" />
            </div>
          ) : isError ? (
            <div className="alert alert-danger feedback-alert feedback-alert--inline mb-0">
              Gagal memuat payroll.
            </div>
          ) : (
            <>
              <div className="table-responsive">
                <table className="table table-hover mb-0 align-middle">
                  <thead>
                    <tr>
                      <th>Karyawan</th>
                      <th>Nomor</th>
                      <th>Periode</th>
                      <th>Attendance</th>
                      <th>Net</th>
                      <th>Status</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    {data?.data.length === 0 && (
                      <tr><td colSpan={7} className="text-center text-muted py-4">Belum ada payroll pada periode ini.</td></tr>
                    )}
                    {data?.data.map((payroll) => (
                      <tr key={payroll.id}>
                        <td>
                          <div className="fw-medium">{payroll.user?.name ?? '-'}</div>
                          <small className="text-muted">{payroll.user?.division ?? '-'} · {payroll.user?.job_title ?? '-'}</small>
                        </td>
                        <td>{payroll.payroll_number}</td>
                        <td>
                          <div>{payroll.period.month}/{payroll.period.year}</div>
                          <small className="text-muted">{payroll.period.label}</small>
                        </td>
                        <td>{payroll.attendance_count} hari · {payroll.attendance_percentage}%</td>
                        <td>{formatCurrency(payroll.net_total)}</td>
                        <td>
                          <span className="badge bg-light text-dark">{data?.references.statuses[payroll.status] ?? payroll.status}</span>
                        </td>
                        <td className="text-end">
                          <div className="d-flex justify-content-end gap-2 admin-table-actions">
                            {payroll.status === 'draft' && (
                              <button className="btn btn-outline-dark btn-sm" onClick={() => approve.mutate(payroll.id)} disabled={approve.isPending}>
                                Approve
                              </button>
                            )}
                            {(payroll.status === 'approved' || payroll.status === 'paid') && (
                              <>
                                {payroll.status === 'approved' && (
                                  <button
                                    className="btn btn-outline-success btn-sm"
                                    onClick={() => markPaid.mutate(payroll.id)}
                                    disabled={markPaid.isPending}
                                  >
                                    Mark Paid
                                  </button>
                                )}
                                <button
                                  className="btn btn-outline-secondary btn-sm"
                                  onClick={() => handleDownloadSlip(payroll.id, payroll.payroll_number)}
                                >
                                  Download Slip
                                </button>
                                <button
                                  className="btn btn-dark btn-sm"
                                  onClick={() => emailSlip.mutate(payroll.id)}
                                  disabled={emailSlip.isPending}
                                >
                                  Email Slip
                                </button>
                              </>
                            )}
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {data?.meta && (
                <div className="px-3 pb-3">
                  <Pagination meta={data.meta} onPageChange={setPage} />
                </div>
              )}
            </>
          )}
      </AdminTableCard>
    </div>
  )
}
