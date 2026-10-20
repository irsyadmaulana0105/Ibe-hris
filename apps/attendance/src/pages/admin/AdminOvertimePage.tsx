import { usePageTitle } from '@/hooks/usePageTitle'
import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { adminListOvertimes, adminApproveOvertime, adminRejectOvertime } from '@/api/admin'
import AdminPageHero from '@/components/admin/AdminPageHero'
import AdminSummaryCard from '@/components/admin/AdminSummaryCard'
import AdminTableCard from '@/components/admin/AdminTableCard'
import StatusBadge from '@/components/StatusBadge'
import Pagination from '@/components/Pagination'
import ApprovalModal from '@/components/ApprovalModal'
import type { Overtime } from '@/types'

export default function AdminOvertimePage() {
  usePageTitle('Approval Lembur')
  const qc = useQueryClient()
  const [page, setPage] = useState(1)
  const [statusFilter, setStatusFilter] = useState('pending')
  const [selected, setSelected] = useState<{ item: Overtime; action: 'approve' | 'reject' } | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['admin-overtimes', page, statusFilter],
    queryFn: () => adminListOvertimes({ status: statusFilter, page: String(page) }),
  })

  const approveMutation = useMutation({
    mutationFn: ({ id, notes }: { id: number; notes: string }) => adminApproveOvertime(id, notes),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['admin-overtimes'] }); setSelected(null) },
  })

  const rejectMutation = useMutation({
    mutationFn: ({ id, notes }: { id: number; notes: string }) => adminRejectOvertime(id, notes),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['admin-overtimes'] }); setSelected(null) },
  })

  function handleConfirm(notes: string) {
    if (!selected) return
    if (selected.action === 'approve') {
      approveMutation.mutate({ id: selected.item.id, notes })
    } else {
      rejectMutation.mutate({ id: selected.item.id, notes })
    }
  }

  return (
    <div className="admin-page-shell">
      <AdminPageHero
        eyebrow="Workforce Workspace"
        title="Overtime Approval Desk"
        subtitle="Pantau backlog lembur, komposisi approval, dan durasi rata-rata lembur dalam satu permukaan kerja."
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
              meta={`${data.summary.total_hours} jam total lembur`}
              variant="navy"
            />
          </div>
          <div className="col-md-3">
            <AdminSummaryCard
              label="Rata-rata Durasi"
              value={`${data.summary.average_hours} jam`}
              meta="Rata-rata per pengajuan"
              variant="emerald"
            />
          </div>
          <div className="col-md-3">
            <AdminSummaryCard
              label="Status Approval"
              value={`${data.summary.status_counts.pending} Pending · ${data.summary.status_counts.approved} Approved · ${data.summary.status_counts.rejected} Rejected`}
              meta="Komposisi approval lembur"
              variant="amber"
              compactValue
            />
          </div>
          <div className="col-md-3">
            <AdminSummaryCard
              label="Backlog"
              value={String(data.pending_count ?? 0)}
              meta="Pengajuan masih menunggu keputusan"
              variant="rose"
            />
          </div>
        </div>
      )}

      <AdminTableCard
        title="Daftar Pengajuan Lembur"
        subtitle={`${data?.pending_count ?? 0} pengajuan masih menunggu persetujuan.`}
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
                        <td colSpan={6} className="text-center text-muted py-4">Tidak ada data.</td>
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
                            <div className="d-flex justify-content-end gap-1">
                              <button onClick={() => setSelected({ item: ot, action: 'approve' })} className="btn btn-success btn-sm">Setujui</button>
                              <button onClick={() => setSelected({ item: ot, action: 'reject' })} className="btn btn-danger btn-sm">Tolak</button>
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
          title={selected.action === 'approve' ? 'Setujui Lembur' : 'Tolak Lembur'}
          action={selected.action}
          onConfirm={handleConfirm}
          onClose={() => setSelected(null)}
          isPending={approveMutation.isPending || rejectMutation.isPending}
        />
      )}
    </div>
  )
}
