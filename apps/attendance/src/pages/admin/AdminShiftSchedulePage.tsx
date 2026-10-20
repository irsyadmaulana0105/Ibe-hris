import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  adminDeleteAutoShift,
  adminDeleteShiftMapping,
  adminGetShiftScheduleReferences,
  adminListAutoShifts,
  adminListShiftMappings,
  adminSaveAutoShift,
  adminSaveShiftMapping,
} from '@/api/admin'
import AdminPageHero from '@/components/admin/AdminPageHero'
import AdminPanelCard from '@/components/admin/AdminPanelCard'
import AdminTableCard from '@/components/admin/AdminTableCard'
import FeedbackAlert from '@/components/FeedbackAlert'
import Pagination from '@/components/Pagination'
import { usePageTitle } from '@/hooks/usePageTitle'

function todayString() {
  return new Date().toISOString().slice(0, 10)
}

function monthString() {
  return new Date().toISOString().slice(0, 7)
}

function shiftLabel(shift?: { name: string; start_time: string; end_time: string | null } | null) {
  if (!shift) return '-'
  return `${shift.name} (${shift.start_time.slice(0, 5)}-${shift.end_time?.slice(0, 5) ?? '--:--'})`
}

export default function AdminShiftSchedulePage() {
  usePageTitle('Shift Scheduling')
  const qc = useQueryClient()
  const [autoPage, setAutoPage] = useState(1)
  const [mappingPage, setMappingPage] = useState(1)
  const [autoJobTitleId, setAutoJobTitleId] = useState('')
  const [autoShiftId, setAutoShiftId] = useState('')
  const [mappingUserId, setMappingUserId] = useState('')
  const [mappingShiftId, setMappingShiftId] = useState('')
  const [mappingDate, setMappingDate] = useState(todayString())
  const [mappingMonth, setMappingMonth] = useState(monthString())
  const [successMsg, setSuccessMsg] = useState('')
  const [errorMsg, setErrorMsg] = useState('')

  const references = useQuery({
    queryKey: ['admin', 'shift-schedules', 'references'],
    queryFn: adminGetShiftScheduleReferences,
  })

  const autoShifts = useQuery({
    queryKey: ['admin', 'shift-schedules', 'auto-shifts', autoPage],
    queryFn: () => adminListAutoShifts({ page: String(autoPage) }),
  })

  const mappings = useQuery({
    queryKey: ['admin', 'shift-schedules', 'mappings', mappingPage, mappingMonth],
    queryFn: () => adminListShiftMappings({ page: String(mappingPage), month: mappingMonth }),
  })

  const saveAutoShift = useMutation({
    mutationFn: adminSaveAutoShift,
    onSuccess: () => {
      setAutoJobTitleId('')
      setAutoShiftId('')
      setSuccessMsg('Default shift berhasil disimpan.')
      setErrorMsg('')
      qc.invalidateQueries({ queryKey: ['admin', 'shift-schedules', 'auto-shifts'] })
    },
    onError: (error: any) => {
      setErrorMsg(error?.response?.data?.message ?? 'Gagal menyimpan default shift.')
      setSuccessMsg('')
    },
  })

  const saveMapping = useMutation({
    mutationFn: adminSaveShiftMapping,
    onSuccess: () => {
      setMappingUserId('')
      setMappingShiftId('')
      setSuccessMsg('Mapping shift berhasil disimpan.')
      setErrorMsg('')
      qc.invalidateQueries({ queryKey: ['admin', 'shift-schedules', 'mappings'] })
    },
    onError: (error: any) => {
      setErrorMsg(error?.response?.data?.message ?? 'Gagal menyimpan mapping shift.')
      setSuccessMsg('')
    },
  })

  const deleteAutoShift = useMutation({
    mutationFn: adminDeleteAutoShift,
    onSuccess: () => {
      setSuccessMsg('Default shift berhasil dihapus.')
      setErrorMsg('')
      qc.invalidateQueries({ queryKey: ['admin', 'shift-schedules', 'auto-shifts'] })
    },
  })

  const deleteMapping = useMutation({
    mutationFn: adminDeleteShiftMapping,
    onSuccess: () => {
      setSuccessMsg('Mapping shift berhasil dihapus.')
      setErrorMsg('')
      qc.invalidateQueries({ queryKey: ['admin', 'shift-schedules', 'mappings'] })
    },
  })

  function submitAutoShift() {
    if (!autoJobTitleId || !autoShiftId) {
      setErrorMsg('Job title dan shift wajib dipilih.')
      setSuccessMsg('')
      return
    }
    saveAutoShift.mutate({ job_title_id: Number(autoJobTitleId), shift_id: Number(autoShiftId) })
  }

  function submitMapping() {
    if (!mappingUserId || !mappingShiftId || !mappingDate) {
      setErrorMsg('Karyawan, shift, dan tanggal wajib dipilih.')
      setSuccessMsg('')
      return
    }
    saveMapping.mutate({ user_id: mappingUserId, shift_id: Number(mappingShiftId), date: mappingDate })
  }

  return (
    <div className="admin-page-shell">
      <AdminPageHero
        eyebrow="Workforce Workspace"
        title="Shift Scheduling"
        subtitle="Default shift per job title dan override shift karyawan per tanggal untuk proses attendance."
        variant="workforce"
      />

      <FeedbackAlert type="success" message={successMsg} />
      <FeedbackAlert type="danger" message={errorMsg} />

      <div className="row g-3 mb-3">
        <div className="col-lg-6">
          <AdminPanelCard
            title="Default Shift per Job Title"
            subtitle="Atur baseline shift berdasarkan jabatan agar attendance tidak selalu bergantung pada override manual."
            className="h-100"
          >
              <div className="row g-3">
                <div className="col-md-6">
                  <label className="form-label">Job Title</label>
                  <select className="form-select" value={autoJobTitleId} onChange={(e) => setAutoJobTitleId(e.target.value)}>
                    <option value="">Pilih</option>
                    {references.data?.job_titles.map((jobTitle) => (
                      <option key={jobTitle.id} value={jobTitle.id}>{jobTitle.name}</option>
                    ))}
                  </select>
                </div>
                <div className="col-md-6">
                  <label className="form-label">Shift</label>
                  <select className="form-select" value={autoShiftId} onChange={(e) => setAutoShiftId(e.target.value)}>
                    <option value="">Pilih</option>
                    {references.data?.shifts.map((shift) => (
                      <option key={shift.id} value={shift.id}>{shiftLabel(shift)}</option>
                    ))}
                  </select>
                </div>
              </div>
              <button className="btn btn-dark btn-sm mt-3 admin-inline-button" onClick={submitAutoShift} disabled={saveAutoShift.isPending}>
                {saveAutoShift.isPending ? 'Menyimpan...' : 'Simpan Default Shift'}
              </button>
          </AdminPanelCard>
        </div>

        <div className="col-lg-6">
          <AdminPanelCard
            title="Override Shift per Tanggal"
            subtitle="Tetapkan shift khusus per karyawan untuk kebutuhan operasional harian."
            className="h-100"
          >
              <div className="row g-3">
                <div className="col-md-5">
                  <label className="form-label">Karyawan</label>
                  <select className="form-select" value={mappingUserId} onChange={(e) => setMappingUserId(e.target.value)}>
                    <option value="">Pilih</option>
                    {references.data?.employees.map((employee) => (
                      <option key={employee.id} value={employee.id}>
                        {employee.name}{employee.nip ? ` - ${employee.nip}` : ''}
                      </option>
                    ))}
                  </select>
                </div>
                <div className="col-md-4">
                  <label className="form-label">Shift</label>
                  <select className="form-select" value={mappingShiftId} onChange={(e) => setMappingShiftId(e.target.value)}>
                    <option value="">Pilih</option>
                    {references.data?.shifts.map((shift) => (
                      <option key={shift.id} value={shift.id}>{shiftLabel(shift)}</option>
                    ))}
                  </select>
                </div>
                <div className="col-md-3">
                  <label className="form-label">Tanggal</label>
                  <input className="form-control" type="date" value={mappingDate} onChange={(e) => setMappingDate(e.target.value)} />
                </div>
              </div>
              <button className="btn btn-dark btn-sm mt-3 admin-inline-button" onClick={submitMapping} disabled={saveMapping.isPending}>
                {saveMapping.isPending ? 'Menyimpan...' : 'Simpan Mapping'}
              </button>
          </AdminPanelCard>
        </div>
      </div>

      <div className="row g-3">
        <div className="col-lg-6">
          <AdminTableCard
            title="Daftar Default Shift"
            subtitle="Mapping default shift berdasarkan job title."
            bodyClassName="p-0"
          >
              <div className="table-responsive">
                <table className="table table-hover mb-0">
                  <thead>
                    <tr>
                      <th>Job Title</th>
                      <th>Shift</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    {autoShifts.data?.data.length === 0 && (
                      <tr><td colSpan={3} className="text-center text-muted py-4">Belum ada default shift.</td></tr>
                    )}
                    {autoShifts.data?.data.map((row) => (
                      <tr key={row.id}>
                        <td>{row.job_title?.name ?? '-'}</td>
                        <td>{shiftLabel(row.shift)}</td>
                        <td className="text-end">
                          <button className="btn btn-outline-danger btn-sm" onClick={() => deleteAutoShift.mutate(row.id)}>
                            Hapus
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {autoShifts.data?.meta && (
                <div className="px-3 pb-3">
                  <Pagination meta={autoShifts.data.meta} onPageChange={setAutoPage} />
                </div>
              )}
          </AdminTableCard>
        </div>

        <div className="col-lg-6">
          <AdminTableCard
            title="Daftar Mapping Shift"
            subtitle="Override shift individual per tanggal pada bulan yang dipilih."
            bodyClassName="p-0"
          >
              <div className="px-3 pt-3 shift-schedule-month-filter">
                <input
                  className="form-control form-control-sm"
                  type="month"
                  value={mappingMonth}
                  onChange={(e) => { setMappingMonth(e.target.value); setMappingPage(1) }}
                />
              </div>
              <div className="table-responsive">
                <table className="table table-hover mb-0">
                  <thead>
                    <tr>
                      <th>Tanggal</th>
                      <th>Karyawan</th>
                      <th>Shift</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    {mappings.data?.data.length === 0 && (
                      <tr><td colSpan={4} className="text-center text-muted py-4">Belum ada mapping shift.</td></tr>
                    )}
                    {mappings.data?.data.map((row) => (
                      <tr key={row.id}>
                        <td>{row.date}</td>
                        <td>{row.user?.name ?? '-'}</td>
                        <td>{shiftLabel(row.shift)}</td>
                        <td className="text-end">
                          <button className="btn btn-outline-danger btn-sm" onClick={() => deleteMapping.mutate(row.id)}>
                            Hapus
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {mappings.data?.meta && (
                <div className="px-3 pb-3">
                  <Pagination meta={mappings.data.meta} onPageChange={setMappingPage} />
                </div>
              )}
          </AdminTableCard>
        </div>
      </div>
    </div>
  )
}
