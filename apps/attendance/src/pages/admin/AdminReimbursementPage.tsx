import { usePageTitle } from '@/hooks/usePageTitle'
import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { adminListReimbursements, adminApproveReimbursement, adminRejectReimbursement } from '@/api/admin'
import AdminPageHero from '@/components/admin/AdminPageHero'
import AdminSummaryCard from '@/components/admin/AdminSummaryCard'
import AdminTableCard from '@/components/admin/AdminTableCard'
import StatusBadge from '@/components/StatusBadge'
import Pagination from '@/components/Pagination'
import ApprovalModal from '@/components/ApprovalModal'
import AttachmentPreview from '@/components/AttachmentPreview'
import type { Reimbursement } from '@/types'

function formatRp(amount: number) {
  return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(amount)
}

export default function AdminReimbursementPage() {
  usePageTitle('Approval Reimburse')
  const qc = useQueryClient()
  const [page, setPage] = useState(1)
  const [statusFilter, setStatusFilter] = useState('pending')
  const [selected, setSelected] = useState<{ item: Reimbursement; action: 'approve' | 'reject' } | null>(null)
  const [partialAmount, setPartialAmount] = useState('')
  const [preview, setPreview] = useState<{ url: string; title: string } | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['admin-reimbursements', page, statusFilter],
    queryFn: () => adminListReimbursements({ status: statusFilter, page: String(page) }),
  })

  const approveMutation = useMutation({
    mutationFn: ({ id, notes, amount }: { id: number; notes: string; amount?: number }) =>
      adminApproveReimbursement(id, notes, amount),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['admin-reimbursements'] }); setSelected(null); setPartialAmount('') },
  })

  const rejectMutation = useMutation({
    mutationFn: ({ id, notes }: { id: number; notes: string }) => adminRejectReimbursement(id, notes),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['admin-reimbursements'] }); setSelected(null) },
  })

  function handleConfirm(notes: string) {
    if (!selected) return
    if (selected.action === 'approve') {
      approveMutation.mutate({
        id: selected.item.id,
        notes,
        amount: partialAmount ? Number(partialAmount) : undefined,
      })
    } else {
      rejectMutation.mutate({ id: selected.item.id, notes })
    }
  }

  return (
    <div className="admin-page-shell">
      <AdminPageHero
        eyebrow="Finance Workspace"
        title="Reimbursement Approval Desk"
        subtitle="Lihat nilai request, approval parsial, dan backlog reimbursement dari satu workspace finance."
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

      {data?.summary && (
        <div className="row g-3 mb-3">
          <div className="col-md-3">
            <AdminSummaryCard
              label="Total Pengajuan"
              value={String(data.summary.total_requests)}
              meta={`Rata-rata ${formatRp(data.summary.average_request)} per pengajuan`}
              variant="navy"
            />
          </div>
          <div className="col-md-3">
            <AdminSummaryCard
              label="Total Diajukan"
              value={formatRp(data.summary.requested_total)}
              meta="Akumulasi nominal request"
              variant="rose"
            />
          </div>
          <div className="col-md-3">
            <AdminSummaryCard
              label="Total Disetujui"
              value={formatRp(data.summary.approved_total)}
              meta="Nominal approval yang sudah berjalan"
              variant="emerald"
            />
          </div>
          <div className="col-md-3">
            <AdminSummaryCard
              label="Status Approval"
              value={`${data.summary.status_counts.pending} Pending · ${data.summary.status_counts.approved} Approved · ${data.summary.status_counts.rejected} Rejected`}
              meta="Komposisi approval reimbursement"
              variant="amber"
              compactValue
            />
          </div>
        </div>
      )}

      <AdminTableCard
        title="Daftar Reimbursement"
        subtitle={`${data?.pending_count ?? 0} pengajuan masih menunggu keputusan.`}
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
                      <th>Keterangan</th>
                      <th>Jumlah</th>
                      <th>Disetujui</th>
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
                    {data?.data.map((r) => (
                      <tr key={r.id}>
                        <td className="fw-medium">{r.user?.name ?? '-'}</td>
                        <td>
                          <p className="mb-0">{r.event}</p>
                          <small className="text-muted">
                            {r.category && <>{r.category.name} · </>}
                            {r.date} · {r.quantity}× {formatRp(r.unit_price)}
                          </small>
                        </td>
                        <td>{formatRp(r.total_amount)}</td>
                        <td>{r.approved_amount != null ? formatRp(r.approved_amount) : '-'}</td>
                        <td><StatusBadge status={r.status} /></td>
                        <td className="text-end">
                          {r.attachment_url && (
                            <button
                              type="button"
                              onClick={() => setPreview({ url: r.attachment_url!, title: r.event })}
                              className="btn btn-link btn-sm text-primary p-0 me-2"
                            >
                              Bukti
                            </button>
                          )}
                          {r.status === 'pending' && (
                            <div className="d-inline-flex gap-1">
                              <button onClick={() => { setSelected({ item: r, action: 'approve' }); setPartialAmount('') }} className="btn btn-success btn-sm">Setujui</button>
                              <button onClick={() => setSelected({ item: r, action: 'reject' })} className="btn btn-danger btn-sm">Tolak</button>
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
          title={selected.action === 'approve' ? 'Setujui Reimbursement' : 'Tolak Reimbursement'}
          action={selected.action}
          onConfirm={handleConfirm}
          onClose={() => { setSelected(null); setPartialAmount('') }}
          isPending={approveMutation.isPending || rejectMutation.isPending}
          extraFields={
            selected.action === 'approve' ? (
              <div className="mb-3">
                <label className="form-label">
                  Jumlah Disetujui <span className="text-muted">(kosongkan = penuh {formatRp(selected.item.total_amount)})</span>
                </label>
                <input
                  type="number"
                  className="form-control"
                  value={partialAmount}
                  onChange={(e) => setPartialAmount(e.target.value)}
                  min="1"
                  max={selected.item.total_amount}
                  placeholder={String(selected.item.total_amount)}
                />
              </div>
            ) : undefined
          }
        />
      )}

      <AttachmentPreview
        url={preview?.url ?? null}
        title={preview?.title}
        onClose={() => setPreview(null)}
      />
    </div>
  )
}
