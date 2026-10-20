import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { adminCreateShift, adminDeleteShift, adminListShifts, adminUpdateShift } from '@/api/masterData'
import AdminPageHero from '@/components/admin/AdminPageHero'
import AdminPanelCard from '@/components/admin/AdminPanelCard'
import AdminTableCard from '@/components/admin/AdminTableCard'
import FeedbackAlert from '@/components/FeedbackAlert'
import Pagination from '@/components/Pagination'
import { usePageTitle } from '@/hooks/usePageTitle'
import type { Shift } from '@/types'

export default function AdminShiftPage() {
  usePageTitle('Master Data Shift')
  const qc = useQueryClient()
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [form, setForm] = useState({ name: '', start_time: '', end_time: '', grace_period_minutes: 15 as number | '' })
  const [editing, setEditing] = useState<Shift | null>(null)
  const [successMsg, setSuccessMsg] = useState('')
  const [errorMsg, setErrorMsg] = useState('')

  const { data, isLoading } = useQuery({
    queryKey: ['admin', 'master-data', 'shifts', page, search],
    queryFn: () => adminListShifts({
      page: String(page),
      ...(search ? { search } : {}),
    }),
  })

  const createMutation = useMutation({
    mutationFn: (payload: { name: string; start_time: string; end_time: string | null; grace_period_minutes: number }) => adminCreateShift(payload),
    onSuccess: () => {
      resetForm()
      setSuccessMsg('Shift berhasil dibuat.')
      qc.invalidateQueries({ queryKey: ['admin', 'master-data', 'shifts'] })
    },
    onError: (error: any) => {
      setErrorMsg(error?.response?.data?.message ?? 'Gagal membuat shift.')
      setSuccessMsg('')
    },
  })

  const updateMutation = useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: { name: string; start_time: string; end_time: string | null; grace_period_minutes: number } }) =>
      adminUpdateShift(id, payload),
    onSuccess: () => {
      resetForm()
      setSuccessMsg('Shift berhasil diperbarui.')
      qc.invalidateQueries({ queryKey: ['admin', 'master-data', 'shifts'] })
    },
    onError: (error: any) => {
      setErrorMsg(error?.response?.data?.message ?? 'Gagal memperbarui shift.')
      setSuccessMsg('')
    },
  })

  const deleteMutation = useMutation({
    mutationFn: (id: number) => adminDeleteShift(id),
    onSuccess: () => {
      setSuccessMsg('Shift berhasil dihapus.')
      setErrorMsg('')
      qc.invalidateQueries({ queryKey: ['admin', 'master-data', 'shifts'] })
    },
    onError: (error: any) => {
      setErrorMsg(error?.response?.data?.message ?? 'Gagal menghapus shift.')
      setSuccessMsg('')
    },
  })

  function submitForm() {
    if (!form.name.trim() || !form.start_time) {
      setErrorMsg('Nama shift dan jam mulai wajib diisi.')
      setSuccessMsg('')
      return
    }

    const payload = {
      name: form.name,
      start_time: form.start_time,
      end_time: form.end_time || null,
      grace_period_minutes: Number.isFinite(form.grace_period_minutes) ? Number(form.grace_period_minutes) : 15,
    }

    if (editing) {
      updateMutation.mutate({ id: editing.id, payload })
      return
    }

    createMutation.mutate(payload)
  }

  function beginEdit(shift: Shift) {
    setEditing(shift)
    setForm({
      name: shift.name,
      start_time: shift.start_time?.slice(0, 5) ?? '',
      end_time: shift.end_time?.slice(0, 5) ?? '',
      grace_period_minutes: (shift as any).grace_period_minutes ?? 15,
    })
    setErrorMsg('')
    setSuccessMsg('')
  }

  function resetForm() {
    setEditing(null)
    setForm({ name: '', start_time: '', end_time: '', grace_period_minutes: 15 })
    setErrorMsg('')
  }

  return (
    <div className="admin-page-shell">
      <AdminPageHero
        eyebrow="Master Data Workspace"
        title="Shift Master"
        subtitle="Master jam kerja untuk attendance, keterlambatan, business trip, auto shift, dan mapping jadwal."
        variant="workforce"
      />

      <FeedbackAlert type="success" message={successMsg} />
      <FeedbackAlert type="danger" message={errorMsg} />

      <div className="row g-3">
        <div className="col-lg-4">
          <AdminPanelCard
            title={editing ? 'Ubah Shift' : 'Tambah Shift'}
            subtitle="Kelola definisi shift yang akan dipakai lintas attendance dan scheduling."
          >
              <div className="mb-3">
                <label className="form-label">Nama Shift</label>
                <input
                  className="form-control"
                  value={form.name}
                  onChange={(e) => setForm({ ...form, name: e.target.value })}
                  placeholder="Contoh: Shift Pagi"
                />
              </div>
              <div className="row g-2">
                <div className="col-6">
                  <label className="form-label">Jam Mulai</label>
                  <input
                    type="time"
                    className="form-control"
                    value={form.start_time}
                    onChange={(e) => setForm({ ...form, start_time: e.target.value })}
                  />
                </div>
                <div className="col-6">
                  <label className="form-label">Jam Selesai</label>
                  <input
                    type="time"
                    className="form-control"
                    value={form.end_time}
                    onChange={(e) => setForm({ ...form, end_time: e.target.value })}
                  />
                </div>
              </div>
              <div className="row g-2 mt-2">
                <div className="col-6">
                  <label className="form-label">Toleransi Telat (menit)</label>
                  <input
                    type="number"
                    min={0}
                    max={120}
                    className="form-control"
                    value={form.grace_period_minutes}
                    onChange={(e) => setForm({ ...form, grace_period_minutes: e.target.value === '' ? '' : Math.max(0, Math.min(120, parseInt(e.target.value, 10) || 0)) })}
                  />
                  <div className="text-muted mt-1" style={{ fontSize: 11 }}>
                    Clock-in lewat menit ini dari jam mulai dihitung TERLAMBAT.
                  </div>
                </div>
              </div>
              <div className="text-muted mt-2 mb-3" style={{ fontSize: 12 }}>
                Shift lintas hari didukung, misalnya 19:00 sampai 02:00.
              </div>
              <div className="d-flex gap-2">
                <button
                  className="btn btn-dark btn-sm"
                  onClick={submitForm}
                  disabled={createMutation.isPending || updateMutation.isPending}
                >
                  {createMutation.isPending || updateMutation.isPending
                    ? 'Menyimpan...'
                    : editing ? 'Simpan Perubahan' : 'Tambah Shift'}
                </button>
                {editing && (
                  <button className="btn btn-outline-secondary btn-sm" onClick={resetForm}>
                    Batal
                  </button>
                )}
              </div>
          </AdminPanelCard>
        </div>

        <div className="col-lg-8">
          <AdminTableCard
            title="Daftar Shift"
            subtitle="Cari dan kelola shift aktif yang dipakai pada auto shift dan mapping."
            bodyClassName="p-0"
          >
              <div className="px-3 pt-3">
                <input
                  className="form-control form-control-sm"
                  style={{ maxWidth: 260 }}
                  value={search}
                  onChange={(e) => { setSearch(e.target.value); setPage(1) }}
                  placeholder="Cari shift..."
                />
              </div>
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
                          <th>Nama</th>
                          <th>Jam</th>
                          <th>Auto Shift</th>
                          <th>Mapping</th>
                          <th></th>
                        </tr>
                      </thead>
                      <tbody>
                        {data?.data.length === 0 && (
                          <tr>
                            <td colSpan={5} className="text-center text-muted py-4">Belum ada shift.</td>
                          </tr>
                        )}
                        {data?.data.map((shift) => (
                          <tr key={shift.id}>
                            <td className="fw-medium">{shift.name}</td>
                            <td>{shift.start_time?.slice(0, 5)} - {shift.end_time?.slice(0, 5) ?? '-'}</td>
                            <td>{shift.auto_shifts_count}</td>
                            <td>{shift.shift_mappings_count}</td>
                            <td className="text-end">
                              <div className="d-flex justify-content-end gap-2">
                                <button className="btn btn-outline-dark btn-sm" onClick={() => beginEdit(shift)}>
                                  Ubah
                                </button>
                                <button
                                  className="btn btn-outline-danger btn-sm"
                                  onClick={() => deleteMutation.mutate(shift.id)}
                                  disabled={deleteMutation.isPending}
                                >
                                  Hapus
                                </button>
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
      </div>
    </div>
  )
}
