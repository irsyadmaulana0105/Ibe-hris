import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { adminExportAttendanceRecap, adminListAttendances } from '@/api/admin'
import AdminPageHero from '@/components/admin/AdminPageHero'
import AdminPanelCard from '@/components/admin/AdminPanelCard'
import AdminTableCard from '@/components/admin/AdminTableCard'
import FeedbackAlert from '@/components/FeedbackAlert'
import Pagination from '@/components/Pagination'
import { usePageTitle } from '@/hooks/usePageTitle'
import type { AdminAttendanceRecord } from '@/types'
import { faceCheckSummary, isSeriousFaceCheck, type FaceCheck } from '@/utils/faceCheck'

const statusLabels: Record<string, string> = {
  present: 'Hadir',
  late: 'Terlambat',
  absent: 'Absen',
  sick: 'Sakit',
  excused: 'Izin',
}

function todayString() {
  return new Date().toISOString().slice(0, 10)
}

function monthString() {
  return new Date().toISOString().slice(0, 7)
}

function findRecord(records: AdminAttendanceRecord[], date: string) {
  return records.find((record) => record.date === date)
}

/** Tanda verifikasi wajah (masuk/pulang); tampil hanya bila ada tanda. */
function faceFlag(checks: Array<FaceCheck | null | undefined>) {
  const flagged = checks.filter((c): c is FaceCheck => !!c && c.flags?.length > 0)
  if (flagged.length === 0) return null
  const serious = flagged.some(isSeriousFaceCheck)
  return (
    <span
      className={`badge ms-1 ${serious ? 'bg-danger' : 'bg-warning text-dark'}`}
      style={{ cursor: 'help' }}
      title={flagged.map(faceCheckSummary).join('\n\n')}
    >
      {serious ? 'Cek wajah' : 'Tipis'}
    </span>
  )
}

function recordBadge(record?: AdminAttendanceRecord) {
  if (!record) {
    return <span className="badge bg-light text-muted">Kosong</span>
  }

  const variant = record.status === 'late'
    ? 'warning text-dark'
    : record.status === 'present'
      ? 'success'
      : record.status === 'absent'
        ? 'danger'
        : 'secondary'

  return (
    <div>
      <span className={`badge bg-${variant}`}>{statusLabels[record.status] ?? record.status}</span>
      {faceFlag([record.face_check_in, record.face_check_out])}
      <div className="text-muted mt-1" style={{ fontSize: 11 }}>
        {record.time_in ?? '--:--'} - {record.time_out ?? '--:--'}
      </div>
    </div>
  )
}

