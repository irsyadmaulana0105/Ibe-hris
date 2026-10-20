import { usePageTitle } from '@/hooks/usePageTitle'
import { useEffect, useState } from 'react'
import { getDashboard } from '@/api/dashboard'
import { useAuth } from '@/context/AuthContext'
import type { DashboardData } from '@/types'
import { Link } from 'react-router-dom'

const statusLabel: Record<string, string> = {
  pending:  'Menunggu',
  approved: 'Disetujui',
  rejected: 'Ditolak',
}

const statusBadge: Record<string, string> = {
  pending:  'bg-warning text-dark',
  approved: 'bg-success',
  rejected: 'bg-danger',
}

function formatCurrency(value: number) {
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
  }).format(value)
}

export default function DashboardPage() {
  usePageTitle('Beranda')
  const { user } = useAuth()
  const [data, setData] = useState<DashboardData | null>(null)
  const [isLoading, setIsLoading] = useState(true)

  useEffect(() => {
    getDashboard()
      .then(setData)
      .finally(() => setIsLoading(false))
  }, [])

  if (isLoading) {
    return (
      <div className="d-flex align-items-center justify-content-center" style={{ minHeight: 200 }}>
        <div className="spinner-border text-primary" role="status" />
      </div>
    )
  }

  if (!data) {
    return (
      <div className="alert alert-danger">Gagal memuat data dashboard.</div>
    )
  }

  const today = new Date().toLocaleDateString('id-ID', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })
  const capabilities = user?.permissions?.capabilities ?? []
  const can = (capability: string) => capabilities.includes(capability)
  const adminCards = [
    {
      label: 'Approval Cuti',
      value: data.admin_summary?.pending_leave_count,
      to: '/admin/leaves',
      visible: can('leave.manage'),
      accent: 'linear-gradient(135deg,#ff8a00,#e76f00)',
    },
    {
      label: 'Approval Lembur',
      value: data.admin_summary?.pending_overtime_count,
      to: '/admin/overtimes',
      visible: can('overtime.manage'),
      accent: 'linear-gradient(135deg,#00a8cc,#007d99)',
    },
    {
      label: 'Approval Kasbon',
      value: data.admin_summary?.pending_salary_advance_count,
      to: '/admin/salary-advances',
      visible: can('salary_advance.manage'),
      accent: 'linear-gradient(135deg,#4caf50,#2e7d32)',
    },
    {
      label: 'Approval Reimburse',
      value: data.admin_summary?.pending_reimbursement_count,
      to: '/admin/reimbursements',
      visible: can('reimbursement.manage'),
      accent: 'linear-gradient(135deg,#ab47bc,#7b1fa2)',
    },
    {
      label: 'Approval Wajah',
      value: data.admin_summary?.pending_face_approval_count,
      to: '/admin/face-approvals',
      visible: can('face_approval.manage'),
      accent: 'linear-gradient(135deg,#ef5350,#c62828)',
    },
  ].filter((card) => card.visible)

  return (
    <div className="dashboard-shell">
      <section className="dashboard-hero">
        <div className="dashboard-hero__content">
          <span className="dashboard-eyebrow">ERP Workspace</span>
          <h1 className="dashboard-hero__title">Selamat datang, {user?.name}</h1>
          <p className="dashboard-hero__subtitle">
            Pantau operasi harian, approval queue, dan performa HR/Finance dari satu permukaan kerja.
          </p>
        </div>
        <div className="dashboard-hero__meta">
          <div className="dashboard-chip">
            <span className="dashboard-chip__label">Hari Ini</span>
            <strong>{today}</strong>
          </div>
          <div className="dashboard-chip dashboard-chip--accent">
            <span className="dashboard-chip__label">Workspace</span>
            <strong>{user?.permissions?.is_admin ? 'Admin + Employee' : 'Employee'}</strong>
          </div>
        </div>
      </section>

      {/* Stat cards - role-aware: magang hanya lihat Status Hari Ini (1 kolom penuh).
          Permanent karyawan lihat 4 kartu lengkap. */}
      <div className="row g-3 mb-4">
        <div className={user?.is_permanent ? 'col-6 col-md-3' : 'col-12'}>
          <div className="dashboard-stat-card dashboard-stat-card--gold">
            <div className="dashboard-stat-card__icon">✅</div>
            <div className="dashboard-stat-card__value">{data.attendance ? 'Hadir' : '-'}</div>
            <div className="dashboard-stat-card__label">Status Hari Ini</div>
          </div>
        </div>
        {user?.is_permanent && (
          <>
            <div className="col-6 col-md-3">
              <div className="dashboard-stat-card dashboard-stat-card--teal">
                <div className="dashboard-stat-card__icon">📅</div>
                <div className="dashboard-stat-card__value">{data.annual_balance}</div>
                <div className="dashboard-stat-card__label">Sisa Cuti Tahunan</div>
              </div>
            </div>
            <div className="col-6 col-md-3">
              <div className="dashboard-stat-card dashboard-stat-card--rose">
                <div className="dashboard-stat-card__icon">📋</div>
                <div className="dashboard-stat-card__value">{data.pending_leave_count}</div>
                <div className="dashboard-stat-card__label">Cuti Pending</div>
              </div>
            </div>
            <div className="col-6 col-md-3">
              <div className="dashboard-stat-card dashboard-stat-card--green">
                <div className="dashboard-stat-card__icon">⏰</div>
                <div className="dashboard-stat-card__value">{data.pending_overtime_count}</div>
                <div className="dashboard-stat-card__label">Lembur Pending</div>
              </div>
            </div>
          </>
        )}
      </div>

      {/* Feature cards - Slip Gaji disembunyikan untuk magang (tidak punya payroll). */}
      <div className="row g-3 mb-4">
        <div className={user?.is_permanent ? 'col-md-6' : 'col-12'}>
          <Link to="/attendance/history" className="dashboard-feature dashboard-feature--teal text-decoration-none">
            <div className="dashboard-feature__body">
              <div className="dashboard-feature__eyebrow">Attendance</div>
              <h5 className="dashboard-feature__title">Kalender Kehadiran</h5>
              <p className="dashboard-feature__text">
                Lihat history absensi bulanan dan buka detail tiap tanggal langsung dari kalender.
              </p>
            </div>
            <div className="dashboard-feature__arrow">↗</div>
          </Link>
        </div>
        {user?.is_permanent && (
          <div className="col-md-6">
            <Link to="/payslips" className="dashboard-feature dashboard-feature--blue text-decoration-none">
              <div className="dashboard-feature__body">
                <div className="dashboard-feature__eyebrow">Payroll</div>
                <h5 className="dashboard-feature__title">Slip Gaji Saya</h5>
                <p className="dashboard-feature__text">
                  Buka detail slip gaji yang sudah approved dan unduh PDF langsung dari portal karyawan.
                </p>
              </div>
              <div className="dashboard-feature__arrow">↗</div>
            </Link>
          </div>
        )}
      </div>

      {adminCards.length > 0 && (
        <section className="dashboard-admin-queue">
          <div className="dashboard-admin-queue__header">
            <div>
              <div className="dashboard-eyebrow dashboard-eyebrow--light">Admin Overview</div>
              <h5 className="dashboard-admin-queue__title">Approval Queue</h5>
              <p className="dashboard-admin-queue__text">
                Ringkasan antrean persetujuan lintas modul untuk membantu operasional harian.
              </p>
            </div>
            {can('audit_logs.view') && (
              <Link to="/admin/audit-logs" className="btn btn-sm btn-light dashboard-admin-queue__action">
                Lihat Audit Log
              </Link>
            )}
          </div>
        <div className="row g-3">
          {adminCards.map((card) => (
            <div className="col-6 col-lg" key={card.label}>
              <Link
                to={card.to}
                className="dashboard-queue-card text-decoration-none"
                style={{ ['--queue-accent' as any]: card.accent }}
              >
                <div className="dashboard-queue-card__label">{card.label}</div>
                <div className="dashboard-queue-card__value">{card.value ?? 0}</div>
                <div className="dashboard-queue-card__hint">Buka modul</div>
              </Link>
            </div>
          ))}
        </div>
      </section>
      )}

      {data.admin_reporting && (
        <section className="dashboard-erp-snapshot">
          <div className="dashboard-erp-snapshot__header">
            <div>
              <div className="dashboard-eyebrow">ERP Snapshot</div>
              <h5 className="dashboard-erp-snapshot__title">HR & Finance Overview</h5>
              <p className="dashboard-erp-snapshot__text">
                Ringkasan lintas modul untuk periode {data.admin_reporting.period.label}.
              </p>
            </div>
          </div>

          <div className="row g-3 mb-3">
            {data.admin_reporting.attendance_today && (
              <div className="col-md-3">
                <Link to="/admin/attendances" className="dashboard-metric-card dashboard-metric-card--emerald text-decoration-none">
                  <div className="dashboard-metric-card__label">Attendance Today</div>
                  <div className="dashboard-metric-card__value">
                    {data.admin_reporting.attendance_today.checked_in}/{data.admin_reporting.attendance_today.total_employees}
                  </div>
                  <div className="dashboard-metric-card__meta">
                    Late {data.admin_reporting.attendance_today.late} · Sick {data.admin_reporting.attendance_today.sick} · Missing {data.admin_reporting.attendance_today.absent_or_missing}
                  </div>
                  <div className="dashboard-metric-card__hint">Buka modul</div>
                </Link>
              </div>
            )}

            {data.admin_reporting.payroll && (
              <div className="col-md-3">
                <Link to="/admin/payrolls" className="dashboard-metric-card dashboard-metric-card--blue text-decoration-none">
                  <div className="dashboard-metric-card__label">Payroll</div>
                  <div className="dashboard-metric-card__value dashboard-metric-card__value--currency">
                    {formatCurrency(data.admin_reporting.payroll.net_total)}
                  </div>
                  <div className="dashboard-metric-card__meta">
                    {data.admin_reporting.payroll.payroll_count} payroll · {data.admin_reporting.payroll.status_counts.approved} approved · {data.admin_reporting.payroll.status_counts.paid} paid
                  </div>
                  <div className="dashboard-metric-card__hint">Buka modul</div>
                </Link>
              </div>
            )}

            {data.admin_reporting.leave && (
              <div className="col-md-3">
                <Link to="/admin/leaves" className="dashboard-metric-card dashboard-metric-card--orange text-decoration-none">
                  <div className="dashboard-metric-card__label">Leave</div>
                  <div className="dashboard-metric-card__value">
                    {data.admin_reporting.leave.total_requests}
                  </div>
                  <div className="dashboard-metric-card__meta">
                    {data.admin_reporting.leave.total_days} hari · {data.admin_reporting.leave.status_counts.pending} pending
                  </div>
                  <div className="dashboard-metric-card__hint">Buka modul</div>
                </Link>
              </div>
            )}

            {data.admin_reporting.overtime && (
              <div className="col-md-3">
                <Link to="/admin/overtimes" className="dashboard-metric-card dashboard-metric-card--violet text-decoration-none">
                  <div className="dashboard-metric-card__label">Overtime</div>
                  <div className="dashboard-metric-card__value">
                    {data.admin_reporting.overtime.total_hours} jam
                  </div>
                  <div className="dashboard-metric-card__meta">
                    {data.admin_reporting.overtime.total_requests} request · avg {data.admin_reporting.overtime.average_hours} jam
                  </div>
                  <div className="dashboard-metric-card__hint">Buka modul</div>
                </Link>
              </div>
            )}
          </div>

          {data.admin_reporting.reimbursement && (
            <div className="row g-3">
              <div className="col-md-6">
                <Link to="/admin/reimbursements" className="dashboard-finance-panel text-decoration-none">
                  <div className="dashboard-finance-panel__top">
                    <div>
                      <div className="dashboard-finance-panel__label">Reimbursement</div>
                      <div className="dashboard-finance-panel__value">{formatCurrency(data.admin_reporting.reimbursement.requested_total)}</div>
                      <div className="dashboard-finance-panel__sub">
                        Requested total · approved {formatCurrency(data.admin_reporting.reimbursement.approved_total)}
                      </div>
                    </div>
                    <div className="dashboard-finance-panel__side">
                      <div className="dashboard-finance-panel__status">
                        {data.admin_reporting.reimbursement.status_counts.pending} pending · {data.admin_reporting.reimbursement.status_counts.approved} approved
                      </div>
                      <div className="dashboard-finance-panel__sub">
                        {data.admin_reporting.reimbursement.total_requests} pengajuan · avg {formatCurrency(data.admin_reporting.reimbursement.average_request)}
                      </div>
                      <div className="dashboard-finance-panel__hint">Buka modul</div>
                    </div>
                  </div>
                </Link>
              </div>

              {data.admin_reporting.salary_advance && (
                <div className="col-md-6">
                  <Link to="/admin/salary-advances" className="dashboard-finance-panel text-decoration-none">
                    <div className="dashboard-finance-panel__top">
                      <div>
                        <div className="dashboard-finance-panel__label">Salary Advance</div>
                        <div className="dashboard-finance-panel__value">{formatCurrency(data.admin_reporting.salary_advance.requested_total)}</div>
                        <div className="dashboard-finance-panel__sub">
                          {data.admin_reporting.salary_advance.total_requests} pengajuan · avg {formatCurrency(data.admin_reporting.salary_advance.average_request)}
                        </div>
                      </div>
                      <div className="dashboard-finance-panel__side">
                        <div className="dashboard-finance-panel__status">
                          {data.admin_reporting.salary_advance.status_counts.pending} pending · {data.admin_reporting.salary_advance.status_counts.approved} approved
                        </div>
                        <div className="dashboard-finance-panel__sub">
                          {data.admin_reporting.salary_advance.status_counts.rejected} rejected bulan ini
                        </div>
                        <div className="dashboard-finance-panel__hint">Buka modul</div>
                      </div>
                    </div>
                  </Link>
                </div>
              )}
            </div>
          )}
        </section>
      )}

      <div className="row g-3">
        {/* Today's attendance - magang: full width, permanent: 5/12 (paired dengan Cuti Terbaru) */}
        <div className={user?.is_permanent ? 'col-md-5' : 'col-12'}>
          <div className="card">
            <div className="card-header">
              <span>Kehadiran Hari Ini</span>
            </div>
            <div className="card-body">
              {data.attendance ? (
                <div>
                  <div className="d-flex justify-content-between mb-3">
                    <span className="text-muted" style={{ fontSize: 13 }}>Status</span>
                    <span className={`badge ${statusBadge[data.attendance.status] ?? 'bg-secondary'}`}>
                      {statusLabel[data.attendance.status] ?? data.attendance.status}
                    </span>
                  </div>
                  <div className="d-flex justify-content-between mb-2">
                    <span className="text-muted" style={{ fontSize: 13 }}>Jam Masuk</span>
                    <strong style={{ fontSize: 13 }}>{data.attendance.time_in ?? '-'}</strong>
                  </div>
                  <div className="d-flex justify-content-between mb-2">
                    <span className="text-muted" style={{ fontSize: 13 }}>Jam Keluar</span>
                    <strong style={{ fontSize: 13 }}>{data.attendance.time_out ?? '-'}</strong>
                  </div>
                  {data.shift && (
                    <div className="d-flex justify-content-between">
                      <span className="text-muted" style={{ fontSize: 13 }}>Shift</span>
                      <span style={{ fontSize: 13 }}>{data.shift.name} ({data.shift.start_time}–{data.shift.end_time})</span>
                    </div>
                  )}
                </div>
              ) : (
                <div className="text-center py-3">
                  <p className="text-muted mb-0" style={{ fontSize: 13 }}>Belum absen hari ini.</p>
                </div>
              )}
            </div>
          </div>
        </div>

        {/* Recent leaves - magang tidak punya cuti, sembunyikan widget */}
        {user?.is_permanent && (
        <div className="col-md-7">
          <div className="card">
            <div className="card-header">
              <span>Cuti Terbaru</span>
            </div>
            <div className="card-body p-0">
              {data.recent_leaves.length === 0 ? (
                <div className="text-center py-4">
                  <p className="text-muted mb-0" style={{ fontSize: 13 }}>Belum ada riwayat cuti.</p>
                </div>
              ) : (
                <div className="table-responsive">
                  <table className="table table-hover mb-0">
                    <thead>
                      <tr>
                        <th>Jenis</th>
                        <th>Tanggal</th>
                        <th>Status</th>
                      </tr>
                    </thead>
                    <tbody>
                      {data.recent_leaves.map((leave) => (
                        <tr key={leave.id}>
                          <td>{leave.leave_type.label}</td>
                          <td>
                            {leave.start_date === leave.end_date
                              ? leave.start_date
                              : `${leave.start_date} – ${leave.end_date}`}
                          </td>
                          <td>
                            <span className={`badge ${statusBadge[leave.status] ?? 'bg-secondary'}`}>
                              {statusLabel[leave.status] ?? leave.status}
                            </span>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          </div>
        </div>
        )}
      </div>

      {can('audit_logs.view') && (
        <div className="card mt-4">
          <div className="card-header d-flex align-items-center justify-content-between">
            <span>Aktivitas Audit Terbaru</span>
            <Link to="/admin/audit-logs" style={{ fontSize: 12, textDecoration: 'none' }}>
              Buka semua
            </Link>
          </div>
          <div className="card-body p-0">
            {data.recent_audit_logs.length === 0 ? (
              <div className="text-center py-4">
                <p className="text-muted mb-0" style={{ fontSize: 13 }}>Belum ada audit log untuk perusahaan ini.</p>
              </div>
            ) : (
              <div className="table-responsive">
                <table className="table table-hover mb-0">
                  <thead>
                    <tr>
                      <th>Waktu</th>
                      <th>Aksi</th>
                      <th>Aktor</th>
                      <th>Entitas</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.recent_audit_logs.map((log) => (
                      <tr key={log.id}>
                        <td style={{ whiteSpace: 'nowrap' }}>
                          {new Intl.DateTimeFormat('id-ID', {
                            dateStyle: 'medium',
                            timeStyle: 'short',
                          }).format(new Date(log.created_at))}
                        </td>
                        <td><code style={{ fontSize: 12 }}>{log.action}</code></td>
                        <td>{log.actor?.name ?? 'Sistem'}</td>
                        <td>
                          {log.entity_type ?? '-'}
                          {log.entity_id ? (
                            <span className="text-muted"> #{log.entity_id}</span>
                          ) : null}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  )
}
