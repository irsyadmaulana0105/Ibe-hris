import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { adminListAuditLogs } from '@/api/admin'
import AdminPageHero from '@/components/admin/AdminPageHero'
import AdminPanelCard from '@/components/admin/AdminPanelCard'
import AdminTableCard from '@/components/admin/AdminTableCard'
import EmptyState from '@/components/EmptyState'
import Pagination from '@/components/Pagination'
import { usePageTitle } from '@/hooks/usePageTitle'

function formatDateTime(value: string) {
  return new Intl.DateTimeFormat('id-ID', {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(value))
}

const ACTION_OPTIONS = [
  { value: '', label: 'Semua Aksi' },
  { value: 'company.updated', label: 'Update Perusahaan' },
  { value: 'leave.approved', label: 'Cuti Disetujui' },
  { value: 'leave.rejected', label: 'Cuti Ditolak' },
  { value: 'overtime.approved', label: 'Lembur Disetujui' },
  { value: 'overtime.rejected', label: 'Lembur Ditolak' },
  { value: 'salary_advance.approved', label: 'Kasbon Disetujui' },
  { value: 'salary_advance.rejected', label: 'Kasbon Ditolak' },
  { value: 'reimbursement.approved', label: 'Reimburse Disetujui' },
  { value: 'reimbursement.rejected', label: 'Reimburse Ditolak' },
  { value: 'face_approval.approved', label: 'Wajah Disetujui' },
  { value: 'face_approval.rejected', label: 'Wajah Ditolak' },
  { value: 'employee.created', label: 'Karyawan Dibuat' },
  { value: 'employee.updated', label: 'Karyawan Diubah' },
  { value: 'employee.deleted', label: 'Karyawan Dihapus' },
  { value: 'employee.salary_updated', label: 'Gaji Pokok Diubah' },
  { value: 'payroll.generated', label: 'Payroll Dibuat' },
  { value: 'payroll.bulk_generated', label: 'Payroll Bulk Dibuat' },
  { value: 'payroll.approved', label: 'Payroll Disetujui' },
  { value: 'payroll.email_queued', label: 'Email Slip Diantrikan' },
]

const ENTITY_OPTIONS = [
  { value: '', label: 'Semua Entitas' },
  { value: 'company', label: 'Company' },
  { value: 'leave', label: 'Leave' },
  { value: 'overtime', label: 'Overtime' },
  { value: 'salary_advance', label: 'Salary Advance' },
  { value: 'reimbursement', label: 'Reimbursement' },
  { value: 'payroll', label: 'Payroll' },
  { value: 'user', label: 'User' },
]

export default function AdminAuditLogPage() {
  usePageTitle('Audit Log')
  const [page, setPage] = useState(1)
  const [action, setAction] = useState('')
  const [entityType, setEntityType] = useState('')

  const { data, isLoading, isError } = useQuery({
    queryKey: ['admin', 'audit-logs', page, action, entityType],
    queryFn: () => adminListAuditLogs({
      page: String(page),
      ...(action ? { action } : {}),
      ...(entityType ? { entity_type: entityType } : {}),
    }),
  })

  return (
    <div className="admin-page-shell">
      <AdminPageHero
        eyebrow="Compliance Workspace"
        title="Audit Log"
        subtitle="Jejak aktivitas aksi sensitif di level admin dan sistem untuk kebutuhan review dan kontrol perubahan."
        variant="employee"
      />

      <AdminPanelCard
        title="Filter Audit"
        subtitle="Persempit audit log berdasarkan jenis aksi dan entitas yang berubah."
        className="mb-3"
      >
          <div className="row g-3">
            <div className="col-md-6">
              <label className="form-label">Filter Aksi</label>
              <select
                className="form-select"
                value={action}
                onChange={(e) => { setAction(e.target.value); setPage(1) }}
              >
                {ACTION_OPTIONS.map((option) => (
                  <option key={option.value} value={option.value}>{option.label}</option>
                ))}
              </select>
            </div>
            <div className="col-md-6">
              <label className="form-label">Filter Entitas</label>
              <select
                className="form-select"
                value={entityType}
                onChange={(e) => { setEntityType(e.target.value); setPage(1) }}
              >
                {ENTITY_OPTIONS.map((option) => (
                  <option key={option.value} value={option.value}>{option.label}</option>
                ))}
              </select>
            </div>
          </div>
      </AdminPanelCard>

      <AdminTableCard
        title="Daftar Audit Log"
        subtitle={`${data?.meta?.total ?? 0} log ditemukan pada filter saat ini.`}
        bodyClassName="p-0"
      >
          {isLoading ? (
            <div className="d-flex justify-content-center py-5">
              <div className="spinner-border text-primary spinner-border-sm" role="status" />
            </div>
          ) : isError ? (
            <div className="alert alert-danger feedback-alert feedback-alert--inline mb-0">
              Gagal memuat audit log.
            </div>
          ) : data && data.data.length === 0 ? (
            <EmptyState
              type="search"
              title="Tidak ada audit log"
              subtitle="Belum ada data yang cocok dengan filter saat ini."
            />
          ) : (
            <>
              <div className="table-responsive audit-log-table">
                <table className="table table-hover mb-0">
                  <thead>
                    <tr>
                      <th>Waktu</th>
                      <th>Aksi</th>
                      <th>Aktor</th>
                      <th>Entitas</th>
                      <th>Detail</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data?.data.map((log) => (
                      <tr key={log.id}>
                        <td style={{ whiteSpace: 'nowrap' }}>{formatDateTime(log.created_at)}</td>
                        <td>
                          <code style={{ fontSize: 12 }}>{log.action}</code>
                        </td>
                        <td>
                          {log.actor ? (
                            <>
                              <div className="fw-medium">{log.actor.name}</div>
                              <small className="text-muted">{log.actor.group}</small>
                            </>
                          ) : (
                            <span className="text-muted">Sistem</span>
                          )}
                        </td>
                        <td>
                          <div className="fw-medium">{log.entity_type ?? '-'}</div>
                          <small className="text-muted">{log.entity_id ?? '-'}</small>
                        </td>
                        <td className="audit-log-table__detail">
                          <pre
                            className="mb-0 audit-log-table__meta"
                            style={{
                              whiteSpace: 'pre-wrap',
                              wordBreak: 'break-word',
                            }}
                          >
                            {log.meta ? JSON.stringify(log.meta, null, 2) : '-'}
                          </pre>
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
