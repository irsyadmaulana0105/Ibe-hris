import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { adminCreateInventory, adminListInventories, adminUpdateInventory } from '@/api/masterData'
import AdminPageHero from '@/components/admin/AdminPageHero'
import AdminPanelCard from '@/components/admin/AdminPanelCard'
import AdminTableCard from '@/components/admin/AdminTableCard'
import FeedbackAlert from '@/components/FeedbackAlert'
import Pagination from '@/components/Pagination'
import { usePageTitle } from '@/hooks/usePageTitle'
import type { InventoryItem } from '@/types'

const emptyForm = {
  item_code: '',
  item_name: '',
  category_id: '',
  stock: '0',
  unit: '',
  description: '',
  office_location_id: '',
  job_title_id: '',
}

type InventoryFormState = typeof emptyForm

function stockLabel(value: string) {
  const number = Number(value)
  return Number.isInteger(number) ? String(number) : value
}

export default function AdminInventoryPage() {
  usePageTitle('Inventory Master')
  const qc = useQueryClient()
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [categoryId, setCategoryId] = useState('')
  const [officeLocationId, setOfficeLocationId] = useState('')
  const [jobTitleId, setJobTitleId] = useState('')
  const [form, setForm] = useState<InventoryFormState>(emptyForm)
  const [editing, setEditing] = useState<InventoryItem | null>(null)
  const [successMsg, setSuccessMsg] = useState('')
  const [errorMsg, setErrorMsg] = useState('')

  const { data, isLoading, isError } = useQuery({
    queryKey: ['admin', 'master-data', 'inventories', page, search, categoryId, officeLocationId, jobTitleId],
    queryFn: () => adminListInventories({
      page: String(page),
      ...(search ? { search } : {}),
      ...(categoryId ? { category_id: categoryId } : {}),
      ...(officeLocationId ? { office_location_id: officeLocationId } : {}),
      ...(jobTitleId ? { job_title_id: jobTitleId } : {}),
    }),
  })

  const createMutation = useMutation({
    mutationFn: adminCreateInventory,
    onSuccess: () => {
      resetForm()
      setSuccessMsg('Item inventory berhasil dibuat.')
      setErrorMsg('')
      qc.invalidateQueries({ queryKey: ['admin', 'master-data', 'inventories'] })
    },
    onError: (error: any) => {
      setErrorMsg(error?.response?.data?.message ?? 'Gagal membuat item inventory.')
      setSuccessMsg('')
    },
  })

  const updateMutation = useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: ReturnType<typeof toPayload> }) => adminUpdateInventory(id, payload),
    onSuccess: () => {
      resetForm()
      setSuccessMsg('Item inventory berhasil diperbarui.')
      setErrorMsg('')
      qc.invalidateQueries({ queryKey: ['admin', 'master-data', 'inventories'] })
    },
    onError: (error: any) => {
      setErrorMsg(error?.response?.data?.message ?? 'Gagal memperbarui item inventory.')
      setSuccessMsg('')
    },
  })

  function updateForm(field: keyof InventoryFormState, value: string) {
    setForm((current) => ({ ...current, [field]: value }))
  }

  function toPayload() {
    return {
      item_code: form.item_code.trim() || null,
      item_name: form.item_name.trim(),
      category_id: form.category_id ? Number(form.category_id) : null,
      stock: Number(form.stock || 0),
      unit: form.unit.trim() || null,
      description: form.description.trim() || null,
      office_location_id: form.office_location_id ? Number(form.office_location_id) : null,
      job_title_id: form.job_title_id ? Number(form.job_title_id) : null,
    }
  }

  function submitForm() {
    setSuccessMsg('')
    setErrorMsg('')

    if (editing) {
      updateMutation.mutate({ id: editing.id, payload: toPayload() })
      return
    }

    createMutation.mutate(toPayload())
  }

  function beginEdit(item: InventoryItem) {
    setEditing(item)
    setForm({
      item_code: item.item_code ?? '',
      item_name: item.item_name,
      category_id: item.category ? String(item.category.id) : '',
      stock: stockLabel(item.stock),
      unit: item.unit ?? '',
      description: item.description ?? '',
      office_location_id: item.office_location ? String(item.office_location.id) : '',
      job_title_id: item.job_title ? String(item.job_title.id) : '',
    })
    setSuccessMsg('')
    setErrorMsg('')
  }

  function resetForm() {
    setEditing(null)
    setForm(emptyForm)
    setErrorMsg('')
  }

  const isSaving = createMutation.isPending || updateMutation.isPending

  return (
    <div className="admin-page-shell">
      <AdminPageHero
        eyebrow="Master Data Workspace"
        title="Inventory Master"
        subtitle="Kelola item inventory lintas lokasi, kategori, dan job title untuk membuka pondasi domain ERP non-HR."
        variant="finance"
      />

      <FeedbackAlert type="success" message={successMsg} />
      <FeedbackAlert type="danger" message={errorMsg} />

      <div className="row g-3">
        <div className="col-lg-4">
          <AdminPanelCard
            title={editing ? 'Ubah Item Inventory' : 'Tambah Item Inventory'}
            subtitle="Simpan item operasional beserta stock, kategori, lokasi, dan relasi jabatan."
            className="h-100"
          >
            <div className="row g-3">
              <div className="col-md-6 col-lg-12">
                <label className="form-label">Kode Item</label>
                <input className="form-control" value={form.item_code} onChange={(e) => updateForm('item_code', e.target.value)} />
              </div>
              <div className="col-md-6 col-lg-12">
                <label className="form-label">Nama Item</label>
                <input className="form-control" value={form.item_name} onChange={(e) => updateForm('item_name', e.target.value)} />
              </div>
              <div className="col-md-6">
                <label className="form-label">Kategori</label>
                <select className="form-select" value={form.category_id} onChange={(e) => updateForm('category_id', e.target.value)}>
                  <option value="">Pilih</option>
                  {data?.references.categories.map((category) => (
                    <option key={category.id} value={category.id}>{category.name}</option>
                  ))}
                </select>
              </div>
              <div className="col-md-6">
                <label className="form-label">Stock</label>
                <input className="form-control" type="number" min="0" step="0.01" value={form.stock} onChange={(e) => updateForm('stock', e.target.value)} />
              </div>
              <div className="col-md-6">
                <label className="form-label">Unit</label>
                <input className="form-control" value={form.unit} onChange={(e) => updateForm('unit', e.target.value)} placeholder="pcs, unit, box" />
              </div>
              <div className="col-md-6">
                <label className="form-label">Office Location</label>
                <select className="form-select" value={form.office_location_id} onChange={(e) => updateForm('office_location_id', e.target.value)}>
                  <option value="">Pilih</option>
                  {data?.references.office_locations.map((location) => (
                    <option key={location.id} value={location.id}>{location.name}</option>
                  ))}
                </select>
              </div>
              <div className="col-12">
                <label className="form-label">Job Title</label>
                <select className="form-select" value={form.job_title_id} onChange={(e) => updateForm('job_title_id', e.target.value)}>
                  <option value="">Pilih</option>
                  {data?.references.job_titles.map((jobTitle) => (
                    <option key={jobTitle.id} value={jobTitle.id}>{jobTitle.name}</option>
                  ))}
                </select>
              </div>
              <div className="col-12">
                <label className="form-label">Deskripsi</label>
                <textarea className="form-control" rows={3} value={form.description} onChange={(e) => updateForm('description', e.target.value)} />
              </div>
            </div>
            <div className="d-flex gap-2 mt-3 admin-form-actions">
              <button className="btn btn-dark btn-sm" onClick={submitForm} disabled={isSaving}>
                {isSaving ? 'Menyimpan...' : editing ? 'Simpan Perubahan' : 'Tambah Item'}
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
          <AdminPanelCard
            title="Filter Inventory"
            subtitle="Cari item dan persempit berdasarkan kategori, lokasi, atau jabatan."
            className="mb-3"
          >
            <div className="row g-3">
              <div className="col-md-4">
                <label className="form-label">Cari</label>
                <input className="form-control" value={search} onChange={(e) => { setSearch(e.target.value); setPage(1) }} placeholder="Nama, kode, unit" />
              </div>
              <div className="col-md-3">
                <label className="form-label">Kategori</label>
                <select className="form-select" value={categoryId} onChange={(e) => { setCategoryId(e.target.value); setPage(1) }}>
                  <option value="">Semua</option>
                  {data?.references.categories.map((category) => (
                    <option key={category.id} value={category.id}>{category.name}</option>
                  ))}
                </select>
              </div>
              <div className="col-md-3">
                <label className="form-label">Lokasi</label>
                <select className="form-select" value={officeLocationId} onChange={(e) => { setOfficeLocationId(e.target.value); setPage(1) }}>
                  <option value="">Semua</option>
                  {data?.references.office_locations.map((location) => (
                    <option key={location.id} value={location.id}>{location.name}</option>
                  ))}
                </select>
              </div>
              <div className="col-md-2">
                <label className="form-label">Job Title</label>
                <select className="form-select" value={jobTitleId} onChange={(e) => { setJobTitleId(e.target.value); setPage(1) }}>
                  <option value="">Semua</option>
                  {data?.references.job_titles.map((jobTitle) => (
                    <option key={jobTitle.id} value={jobTitle.id}>{jobTitle.name}</option>
                  ))}
                </select>
              </div>
            </div>
          </AdminPanelCard>

          <AdminTableCard
            title="Daftar Inventory"
            subtitle="Pondasi item operasional yang bisa dikembangkan ke stock movement, request, dan procurement."
            bodyClassName="p-0"
          >
            {isLoading ? (
              <div className="d-flex justify-content-center py-5">
                <div className="spinner-border text-primary spinner-border-sm" role="status" />
              </div>
            ) : isError ? (
              <div className="alert alert-danger feedback-alert feedback-alert--inline mb-0">
                Gagal memuat inventory.
              </div>
            ) : (
              <>
                <div className="table-responsive">
                  <table className="table table-hover mb-0 align-middle">
                    <thead>
                      <tr>
                        <th>Item</th>
                        <th>Kategori</th>
                        <th>Stock</th>
                        <th>Lokasi</th>
                        <th>Job Title</th>
                        <th></th>
                      </tr>
                    </thead>
                    <tbody>
                      {data?.data.length === 0 && (
                        <tr>
                          <td colSpan={6} className="text-center text-muted py-4">Belum ada item inventory.</td>
                        </tr>
                      )}
                      {data?.data.map((item) => (
                        <tr key={item.id}>
                          <td>
                            <div className="fw-medium">{item.item_name}</div>
                            <small className="text-muted">{item.item_code ?? 'Kode belum diisi'}</small>
                          </td>
                          <td>{item.category?.name ?? '-'}</td>
                          <td>{stockLabel(item.stock)} {item.unit ?? ''}</td>
                          <td>{item.office_location?.name ?? '-'}</td>
                          <td>{item.job_title?.name ?? '-'}</td>
                          <td className="text-end">
                            <button className="btn btn-outline-dark btn-sm" onClick={() => beginEdit(item)}>
                              Ubah
                            </button>
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