export default function AdminAttendancePage() {
  usePageTitle('Admin Absensi')
  const [page, setPage] = useState(1)
  const [mode, setMode] = useState<'date' | 'month'>('date')
  const [date, setDate] = useState(todayString())
  const [month, setMonth] = useState(monthString())
  const [search, setSearch] = useState('')
  const [divisionId, setDivisionId] = useState('')
  const [jobTitleId, setJobTitleId] = useState('')
  const [includeInactive, setIncludeInactive] = useState(false)
  const [successMsg, setSuccessMsg] = useState('')
  const [errorMsg, setErrorMsg] = useState('')

  const { data, isLoading, isError } = useQuery({
    queryKey: ['admin', 'attendances', page, mode, date, month, search, divisionId, jobTitleId, includeInactive],
    queryFn: () => adminListAttendances({
      page: String(page),
      ...(mode === 'date' ? { date } : { month }),
      ...(search ? { search } : {}),
      ...(divisionId ? { division: divisionId } : {}),
      ...(jobTitleId ? { job_title: jobTitleId } : {}),
      ...(includeInactive ? { include_inactive: '1' } : {}),
    }),
  })

  const jobTitles = data?.references.job_titles.filter((jobTitle) => (
    !divisionId || String(jobTitle.division_id) === divisionId
  )) ?? []

  async function handleExport() {
    try {
      setErrorMsg('')
      const params = {
        ...(mode === 'date' ? { date } : { month }),
        ...(search ? { search } : {}),
        ...(divisionId ? { division: divisionId } : {}),
        ...(jobTitleId ? { job_title: jobTitleId } : {}),
        ...(includeInactive ? { include_inactive: '1' } : {}),
      }
      const blob = await adminExportAttendanceRecap(params)
      const url = window.URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = `attendance-recap-${mode === 'date' ? date : month}.csv`
      document.body.appendChild(link)
      link.click()
      link.remove()
      window.URL.revokeObjectURL(url)
      setSuccessMsg('Rekap absensi berhasil diunduh.')
    } catch (error: any) {
      setErrorMsg(error?.response?.data?.message ?? 'Gagal mengunduh rekap absensi.')
      setSuccessMsg('')
    }
  }

  return (
    <div className="admin-page-shell">
      <AdminPageHero
        eyebrow="Workforce Workspace"
        title="Attendance Command Center"
        subtitle="Monitoring kehadiran per tanggal atau bulan, lengkap dengan filter organisasi dan ekspor rekap."
        variant="workforce"
        action={(
          <button className="btn btn-outline-dark btn-sm" onClick={handleExport}>
            Export CSV
          </button>
        )}
      />

      <FeedbackAlert type="success" message={successMsg} />
      <FeedbackAlert type="danger" message={errorMsg} />

      <AdminPanelCard
        title="Filter Attendance"
        subtitle="Atur mode periode, cari karyawan, dan persempit berdasarkan struktur organisasi."
        className="mb-3"
      >
          <div className="row g-3">
            <div className="col-md-2">
              <label className="form-label">Periode</label>
              <select
                className="form-select"
                value={mode}
                onChange={(e) => { setMode(e.target.value as 'date' | 'month'); setPage(1) }}
              >
                <option value="date">Tanggal</option>
                <option value="month">Bulan</option>
              </select>
            </div>
            <div className="col-md-2">
              <label className="form-label">{mode === 'date' ? 'Tanggal' : 'Bulan'}</label>
              <input
                className="form-control"
                type={mode}
                value={mode === 'date' ? date : month}
                onChange={(e) => {
                  if (mode === 'date') setDate(e.target.value)
                  else setMonth(e.target.value)
                  setPage(1)
                }}
              />
            </div>
            <div className="col-md-3">
              <label className="form-label">Cari</label>
              <input
                className="form-control"
                value={search}
                onChange={(e) => { setSearch(e.target.value); setPage(1) }}
                placeholder="Nama atau NIP"
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
            <div className="col-md-3">
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
            <div className="col-md-3 d-flex align-items-end">
              <div className="form-check">
                <input
                  className="form-check-input"
                  type="checkbox"
                  id="attendance-include-inactive"
                  checked={includeInactive}
                  onChange={(e) => { setIncludeInactive(e.target.checked); setPage(1) }}
                />
                <label className="form-check-label small text-muted" htmlFor="attendance-include-inactive">
                  Tampilkan karyawan resign (untuk audit historis)
                </label>
              </div>
            </div>
          </div>
      </AdminPanelCard>

      <AdminTableCard
        title="Grid Kehadiran"
        subtitle="Tampilan absensi karyawan berdasarkan periode aktif dengan status dan jam masuk/pulang."
        className="attendance-grid-card"
        bodyClassName="p-0"
      >
          {isLoading ? (
            <div className="d-flex justify-content-center py-5">
              <div className="spinner-border text-primary spinner-border-sm" role="status" />
            </div>
          ) : isError ? (
            <div className="alert alert-danger feedback-alert feedback-alert--inline mb-0">
              Gagal memuat data absensi.
            </div>
          ) : (
            <>
              <div className="attendance-grid-hint px-3 pt-3">
                Geser ke samping untuk melihat seluruh tanggal pada grid absensi.
              </div>
              <div className="table-responsive attendance-grid-table">
                <table className="table table-hover mb-0 align-middle">
                  <thead>
                    <tr>
                      <th style={{ minWidth: 220 }}>Karyawan</th>
                      <th style={{ minWidth: 180 }}>Organisasi</th>
                      {data?.dates.map((periodDate) => (
                        <th key={periodDate} className="text-center" style={{ minWidth: 120 }}>
                          {new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short' }).format(new Date(periodDate))}
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {data?.data.length === 0 && (
                      <tr>
                        <td colSpan={(data?.dates.length ?? 0) + 2} className="text-center text-muted py-4">
                          Tidak ada karyawan pada filter ini.
                        </td>
                      </tr>
                    )}
                    {data?.data.map((employee) => (
                      <tr key={employee.id}>
                        <td>
                          <div className="fw-medium">{employee.name}</div>
                          <small className="text-muted">{employee.nip ? `NIP ${employee.nip}` : employee.email}</small>
                        </td>
                        <td>
                          <div>{employee.division?.name ?? '-'}</div>
                          <small className="text-muted">{employee.job_title?.name ?? '-'}</small>
                        </td>
                        {data?.dates.map((periodDate) => (
                          <td key={`${employee.id}-${periodDate}`} className="text-center">
                            {recordBadge(findRecord(employee.attendances ?? [], periodDate))}
                          </td>
                        ))}
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
