import { usePageTitle } from '@/hooks/usePageTitle'
import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { adminListSalaryAdvances, adminApproveSalaryAdvance, adminRejectSalaryAdvance } from '@/api/admin'
import AdminPageHero from '@/components/admin/AdminPageHero'
import AdminTableCard from '@/components/admin/AdminTableCard'
import StatusBadge from '@/components/StatusBadge'
import Pagination from '@/components/Pagination'
import ApprovalModal from '@/components/ApprovalModal'
import type { SalaryAdvance } from '@/types'

function formatRp(amount: number) {
  return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(amount)
}

export default function AdminSalaryAdvancePage() {
  usePageTitle('Approval Kasbon')
  const qc = useQueryClient()
  const [page, setPage] = useState(1)
  const [statusFilter, setStatusFilter] = useState('pending')
  const [selected, setSelected] = useState<{ item: SalaryAdvance; action: 'approve' | 'reject' } | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['admin-salary-advances', page, statusFilter],
    queryFn: () => adminListSalaryAdvances({ status: statusFilter, page: String(page) }),
  })

  const approveMutation = useMutation({
    mutationFn: ({ id, notes }: { id: number; notes: string }) => adminApproveSalaryAdvance(id, notes),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['admin-salary-advances'] }); setSelected(null) },
  })

  const rejectMutation = useMutation({
    mutationFn: ({ id, notes }: { id: number; notes: string }) => adminRejectSalaryAdvance(id, notes),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['admin-salary-advances'] }); setSelected(null) },
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
        eyebrow="Finance Workspace"
        title="Salary Advance Approval Desk"
        subtitle="Kelola approval kasbon karyawan dengan backlog status yang lebih rapi dan konsisten dengan workspace finance."
        variant="finance"
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

      <AdminTableCard
        title="Daftar Kasbon"
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
                      <th>Karyawan</th>
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
                        <td colSpan={6} className="text-center text-muted py-4">Tidak ada data.</td>
                      </tr>
                    )}
                    {data?.data.map((sa) => (
                      <tr key={sa.id}>
                        <td className="fw-medium">{sa.user?.name ?? '-'}</td>
                        <td>{formatRp(sa.amount)}</td>
                        <td style={{ maxWidth: 200, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }} className="text-muted">{sa.purpose}</td>
                        <td className="text-muted">{sa.created_at.slice(0, 10)}</td>
                        <td><StatusBadge status={sa.status} /></td>
                        <td className="text-end">
                          {sa.status === 'pending' && (
                            <div className="d-flex justify-content-end gap-1">
                              <button onClick={() => setSelected({ item: sa, action: 'approve' })} className="btn btn-success btn-sm">Setujui</button>
                              <button onClick={() => setSelected({ item: sa, action: 'reject' })} className="btn btn-danger btn-sm">Tolak</button>
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
          title={selected.action === 'approve' ? 'Setujui Kasbon' : 'Tolak Kasbon'}
          action={selected.action}
          onConfirm={handleConfirm}
          onClose={() => setSelected(null)}
          isPending={approveMutation.isPending || rejectMutation.isPending}
        />
      )}
    </div>
  )
}
