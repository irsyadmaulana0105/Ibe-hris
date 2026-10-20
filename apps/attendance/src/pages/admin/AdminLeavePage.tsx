import { usePageTitle } from '@/hooks/usePageTitle'
import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { adminListLeaves, adminApproveLeave, adminRejectLeave } from '@/api/admin'
import AdminPageHero from '@/components/admin/AdminPageHero'
import AdminSummaryCard from '@/components/admin/AdminSummaryCard'
import AdminTableCard from '@/components/admin/AdminTableCard'
import StatusBadge from '@/components/StatusBadge'
import Pagination from '@/components/Pagination'
import ApprovalModal from '@/components/ApprovalModal'
import type { Leave } from '@/types'

export default function AdminLeavePage() {
  usePageTitle('Approval Cuti')
  const qc = useQueryClient()
  const [page, setPage] = useState(1)
  const [statusFilter, setStatusFilter] = useState('pending')
  const [selected, setSelected] = useState<{ leave: Leave; action: 'approve' | 'reject' } | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['admin-leaves', page, statusFilter],
    queryFn: () => adminListLeaves({ status: statusFilter, page: String(page) }),
  })

  const approveMutation = useMutation({
    mutationFn: ({ id, notes }: { id: number; notes: string }) => adminApproveLeave(id, notes),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['admin-leaves'] }); setSelected(null) },
  })

  const rejectMutation = useMutation({
    mutationFn: ({ id, notes }: { id: number; notes: string }) => adminRejectLeave(id, notes),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['admin-leaves'] }); setSelected(null) },
  })

  function handleConfirm(notes: string) {
    if (!selected) return
    if (selected.action === 'approve') {
      approveMutation.mutate({ id: selected.leave.id, notes })
    } else {
      rejectMutation.mutate({ id: selected.leave.id, notes })
    }
  }

  return (
    <div className="admin-page-shell">
      <AdminPageHero
        eyebrow="Workforce Workspace"
        title="Leave Approval Desk"
        subtitle="Kelola approval cuti, pantau volume pengajuan, dan lihat komposisi jenis cuti dari satu workspace."
        variant="workforce"
        action={(
          <select
            className="form-select form-select-sm"
            style={{ width: 'auto' }}
            value={statusFilter}
            onChange={(e) => { setStatusFilter(e.target.value); setPage(1) }}
          >
            <option value="pending">Menunggu</option>
            <option value="approved">Disetujui</option>
            <option value="rejected">Ditolak</option>
            <option value="all">Semua</option>
          </select>
        )}
      />

      {data?.summary && (
        <div className="row g-3 mb-3">
          <div className="col-md-3">
            <AdminSummaryCard
              label="Total Pengajuan"
              value={String(data.summary.total_requests)}
              meta={`${data.summary.total_days} hari cuti diajukan`}
              variant="navy"
            />
          </div>
          <div className="col-md-3">
            <AdminSummaryCard
              label="Status"
              value={`${data.summary.status_counts.pending} Pending · ${data.summary.status_counts.approved} Approved · ${data.summary.status_counts.rejected} Rejected`}
              meta="Ringkasan approval periode aktif"
              variant="amber"
              compactValue
            />
          </div>
          <div className="col-md-3">
            <AdminSummaryCard
              label="Jenis Utama"
              value={`${data.summary.type_counts.annual} Annual · ${data.summary.type_counts.sick} Sick`}
              meta="Jenis cuti yang paling sering dipakai"
              variant="emerald"
              compactValue
            />
          </div>
          <div className="col-md-3">
            <AdminSummaryCard
              label="Jenis Lain"
              value={`${data.summary.type_counts.personal} Personal · ${data.summary.type_counts.medical_checkup} MCU · ${data.summary.type_counts.other} Other`}
              meta="Komposisi pengajuan non-utama"
              variant="rose"
              compactValue
            />
          </div>
        </div>
      )}

      <AdminTableCard
        title="Daftar Pengajuan Cuti"
        subtitle={`${data?.meta.total ?? 0} pengajuan pada filter saat ini.`}
        bodyClassName="p-0"
      >
          {isLoading ? (
            <div className="d-flex justify-content-center py-5">
              <div className="spinner-border text-primary spinner-border-sm" role="status" />
            </div>
          ) : (
            <>
              <div className="table-responsive">
                <table className="table table-hover mb-0">
                  <thead>
                    <tr>
                      <th>Karyawan</th>
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
                        <td colSpan={6} className="text-center text-muted py-4">Tidak ada data.</td>
                      </tr>
                    )}
                    {data?.data.map((leave) => (
                      <tr key={leave.id}>
                        <td>
                          <p className="mb-0 fw-medium">{leave.user?.name ?? '-'}</p>
                          {leave.user?.division && <small className="text-muted">{leave.user.division}</small>}
                        </td>
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
                            <div className="d-flex justify-content-end gap-1">
                              <button
                                onClick={() => setSelected({ leave, action: 'approve' })}
                                className="btn btn-success btn-sm"
                              >
                                Setujui
                              </button>
                              <button
                                onClick={() => setSelected({ leave, action: 'reject' })}
                                className="btn btn-danger btn-sm"
                              >
                                Tolak
                              </button>
                            </div>
                          )}
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

      {selected && (
        <ApprovalModal
          title={selected.action === 'approve' ? 'Setujui Cuti' : 'Tolak Cuti'}
          action={selected.action}
          onConfirm={handleConfirm}
          onClose={() => setSelected(null)}
          isPending={approveMutation.isPending || rejectMutation.isPending}
        />
      )}
    </div>
  )
}
