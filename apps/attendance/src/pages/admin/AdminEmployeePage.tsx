import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { adminCreateEmployee, adminListEmployees, adminRegenerateInvite, adminResignEmployee, adminReactivateEmployee, adminUpdateEmployee, type ResignPayload } from '@/api/admin'
import AdminPageHero from '@/components/admin/AdminPageHero'
import AdminPanelCard from '@/components/admin/AdminPanelCard'
import AdminTableCard from '@/components/admin/AdminTableCard'
import FeedbackAlert from '@/components/FeedbackAlert'
import Pagination from '@/components/Pagination'
import { usePageTitle } from '@/hooks/usePageTitle'
import { getErrorMessage } from '@/utils/apiError'
import type { AdminEmployee, AdminEmployeePayload } from '@/types'

interface InviteResult {
  token: string
  expiresAt: string
  employeeName: string
}

function buildInviteUrl(token: string): string {
  // Detect base path from current URL so the link works whether the SPA is
  // served from root (absensi.ibereality.com/) or a sub-path (domain.com/app/)
  const currentPath = window.location.pathname
  const appBase = currentPath.startsWith('/app') ? '/app' : ''
  return `${window.location.origin}${appBase}/set-password?token=${encodeURIComponent(token)}`
}

function InvitePanel({ result, onDismiss }: { result: InviteResult; onDismiss: () => void }) {
  const [copied, setCopied] = useState(false)
  const url = buildInviteUrl(result.token)

  function copyLink() {
    navigator.clipboard.writeText(url).then(() => {
      setCopied(true)
      setTimeout(() => setCopied(false), 2500)
    })
  }

  return (
    <div className="alert alert-success border-0 mb-3" style={{ background: '#f0fdf4', borderLeft: '4px solid #16a34a' }}>
      <div className="d-flex justify-content-between align-items-start">
        <div className="flex-grow-1 me-2">
          <div className="fw-semibold mb-1" style={{ fontSize: 14 }}>
            Link undangan untuk <em>{result.employeeName}</em> siap dikirim
          </div>
          <div
            className="font-monospace text-break mb-2"
            style={{ fontSize: 12, background: '#dcfce7', padding: '6px 8px', borderRadius: 4 }}
          >
            {url}
          </div>
          <div className="d-flex gap-2">
            <button className="btn btn-success btn-sm" onClick={copyLink}>
              {copied ? 'Tersalin!' : 'Salin Link'}
            </button>
          </div>
          <div className="text-muted mt-2" style={{ fontSize: 11 }}>
            Link berlaku 72 jam · hanya bisa digunakan sekali · karyawan akan diminta set password sendiri
          </div>
        </div>
        <button type="button" className="btn-close" onClick={onDismiss} />
      </div>
    </div>
  )
}

function formatCurrency(value: number | null) {
  if (value === null) return '-'
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
  }).format(value)
}

const emptyForm = {
  nip: '',
  name: '',
  email: '',
  phone: '',
  password: '',
  gender: '',
  birth_date: '',
  birth_place: '',
  address: '',
  city: '',
  division_id: '',
  job_title_id: '',
  education_id: '',
  employment_status: '',
  salary_monthly: '',
  join_date: '',
  annual_leave_balance: '12',
}

type EmployeeFormState = typeof emptyForm

