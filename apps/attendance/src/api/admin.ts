import client from './client'
import type { EnrollmentQuality } from '@/utils/faceCheck'
import type { AdminAttendanceEmployee, AdminAttendanceReferences, AdminEmployee, AdminEmployeePayload, AdminPayroll, AdminPayrollReferences, AdminPayrollSummary, Attendance, AuditLog, AutoShift, CreateEmployeeResult, EmployeeReferences, Leave, LeaveListResponse, Overtime, PaginatedResponse, Reimbursement, SalaryAdvance, ShiftMapping, ShiftScheduleReference } from '@/types'

// ── Company ───────────────────────────────────────────────────────────────────

export interface CompanyDetail {
  id: number
  name: string
  address: string | null
  city: string | null
  phone: string | null
  email: string | null
  finance_email: string | null
  logo_url: string | null
  is_active: boolean
}

export async function getCompany(): Promise<CompanyDetail> {
  const { data } = await client.get<{ data: CompanyDetail }>('/admin/company')
  return data.data
}

export async function updateCompany(form: FormData): Promise<CompanyDetail> {
  const { data } = await client.post<{ data: CompanyDetail }>('/admin/company', form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data.data
}

// ── Leave ─────────────────────────────────────────────────────────────────────

export async function adminListLeaves(params?: Record<string, string>): Promise<LeaveListResponse> {
  const { data } = await client.get<LeaveListResponse>('/admin/leaves', { params })
  return data
}

export async function adminApproveLeave(id: number, notes?: string): Promise<Leave> {
  const { data } = await client.post<{ data: Leave }>(`/admin/leaves/${id}/approve`, { notes })
  return data.data
}

export async function adminRejectLeave(id: number, notes?: string): Promise<Leave> {
  const { data } = await client.post<{ data: Leave }>(`/admin/leaves/${id}/reject`, { notes })
  return data.data
}

// ── Overtime ──────────────────────────────────────────────────────────────────

export async function adminListOvertimes(params?: Record<string, string>): Promise<PaginatedResponse<Overtime> & {
  pending_count: number
  summary?: {
    total_requests: number
    total_hours: number
    average_hours: number
    status_counts: {
      pending: number
      approved: number
      rejected: number
    }
  }
}> {
  const { data } = await client.get('/admin/overtimes', { params })
  return data
}

export async function adminApproveOvertime(id: number, notes?: string): Promise<Overtime> {
  const { data } = await client.post<{ data: Overtime }>(`/admin/overtimes/${id}/approve`, { notes })
  return data.data
}

export async function adminRejectOvertime(id: number, notes?: string): Promise<Overtime> {
  const { data } = await client.post<{ data: Overtime }>(`/admin/overtimes/${id}/reject`, { notes })
  return data.data
}

// ── Salary Advance ────────────────────────────────────────────────────────────

export async function adminListSalaryAdvances(params?: Record<string, string>): Promise<PaginatedResponse<SalaryAdvance> & { pending_count: number }> {
  const { data } = await client.get('/admin/salary-advances', { params })
  return data
}

export async function adminApproveSalaryAdvance(id: number, notes?: string): Promise<SalaryAdvance> {
  const { data } = await client.post<{ data: SalaryAdvance }>(`/admin/salary-advances/${id}/approve`, { notes })
  return data.data
}

export async function adminRejectSalaryAdvance(id: number, notes?: string): Promise<SalaryAdvance> {
  const { data } = await client.post<{ data: SalaryAdvance }>(`/admin/salary-advances/${id}/reject`, { notes })
  return data.data
}

export async function adminSettleSalaryAdvance(id: number, reference: string): Promise<SalaryAdvance> {
  const { data } = await client.post<{ data: SalaryAdvance }>(`/admin/salary-advances/${id}/settle`, { reference }, {
    headers: { 'Idempotency-Key': crypto.randomUUID() },
  })
  return data.data
}

// ── Reimbursement ─────────────────────────────────────────────────────────────

export async function adminListReimbursements(params?: Record<string, string>): Promise<PaginatedResponse<Reimbursement> & {
  pending_count: number
  summary?: {
    total_requests: number
    requested_total: number
    approved_total: number
    average_request: number
    status_counts: {
      pending: number
      approved: number
      rejected: number
    }
  }
}> {
  const { data } = await client.get('/admin/reimbursements', { params })
  return data
}

export async function adminApproveReimbursement(id: number, notes?: string, approvedAmount?: number): Promise<Reimbursement> {
  const { data } = await client.post<{ data: Reimbursement }>(`/admin/reimbursements/${id}/approve`, {
    notes,
    ...(approvedAmount !== undefined ? { approved_amount: approvedAmount } : {}),
  })
  return data.data
}

export async function adminRejectReimbursement(id: number, notes?: string): Promise<Reimbursement> {
  const { data } = await client.post<{ data: Reimbursement }>(`/admin/reimbursements/${id}/reject`, { notes })
  return data.data
}

// ── Payroll ─────────────────────────────────────────────────────────────────

export async function adminListPayrolls(params?: Record<string, string>): Promise<PaginatedResponse<AdminPayroll> & { references: AdminPayrollReferences; summary: AdminPayrollSummary }> {
  const { data } = await client.get('/admin/payrolls', { params })
  return data
}

export async function adminGeneratePayroll(payload: { user_id: string; month: string; bonus_extra?: number }): Promise<AdminPayroll> {
  const { data } = await client.post<{ data: AdminPayroll }>('/admin/payrolls', payload)
  return data.data
}

export async function adminBulkGeneratePayroll(payload: { month: string; bonus_extra?: number }): Promise<{ generated_count: number }> {
  const { data } = await client.post<{ data: { generated_count: number } }>('/admin/payrolls/bulk-generate', payload)
  return data.data
}

export async function adminApprovePayroll(id: number): Promise<AdminPayroll> {
  const { data } = await client.post<{ data: AdminPayroll }>(`/admin/payrolls/${id}/approve`)
  return data.data
}

export async function adminMarkPayrollPaid(id: number): Promise<AdminPayroll> {
  const { data } = await client.post<{ data: AdminPayroll }>(`/admin/payrolls/${id}/mark-paid`)
  return data.data
}

export async function adminExportPayrollReport(params?: Record<string, string>): Promise<Blob> {
  const response = await client.get('/admin/payrolls/export', {
    params,
    responseType: 'blob',
    headers: {
      Accept: 'text/csv',
    },
  })

  return response.data
}

export async function adminDownloadPayrollSlip(id: number): Promise<Blob> {
  const response = await client.get(`/admin/payrolls/${id}/slip`, {
    responseType: 'blob',
    headers: {
      Accept: 'application/pdf',
    },
  })

  return response.data
}

export async function adminSendPayrollSlipEmail(id: number): Promise<{ recipient_email: string; bcc_emails: string[] }> {
  const { data } = await client.post<{ data: { recipient_email: string; bcc_emails: string[] } }>(`/admin/payrolls/${id}/send-slip-email`)
  return data.data
}

// ── Attendance ────────────────────────────────────────────────────────────────

export async function adminListAttendances(params?: Record<string, string>): Promise<PaginatedResponse<AdminAttendanceEmployee> & { dates: string[]; references: AdminAttendanceReferences }> {
  const { data } = await client.get('/admin/attendances', { params })
  return data
}

export async function adminExportAttendanceRecap(params?: Record<string, string>): Promise<Blob> {
  const response = await client.get('/admin/attendances/export', {
    params,
    responseType: 'blob',
    headers: {
      Accept: 'text/csv',
    },
  })

  return response.data
}

export async function adminGetAttendance(id: number): Promise<Attendance> {
  const { data } = await client.get<{ data: Attendance }>(`/admin/attendances/${id}`)
  return data.data
}

// ── Shift Scheduling ─────────────────────────────────────────────────────────

export async function adminGetShiftScheduleReferences(): Promise<ShiftScheduleReference> {
  const { data } = await client.get<{ data: ShiftScheduleReference }>('/admin/shift-schedules/references')
  return data.data
}

export async function adminListAutoShifts(params?: Record<string, string>): Promise<PaginatedResponse<AutoShift>> {
  const { data } = await client.get('/admin/shift-schedules/auto-shifts', { params })
  return data
}

export async function adminSaveAutoShift(payload: { job_title_id: number; shift_id: number }): Promise<AutoShift> {
  const { data } = await client.post<{ data: AutoShift }>('/admin/shift-schedules/auto-shifts', payload)
  return data.data
}

export async function adminDeleteAutoShift(id: number): Promise<void> {
  await client.delete(`/admin/shift-schedules/auto-shifts/${id}`)
}

export async function adminListShiftMappings(params?: Record<string, string>): Promise<PaginatedResponse<ShiftMapping>> {
  const { data } = await client.get('/admin/shift-schedules/mappings', { params })
  return data
}

export async function adminSaveShiftMapping(payload: { user_id: string; shift_id: number; date: string }): Promise<ShiftMapping> {
  const { data } = await client.post<{ data: ShiftMapping }>('/admin/shift-schedules/mappings', payload)
  return data.data
}

export async function adminDeleteShiftMapping(id: number): Promise<void> {
  await client.delete(`/admin/shift-schedules/mappings/${id}`)
}

// ── Employees ────────────────────────────────────────────────────────────────

export async function adminListEmployees(params?: Record<string, string>): Promise<PaginatedResponse<AdminEmployee> & { references: EmployeeReferences }> {
  const { data } = await client.get('/admin/employees', { params })
  return data
}

export async function adminGetEmployee(id: string): Promise<AdminEmployee> {
  const { data } = await client.get<{ data: AdminEmployee }>(`/admin/employees/${id}`)
  return data.data
}

export async function adminCreateEmployee(payload: AdminEmployeePayload): Promise<CreateEmployeeResult> {
  const { data } = await client.post<{ data: CreateEmployeeResult }>('/admin/employees', payload)
  return data.data
}

export async function adminUpdateEmployee(id: string, payload: AdminEmployeePayload): Promise<AdminEmployee> {
  const { data } = await client.put<{ data: AdminEmployee }>(`/admin/employees/${id}`, payload)
  return data.data
}

export async function adminRegenerateInvite(id: string): Promise<{ invite_token: string; invite_expires_at: string }> {
  const { data } = await client.post<{ data: { invite_token: string; invite_expires_at: string } }>(`/admin/employees/${id}/regenerate-invite`)
  return data.data
}

export interface ResignPayload {
  resignation_type: 'resign' | 'terminated' | 'retired'
  resigned_at: string                  // YYYY-MM-DD
  last_working_date?: string | null    // YYYY-MM-DD, default = resigned_at
  reason?: string | null
}

export async function adminResignEmployee(id: string, payload: ResignPayload): Promise<AdminEmployee> {
  const { data } = await client.post<{ data: AdminEmployee }>(`/admin/employees/${id}/resign`, payload)
  return data.data
}

export async function adminReactivateEmployee(id: string, employment_status?: string): Promise<AdminEmployee> {
  const { data } = await client.post<{ data: AdminEmployee }>(`/admin/employees/${id}/reactivate`,
    employment_status ? { employment_status } : {})
  return data.data
}

// ── Face Approval ─────────────────────────────────────────────────────────────

export interface FacePendingUser {
  id: string
  name: string
  nip: string | null
  email: string
  avatar: string | null
  has_active: boolean
  // Sejak 19 Okt 2026: foto yang BENAR-BENAR didaftarkan (URL bertanda tangan).
  enrollment_photo_url?: string | null
  active_photo_url?: string | null
  enrollment_quality?: EnrollmentQuality | null
  submitted_at?: string | null
}

export async function adminListFaceApprovals(): Promise<FacePendingUser[]> {
  const { data } = await client.get<{ data: FacePendingUser[] }>('/admin/face-approvals')
  return data.data
}

export async function adminApproveFace(id: string): Promise<void> {
  await client.post(`/admin/face-approvals/${id}/approve`)
}

export async function adminRejectFace(id: string): Promise<void> {
  await client.post(`/admin/face-approvals/${id}/reject`)
}

// ── Audit Logs ───────────────────────────────────────────────────────────────

export async function adminListAuditLogs(params?: Record<string, string>): Promise<PaginatedResponse<AuditLog>> {
  const { data } = await client.get('/admin/audit-logs', { params })
  return data
}
