import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { adminCreateDivision, adminDeleteDivision, adminListDivisions, adminUpdateDivision } from '@/api/masterData'
import FeedbackAlert from '@/components/FeedbackAlert'
import Pagination from '@/components/Pagination'
import { usePageTitle } from '@/hooks/usePageTitle'
import type { Division } from '@/types'

export default function AdminDivisionPage() {
  usePageTitle('Master Data Division')
  const qc = useQueryClient()
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [formName, setFormName] = useState('')
  const [editing, setEditing] = useState<Division | null>(null)
  const [successMsg, setSuccessMsg] = useState('')
  const [errorMsg, setErrorMsg] = useState('')

  const { data, isLoading } = useQuery({
    queryKey: ['admin', 'master-data', 'divisions', page, search],
    queryFn: () => adminListDivisions({
      page: String(page),
      ...(search ? { search } : {}),
    }),
  })

  const createMutation = useMutation({
    mutationFn: (payload: { name: string }) => adminCreateDivision(payload),
    onSuccess: () => {
      setFormName('')
      setSuccessMsg('Division berhasil dibuat.')
      setErrorMsg('')
      qc.invalidateQueries({ queryKey: ['admin', 'master-data', 'divisions'] })
    },
    onError: (error: any) => {
      setErrorMsg(error?.response?.data?.message ?? 'Gagal membuat division.')
      setSuccessMsg('')
    },
  })

  const updateMutation = useMutation({
    mutationFn: ({ id, name }: { id: number; name: string }) => adminUpdateDivision(id, { name }),
    onSuccess: () => {
      setEditing(null)
      setFormName('')
      setSuccessMsg('Division berhasil diperbarui.')
      setErrorMsg('')
      qc.invalidateQueries({ queryKey: ['admin', 'master-data', 'divisions'] })
    },
    onError: (error: any) => {
      setErrorMsg(error?.response?.data?.message ?? 'Gagal memperbarui division.')
      setSuccessMsg('')
    },
  })

  const deleteMutation = useMutation({
    mutationFn: (id: number) => adminDeleteDivision(id),
    onSuccess: () => {
      setSuccessMsg('Division berhasil dihapus.')
      setErrorMsg('')
      qc.invalidateQueries({ queryKey: ['admin', 'master-data', 'divisions'] })
    },
    onError: (error: any) => {
      setErrorMsg(error?.response?.data?.message ?? 'Gagal menghapus division.')
      setSuccessMsg('')
    },
  })

  function submitForm() {
    if (!formName.trim()) {
      setErrorMsg('Nama division wajib diisi.')
      setSuccessMsg('')
      return
    }

    if (editing) {
      updateMutation.mutate({ id: editing.id, name: formName })
      return
    }

    createMutation.mutate({ name: formName })
  }

  function beginEdit(division: Division) {
    setEditing(division)
    setFormName(division.name)
    setErrorMsg('')
    setSuccessMsg('')
  }

  function resetForm() {
    setEditing(null)
    setFormName('')
    setErrorMsg('')
  }

  return (
    <>
      <div className="d-flex align-items-start justify-content-between mb-4 gap-3">
        <div>
          <h5 className="mb-1" style={{ fontWeight: 700 }}>Division</h5>
          <p className="text-muted mb-0" style={{ fontSize: 13 }}>
            Master referensi unit kerja yang dipakai lintas employee, approval, attendance, dan payroll.
          </p>
        </div>
      </div>

      <FeedbackAlert type="success" message={successMsg} />
      <FeedbackAlert type="danger" message={errorMsg} />

      <div className="row g-3">
        <div className="col-lg-4">
          <div className="card">
            <div className="card-header">{editing ? 'Ubah Division' : 'Tambah Division'}</div>
            <div className="card-body">
              <div className="mb-3">
                <label className="form-label">Nama Division</label>
                <input
                  className="form-control"
                  value={formName}
                  onChange={(e) => setFormName(e.target.value)}
                  placeholder="Contoh: Human Resource"
                />
              </div>
              <div className="d-flex gap-2 admin-form-actions">
                <button
                  className="btn btn-dark btn-sm"
                  onClick={submitForm}
                  disabled={createMutation.isPending || updateMutation.isPending}
                >
                  {createMutation.isPending || updateMutation.isPending
                    ? 'Menyimpan...'
                    : editing ? 'Simpan Perubahan' : 'Tambah Division'}
                </button>
                {editing && (
                  <button className="btn btn-outline-secondary btn-sm" onClick={resetForm}>
                    Batal
                  </button>
                )}
              </div>
            </div>
          </div>
        </div>

        <div className="col-lg-8">
          <div className="card">
            <div className="card-header d-flex align-items-center justify-content-between gap-2">
              <span>Daftar Division</span>
              <input
                className="form-control form-control-sm"
                style={{ maxWidth: 260 }}
                value={search}
                onChange={(e) => { setSearch(e.target.value); setPage(1) }}
                placeholder="Cari division..."
              />
            </div>
            <div className="card-body p-0">
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
                          <th>Dipakai Karyawan</th>
                          <th>Diperbarui</th>
                          <th></th>
                        </tr>
                      </thead>
                      <tbody>
                        {data?.data.length === 0 && (
                          <tr>
                            <td colSpan={4} className="text-center text-muted py-4">Belum ada division.</td>
                          </tr>
                        )}
                        {data?.data.map((division) => (
                          <tr key={division.id}>
                            <td className="fw-medium">{division.name}</td>
                            <td>{division.users_count}</td>
                            <td>{new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium' }).format(new Date(division.updated_at))}</td>
                            <td className="text-end">
                              <div className="d-flex justify-content-end gap-2 admin-table-actions">
                                <button className="btn btn-outline-dark btn-sm" onClick={() => beginEdit(division)}>
                                  Ubah
                                </button>
                                <button
                                  className="btn btn-outline-danger btn-sm"
                                  onClick={() => deleteMutation.mutate(division.id)}
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
            </div>
          </div>
        </div>
      </div>
    </>
  )
}
