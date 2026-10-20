import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { adminCreateJobTitle, adminDeleteJobTitle, adminListJobTitles, adminUpdateJobTitle } from '@/api/masterData'
import FeedbackAlert from '@/components/FeedbackAlert'
import Pagination from '@/components/Pagination'
import { usePageTitle } from '@/hooks/usePageTitle'
import type { JobTitle } from '@/types'

export default function AdminJobTitlePage() {
  usePageTitle('Master Data Job Title')
  const qc = useQueryClient()
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [divisionFilter, setDivisionFilter] = useState('')
  const [formName, setFormName] = useState('')
  const [formDivisionId, setFormDivisionId] = useState('')
  const [editing, setEditing] = useState<JobTitle | null>(null)
  const [successMsg, setSuccessMsg] = useState('')
  const [errorMsg, setErrorMsg] = useState('')

  const { data, isLoading } = useQuery({
    queryKey: ['admin', 'master-data', 'job-titles', page, search, divisionFilter],
    queryFn: () => adminListJobTitles({
      page: String(page),
      ...(search ? { search } : {}),
      ...(divisionFilter ? { division_id: divisionFilter } : {}),
    }),
  })

  const createMutation = useMutation({
    mutationFn: (payload: { name: string; division_id: number }) => adminCreateJobTitle(payload),
    onSuccess: () => {
      setFormName('')
      setFormDivisionId('')
      setSuccessMsg('Job title berhasil dibuat.')
      setErrorMsg('')
      qc.invalidateQueries({ queryKey: ['admin', 'master-data', 'job-titles'] })
    },
    onError: (error: any) => {
      setErrorMsg(error?.response?.data?.message ?? 'Gagal membuat job title.')
      setSuccessMsg('')
    },
  })

  const updateMutation = useMutation({
    mutationFn: ({ id, name, division_id }: { id: number; name: string; division_id: number }) =>
      adminUpdateJobTitle(id, { name, division_id }),
    onSuccess: () => {
      setEditing(null)
      setFormName('')
      setFormDivisionId('')
      setSuccessMsg('Job title berhasil diperbarui.')
      setErrorMsg('')
      qc.invalidateQueries({ queryKey: ['admin', 'master-data', 'job-titles'] })
    },
    onError: (error: any) => {
      setErrorMsg(error?.response?.data?.message ?? 'Gagal memperbarui job title.')
      setSuccessMsg('')
    },
  })

  const deleteMutation = useMutation({
    mutationFn: (id: number) => adminDeleteJobTitle(id),
    onSuccess: () => {
      setSuccessMsg('Job title berhasil dihapus.')
      setErrorMsg('')
      qc.invalidateQueries({ queryKey: ['admin', 'master-data', 'job-titles'] })
    },
    onError: (error: any) => {
      setErrorMsg(error?.response?.data?.message ?? 'Gagal menghapus job title.')
      setSuccessMsg('')
    },
  })

  function submitForm() {
    if (!formName.trim()) {
      setErrorMsg('Nama job title wajib diisi.')
      setSuccessMsg('')
      return
    }

    if (!formDivisionId) {
      setErrorMsg('Division wajib dipilih.')
      setSuccessMsg('')
      return
    }

    const division_id = Number(formDivisionId)

    if (editing) {
      updateMutation.mutate({ id: editing.id, name: formName, division_id })
      return
    }

    createMutation.mutate({ name: formName, division_id })
  }

  function beginEdit(jobTitle: JobTitle) {
    setEditing(jobTitle)
    setFormName(jobTitle.name)
    setFormDivisionId(jobTitle.division_id ? String(jobTitle.division_id) : '')
    setErrorMsg('')
    setSuccessMsg('')
  }

  function resetForm() {
    setEditing(null)
    setFormName('')
    setFormDivisionId('')
    setErrorMsg('')
  }

  return (
    <>
      <div className="d-flex align-items-start justify-content-between mb-4 gap-3">
        <div>
          <h5 className="mb-1" style={{ fontWeight: 700 }}>Job Title</h5>
          <p className="text-muted mb-0" style={{ fontSize: 13 }}>
            Referensi posisi kerja yang terikat ke division dan dipakai di employee, attendance, serta payroll.
          </p>
        </div>
      </div>

      <FeedbackAlert type="success" message={successMsg} />
      <FeedbackAlert type="danger" message={errorMsg} />

      <div className="row g-3">
        <div className="col-lg-4">
          <div className="card">
            <div className="card-header">{editing ? 'Ubah Job Title' : 'Tambah Job Title'}</div>
            <div className="card-body">
              <div className="mb-3">
                <label className="form-label">Division</label>
                <select
                  className="form-select"
                  value={formDivisionId}
                  onChange={(e) => setFormDivisionId(e.target.value)}
                >
                  <option value="">Pilih division</option>
                  {data?.divisions.map((division) => (
                    <option key={division.id} value={division.id}>{division.name}</option>
                  ))}
                </select>
              </div>
              <div className="mb-3">
                <label className="form-label">Nama Job Title</label>
                <input
                  className="form-control"
                  value={formName}
                  onChange={(e) => setFormName(e.target.value)}
                  placeholder="Contoh: Supervisor"
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
                    : editing ? 'Simpan Perubahan' : 'Tambah Job Title'}
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
            <div className="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
              <span>Daftar Job Title</span>
              <div className="d-flex align-items-center gap-2 flex-wrap master-data-toolbar">
                <select
                  className="form-select form-select-sm"
                  style={{ width: 180 }}
                  value={divisionFilter}
                  onChange={(e) => { setDivisionFilter(e.target.value); setPage(1) }}
                >
                  <option value="">Semua division</option>
                  {data?.divisions.map((division) => (
                    <option key={division.id} value={division.id}>{division.name}</option>
                  ))}
                </select>
                <input
                  className="form-control form-control-sm"
                  style={{ width: 220 }}
                  value={search}
                  onChange={(e) => { setSearch(e.target.value); setPage(1) }}
                  placeholder="Cari job title..."
                />
              </div>
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
                          <th>Division</th>
                          <th>Dipakai Karyawan</th>
                          <th>Diperbarui</th>
                          <th></th>
                        </tr>
                      </thead>
                      <tbody>
                        {data?.data.length === 0 && (
                          <tr>
                            <td colSpan={5} className="text-center text-muted py-4">Belum ada job title.</td>
                          </tr>
                        )}
                        {data?.data.map((jobTitle) => (
                          <tr key={jobTitle.id}>
                            <td className="fw-medium">{jobTitle.name}</td>
                            <td>{jobTitle.division?.name ?? '-'}</td>
                            <td>{jobTitle.users_count}</td>
                            <td>{new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium' }).format(new Date(jobTitle.updated_at))}</td>
                            <td className="text-end">
                              <div className="d-flex justify-content-end gap-2 admin-table-actions">
                                <button className="btn btn-outline-dark btn-sm" onClick={() => beginEdit(jobTitle)}>
                                  Ubah
                                </button>
                                <button
                                  className="btn btn-outline-danger btn-sm"
                                  onClick={() => deleteMutation.mutate(jobTitle.id)}
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