export default function AdminEmployeePage() {
  usePageTitle('Employee Master')
  const qc = useQueryClient()
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [divisionId, setDivisionId] = useState('')
  const [jobTitleId, setJobTitleId] = useState('')
  const [educationId, setEducationId] = useState('')
  const [employmentStatus, setEmploymentStatus] = useState('')
  const [includeInactive, setIncludeInactive] = useState(false)
  const [resigning, setResigning] = useState<AdminEmployee | null>(null)
  const [form, setForm] = useState<EmployeeFormState>(emptyForm)
  const [editing, setEditing] = useState<AdminEmployee | null>(null)
  const [successMsg, setSuccessMsg] = useState('')
  const [errorMsg, setErrorMsg] = useState('')
  const [inviteResult, setInviteResult] = useState<InviteResult | null>(null)

  const { data, isLoading, isError } = useQuery({
    queryKey: ['admin', 'employees', page, search, divisionId, jobTitleId, educationId, employmentStatus, includeInactive],
    queryFn: () => adminListEmployees({
      page: String(page),
      ...(search ? { search } : {}),
      ...(divisionId ? { division_id: divisionId } : {}),
      ...(jobTitleId ? { job_title_id: jobTitleId } : {}),
      ...(educationId ? { education_id: educationId } : {}),
      ...(employmentStatus ? { employment_status: employmentStatus } : {}),
      ...(includeInactive ? { include_inactive: '1' } : {}),
    }),
  })

  const resignMutation = useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: ResignPayload }) => adminResignEmployee(id, payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin', 'employees'] })
      setSuccessMsg('Karyawan ditandai resign. Semua sesi telah dihentikan.')
      setResigning(null)
    },
    onError: (err: unknown) => {
      const e = err as { response?: { data?: { message?: string } } }
      setErrorMsg(e.response?.data?.message ?? 'Gagal menandai resign.')
    },
  })

  const reactivateMutation = useMutation({
    mutationFn: ({ id, status }: { id: string; status?: string }) => adminReactivateEmployee(id, status),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin', 'employees'] })
      setSuccessMsg('Karyawan diaktifkan kembali. Reset password lalu undang ulang.')
    },
    onError: (err: unknown) => {
      const e = err as { response?: { data?: { message?: string } } }
      setErrorMsg(e.response?.data?.message ?? 'Gagal mengaktifkan kembali.')
    },
  })

  const jobTitles = data?.references.job_titles.filter((jobTitle) => (
    !divisionId || String(jobTitle.division_id) === divisionId
  )) ?? []

  const formJobTitles = data?.references.job_titles.filter((jobTitle) => (
    !form.division_id || String(jobTitle.division_id) === form.division_id
  )) ?? []

  const createMutation = useMutation({
    mutationFn: (payload: AdminEmployeePayload) => adminCreateEmployee(payload),
    onSuccess: (result) => {
      resetForm()
      setSuccessMsg('')
      setInviteResult({
        token: result.invite_token,
        expiresAt: result.invite_expires_at,
        employeeName: result.name,
      })
      qc.invalidateQueries({ queryKey: ['admin', 'employees'] })
    },
    onError: (error: unknown) => {
      setErrorMsg(getErrorMessage(error, 'Gagal membuat karyawan.'))
      setSuccessMsg('')
    },
  })

  const updateMutation = useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: AdminEmployeePayload }) => adminUpdateEmployee(id, payload),
    onSuccess: () => {
      resetForm()
      setSuccessMsg('Karyawan berhasil diperbarui.')
      qc.invalidateQueries({ queryKey: ['admin', 'employees'] })
    },
    onError: (error: unknown) => {
      setErrorMsg(getErrorMessage(error, 'Gagal memperbarui karyawan.'))
      setSuccessMsg('')
    },
  })

  const regenInviteMutation = useMutation({
    mutationFn: (employee: AdminEmployee) => adminRegenerateInvite(employee.id).then((res) => ({ ...res, employeeName: employee.name })),
    onSuccess: (res) => {
      setInviteResult({ token: res.invite_token, expiresAt: res.invite_expires_at, employeeName: res.employeeName })
    },
    onError: (error: unknown) => {
      setErrorMsg(getErrorMessage(error, 'Gagal membuat ulang link undangan.'))
    },
  })

  function updateForm(field: keyof EmployeeFormState, value: string) {
    setForm((current) => ({
      ...current,
      [field]: value,
      ...(field === 'division_id' ? { job_title_id: '' } : {}),
    }))
  }

  function toPayload(): AdminEmployeePayload {
    return {
      nip: form.nip.trim(),
      name: form.name.trim(),
      email: form.email.trim(),
      phone: form.phone.trim(),
      ...(form.password ? { password: form.password } : {}),
      gender: form.gender,
      birth_date: form.birth_date || null,
      birth_place: form.birth_place || null,
      address: form.address.trim(),
      city: form.city.trim(),
      division_id: form.division_id ? Number(form.division_id) : null,
      job_title_id: form.job_title_id ? Number(form.job_title_id) : null,
      education_id: form.education_id ? Number(form.education_id) : null,
      employment_status: form.employment_status || null,
      salary_monthly: form.salary_monthly ? Number(form.salary_monthly) : null,
      join_date: form.join_date || null,
      annual_leave_balance: form.annual_leave_balance ? Number(form.annual_leave_balance) : 12,
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

  function beginEdit(employee: AdminEmployee) {
    setEditing(employee)
    setForm({
      nip: employee.nip ?? '',
      name: employee.name,
      email: employee.email,
      phone: employee.phone ?? '',
      password: '',
      gender: employee.gender ?? '',
      birth_date: employee.birth_date ?? '',
      birth_place: employee.birth_place ?? '',
      address: employee.address ?? '',
      city: employee.city ?? '',
      division_id: employee.division ? String(employee.division.id) : '',
      job_title_id: employee.job_title ? String(employee.job_title.id) : '',
      education_id: employee.education ? String(employee.education.id) : '',
      employment_status: employee.employment_status ?? '',
      salary_monthly: employee.salary_monthly === null ? '' : String(employee.salary_monthly),
      join_date: employee.join_date ?? '',
      annual_leave_balance: String(employee.annual_leave_balance ?? 12),
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
        eyebrow="Workforce Workspace"
        title="Employee Master"
        subtitle="Kelola data induk karyawan, referensi organisasi, status kerja, dan parameter dasar payroll dari satu workspace."
        variant="employee"
      />

      <FeedbackAlert type="success" message={successMsg} />
      <FeedbackAlert type="danger" message={errorMsg} />
      {inviteResult && <InvitePanel result={inviteResult} onDismiss={() => setInviteResult(null)} />}

      <AdminPanelCard
        title={editing ? 'Ubah Karyawan' : 'Tambah Karyawan'}
        subtitle="Lengkapi identitas, organisasi, dan data kerja karyawan sebelum disimpan ke employee master."
        className="mb-3"
      >
          <div className="row g-3">
            <div className="col-md-3">
              <label className="form-label">Nama</label>
              <input className="form-control" value={form.name} onChange={(e) => updateForm('name', e.target.value)} />
            </div>
            <div className="col-md-2">
              <label className="form-label">NIK / ID legacy</label>
              <input className="form-control" value={form.nip} onChange={(e) => updateForm('nip', e.target.value)} placeholder="NIP baru dibuat otomatis" />
            </div>
            <div className="col-md-3">
              <label className="form-label">Email</label>
              <input className="form-control" type="email" value={form.email} onChange={(e) => updateForm('email', e.target.value)} />
            </div>
            <div className="col-md-2">
              <label className="form-label">Telepon</label>
              <input className="form-control" value={form.phone} onChange={(e) => updateForm('phone', e.target.value)} />
            </div>
            <div className="col-md-2">
              <label className="form-label">Password</label>
              <input className="form-control" type="password" value={form.password} onChange={(e) => updateForm('password', e.target.value)} placeholder={editing ? 'Opsional' : ''} />
            </div>
            <div className="col-md-2">
              <label className="form-label">Gender</label>
              <select className="form-select" value={form.gender} onChange={(e) => updateForm('gender', e.target.value)}>
                <option value="">Pilih</option>
                <option value="male">Laki-laki</option>
                <option value="female">Perempuan</option>
              </select>
            </div>
            <div className="col-md-2">
              <label className="form-label">Division</label>
              <select className="form-select" value={form.division_id} onChange={(e) => updateForm('division_id', e.target.value)}>
                <option value="">Pilih</option>
                {data?.references.divisions.map((division) => (
                  <option key={division.id} value={division.id}>{division.name}</option>
                ))}
              </select>
            </div>
            <div className="col-md-2">
              <label className="form-label">Job Title</label>
              <select className="form-select" value={form.job_title_id} onChange={(e) => updateForm('job_title_id', e.target.value)}>
                <option value="">Pilih</option>
                {formJobTitles.map((jobTitle) => (
                  <option key={jobTitle.id} value={jobTitle.id}>{jobTitle.name}</option>
                ))}
              </select>
            </div>
            <div className="col-md-2">
              <label className="form-label">Education</label>
              <select className="form-select" value={form.education_id} onChange={(e) => updateForm('education_id', e.target.value)}>
                <option value="">Pilih</option>
                {data?.references.educations.map((education) => (
                  <option key={education.id} value={education.id}>{education.name}</option>
                ))}
              </select>
            </div>
            <div className="col-md-2">
              <label className="form-label">Status</label>
              <select className="form-select" value={form.employment_status} onChange={(e) => updateForm('employment_status', e.target.value)}>
                <option value="">Pilih</option>
                {Object.entries(data?.references.employment_statuses ?? {}).map(([value, label]) => (
                  <option key={value} value={value}>{label}</option>
                ))}
              </select>
            </div>
            <div className="col-md-2">
              <label className="form-label">Gaji Bulanan</label>
              <input className="form-control" type="number" value={form.salary_monthly} onChange={(e) => updateForm('salary_monthly', e.target.value)} />
            </div>
            <div className="col-md-2">
              <label className="form-label">Join Date</label>
              <input className="form-control" type="date" value={form.join_date} onChange={(e) => updateForm('join_date', e.target.value)} />
            </div>
            <div className="col-md-2">
              <label className="form-label">Birth Date</label>
              <input className="form-control" type="date" value={form.birth_date} onChange={(e) => updateForm('birth_date', e.target.value)} />
            </div>
            <div className="col-md-2">
              <label className="form-label">Birth Place</label>
              <input className="form-control" value={form.birth_place} onChange={(e) => updateForm('birth_place', e.target.value)} />
            </div>
            <div className="col-md-2">
              <label className="form-label">Kota</label>
              <input className="form-control" value={form.city} onChange={(e) => updateForm('city', e.target.value)} />
            </div>
            <div className="col-md-2">
              <label className="form-label">Jatah Cuti</label>
              <input className="form-control" type="number" value={form.annual_leave_balance} onChange={(e) => updateForm('annual_leave_balance', e.target.value)} />
            </div>
            <div className="col-md-4">
              <label className="form-label">Alamat</label>
              <input className="form-control" value={form.address} onChange={(e) => updateForm('address', e.target.value)} />
            </div>
          </div>
          <div className="d-flex gap-2 mt-3 admin-form-actions">
            <button className="btn btn-dark btn-sm" onClick={submitForm} disabled={isSaving}>
              {isSaving ? 'Menyimpan...' : editing ? 'Simpan Perubahan' : 'Tambah Karyawan'}
            </button>
            {editing && (
              <button className="btn btn-outline-secondary btn-sm" onClick={resetForm}>
                Batal
              </button>
            )}
          </div>
      </AdminPanelCard>

      <AdminPanelCard
        title="Filter Employee"
        subtitle="Cari dan persempit employee list berdasarkan organisasi, pendidikan, dan status kerja."
        className="mb-3"
      >
          <div className="row g-3">
            <div className="col-md-4">
              <label className="form-label">Cari</label>
              <input
                className="form-control"
                value={search}
                onChange={(e) => { setSearch(e.target.value); setPage(1) }}
                placeholder="Nama, NIP, email, telepon"
              />
            </div>
            <div className="col-md-2">
              <label className="form-label">Division</label>
              <select
                className="form-select"
                value={divisionId}
                onChange={(e) => { setDivisionId(e.target.value); setJobTitleId(''); setPage(1) }}
              >
                <option value="">Semua</option>
                {data?.references.divisions.map((division) => (
                  <option key={division.id} value={division.id}>{division.name}</option>
                ))}
              </select>
            </div>
            <div className="col-md-2">
              <label className="form-label">Job Title</label>
              <select
                className="form-select"
                value={jobTitleId}
                onChange={(e) => { setJobTitleId(e.target.value); setPage(1) }}
              >
                <option value="">Semua</option>
                {jobTitles.map((jobTitle) => (
                  <option key={jobTitle.id} value={jobTitle.id}>{jobTitle.name}</option>
                ))}
              </select>
            </div>
            <div className="col-md-2">
              <label className="form-label">Education</label>
              <select
                className="form-select"
                value={educationId}
                onChange={(e) => { setEducationId(e.target.value); setPage(1) }}
              >
                <option value="">Semua</option>
                {data?.references.educations.map((education) => (
                  <option key={education.id} value={education.id}>{education.name}</option>
                ))}
              </select>
            </div>
            <div className="col-md-2">
              <label className="form-label">Status</label>
              <select
                className="form-select"
                value={employmentStatus}
                onChange={(e) => { setEmploymentStatus(e.target.value); setPage(1) }}
              >
                <option value="">Semua</option>
                {Object.entries(data?.references.employment_statuses ?? {}).map(([value, label]) => (
                  <option key={value} value={value}>{label}</option>
                ))}
              </select>
            </div>
            <div className="col-md-2 d-flex align-items-end">
              <div className="form-check">
                <input
                  className="form-check-input"
                  type="checkbox"
                  id="include-inactive-toggle"
                  checked={includeInactive}
                  onChange={(e) => { setIncludeInactive(e.target.checked); setPage(1) }}
                />
                <label className="form-check-label small text-muted" htmlFor="include-inactive-toggle">
                  Tampilkan karyawan resign
                </label>
              </div>
            </div>
          </div>
      </AdminPanelCard>

      <AdminTableCard
        title="Daftar Karyawan"
        subtitle="Ringkasan identitas, organisasi, status kerja, dan kompensasi dasar seluruh karyawan."
        className="employee-table-card"
        bodyClassName="p-0"
      >
          {isLoading ? (
            <div className="d-flex justify-content-center py-5">
              <div className="spinner-border text-primary spinner-border-sm" role="status" />
            </div>
          ) : isError ? (
            <div className="alert alert-danger feedback-alert feedback-alert--inline mb-0">
              Gagal memuat data karyawan.
            </div>
          ) : (
            <>
              <div className="table-responsive">
                <table className="table table-hover mb-0">
                  <thead>
                    <tr>
                      <th>Karyawan</th>
                      <th>Kontak</th>
                      <th>Organisasi</th>
                      <th>Status</th>
                      <th>Gaji</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    {data?.data.length === 0 && (
                      <tr>
                        <td colSpan={6} className="text-center text-muted py-4">Belum ada karyawan.</td>
                      </tr>
                    )}
                    {data?.data.map((employee) => (
                      <tr key={employee.id}>
                        <td>
                          <div className="fw-medium">{employee.name}</div>
                          <small className="text-muted">{employee.employee_number ? `NIP ${employee.employee_number}` : 'NIP sedang digenerate'}</small>
                          {employee.nik && <div><small className="text-muted">NIK {employee.nik}</small></div>}
                        </td>
                        <td>
                          <div>{employee.email}</div>
                          <small className="text-muted">{employee.phone ?? '-'}</small>
                        </td>
                        <td>
                          <div>{employee.division?.name ?? '-'}</div>
                          <small className="text-muted">
                            {employee.job_title?.name ?? '-'} · {employee.education?.name ?? '-'}
                          </small>
                        </td>
                        <td>
                          <span className={`badge ${employee.is_inactive ? 'bg-secondary' : 'bg-light text-dark'}`}>
                            {employee.employment_status_label
                              ?? (employee.employment_status
                                ? data?.references.employment_statuses[employee.employment_status] ?? employee.employment_status
                                : '-')}
                          </span>
                          <div>
                            {employee.is_inactive && employee.resigned_at ? (
                              <small className="text-danger">Resign: {employee.resigned_at}</small>
                            ) : (
                              <small className="text-muted">{employee.join_date ?? 'Join date kosong'}</small>
                            )}
                          </div>
                        </td>
                        <td>{formatCurrency(employee.salary_monthly)}</td>
                        <td className="text-end">
                          <div className="d-flex gap-1 justify-content-end">
                            {!employee.is_inactive ? (
                              <>
                                <button className="btn btn-outline-dark btn-sm" onClick={() => beginEdit(employee)}>
                                  Ubah
                                </button>
                                <button
                                  className="btn btn-outline-secondary btn-sm"
                                  title="Kirim ulang link undangan"
                                  disabled={regenInviteMutation.isPending}
                                  onClick={() => regenInviteMutation.mutate(employee)}
                                >
                                  Invite
                                </button>
                                <button
                                  className="btn btn-outline-danger btn-sm"
                                  title="Tandai karyawan resign / diberhentikan"
                                  onClick={() => setResigning(employee)}
                                >
                                  Resign
                                </button>
                              </>
                            ) : (
                              <button
                                className="btn btn-outline-success btn-sm"
                                title="Aktifkan karyawan kembali (re-hire)"
                                disabled={reactivateMutation.isPending}
                                onClick={() => {
                                  if (confirm(`Aktifkan kembali ${employee.name}? Status akan kembali jadi Permanent dan user perlu login ulang setelah reset password.`)) {
                                    reactivateMutation.mutate({ id: employee.id })
                                  }
                                }}
                              >
                                Aktifkan
                              </button>
                            )}
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

      {resigning && (
        <ResignModal
          employee={resigning}
          onClose={() => setResigning(null)}
          onSubmit={(payload) => resignMutation.mutate({ id: resigning.id, payload })}
          submitting={resignMutation.isPending}
        />
      )}
    </div>
  )
}

function ResignModal({ employee, onClose, onSubmit, submitting }: {
  employee: AdminEmployee
  onClose: () => void
  onSubmit: (p: ResignPayload) => void
  submitting: boolean
}) {
  const today = new Date().toISOString().slice(0, 10)
  const [type, setType] = useState<'resign' | 'terminated' | 'retired'>('resign')
  const [resignedAt, setResignedAt] = useState(today)
  const [lastWorking, setLastWorking] = useState('')
  const [reason, setReason] = useState('')

  return (
    <div
      role="dialog" aria-modal="true"
      style={{
        position: 'fixed', inset: 0, zIndex: 1050,
        background: 'rgba(0,0,0,0.55)',
        display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20,
      }}
    >
      <div className="bg-white rounded-3 shadow-lg" style={{ maxWidth: 500, width: '100%', padding: 24 }}>
        <h5 className="mb-3">Tandai Resign - {employee.name}</h5>
        <div className="alert alert-warning py-2 px-3 small">
          ⚠️ Setelah disimpan: data karyawan TETAP ada (attendance, payroll, kontrak),
          tapi semua sesi login langsung mati dan tidak bisa akses sistem lagi.
        </div>

        <div className="mb-2">
          <label className="form-label small">Jenis</label>
          <select className="form-select form-select-sm" value={type}
            onChange={(e) => setType(e.target.value as any)}>
            <option value="resign">Resign (mengundurkan diri)</option>
            <option value="terminated">Diberhentikan / PHK</option>
            <option value="retired">Pensiun</option>
          </select>
        </div>

        <div className="row g-2 mb-2">
          <div className="col-6">
            <label className="form-label small">Tanggal Resign</label>
            <input type="date" className="form-control form-control-sm"
              value={resignedAt}
              onChange={(e) => setResignedAt(e.target.value)} />
          </div>
          <div className="col-6">
            <label className="form-label small">Tgl Kerja Terakhir</label>
            <input type="date" className="form-control form-control-sm"
              value={lastWorking}
              max={resignedAt}
              placeholder={resignedAt}
              onChange={(e) => setLastWorking(e.target.value)} />
            <small className="text-muted">Kosong = sama dengan tanggal resign</small>
          </div>
        </div>

        <div className="mb-3">
          <label className="form-label small">Alasan (opsional)</label>
          <textarea className="form-control form-control-sm" rows={2}
            value={reason}
            placeholder="Mis. dapat pekerjaan baru, kontrak habis, dst."
            onChange={(e) => setReason(e.target.value)} />
        </div>

        <div className="d-flex justify-content-end gap-2">
          <button className="btn btn-secondary btn-sm" onClick={onClose} disabled={submitting}>Batal</button>
          <button
            className="btn btn-danger btn-sm"
            disabled={submitting || !resignedAt}
            onClick={() => onSubmit({
              resignation_type: type,
              resigned_at: resignedAt,
              last_working_date: lastWorking || null,
              reason: reason.trim() || null,
            })}
          >
            {submitting ? 'Menyimpan…' : 'Konfirmasi Resign'}
          </button>
        </div>
      </div>
    </div>
  )
}
