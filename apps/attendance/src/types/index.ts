import type { FaceCheck } from '@/utils/faceCheck'

// ── Auth & User ───────────────────────────────────────────────────────────────

export interface User {
  id: string
  nip: string | null
  employee_number: string | null
  nik: string | null
  name: string
  email: string
  phone: string | null
  gender: string | null
  group: 'user' | 'admin' | 'superadmin' | 'finance'
  employment_status: string | null
  join_date: string | null
  birth_date: string | null
  address: string | null
  city: string | null
  annual_leave_balance: number
  salary_advance_balance: number | null
  avatar: string | null
  division: { id: number; name: string } | null
  job_title: { id: number; name: string } | null
  company: {
    id: number
    name: string
    logo: string | null
    address: string | null
    city: string | null
    phone: string | null
    email: string | null
    finance_email: string | null
  } | null
  permissions: {
    is_admin: boolean
    is_superadmin: boolean
    capabilities: string[]
  }

  has_face_registered: boolean
  has_face_pending_approval: boolean
  attendance_location_mode: 'office_geofence' | 'mobile_field'
  is_permanent: boolean
}

export interface LoginPayload {
  identifier: string
  password: string
  device_name?: string
}

export interface InviteAcceptPayload {
  token: string
  password: string
  password_confirmation: string
}

export interface CreateEmployeeResult extends AdminEmployee {
  invite_token: string
  invite_expires_at: string
}

export interface AuthResponse {
  token: string
  token_type: 'Bearer'
  user: User
}

export interface SsoPlatform {
  key: 'erp' | 'attendance' | 'projects' | string
  name: string
  description: string
  enabled: boolean
  allowed: boolean
  status_label: string
}

export interface SsoGenerateResponse {
  target_app: string
  sso_token: string
  expires_at: string
  redirect_url: string
}

export interface SsoExchangeResponse {
  access_token: string
  token_type: 'Bearer'
  user: User
  platform: {
    key: string
    name: string
  }
}

// ── Pagination ────────────────────────────────────────────────────────────────

export interface Meta {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface PaginatedResponse<T> {
  data: T[]
  meta: Meta
}

export interface Division {
  id: number
  name: string
  users_count: number
  created_at: string
  updated_at: string
}

export interface JobTitle {
  id: number
  name: string
  division: { id: number; name: string } | null
  division_id: number | null
  users_count: number
  created_at: string
  updated_at: string
}

export interface Education {
  id: number
  name: string
  users_count: number
  created_at: string
  updated_at: string
}

export interface Shift {
  id: number
  name: string
  start_time: string
  end_time: string | null
  auto_shifts_count: number
  shift_mappings_count: number
  created_at: string
  updated_at: string
}

export interface InventoryCategoryReference {
  id: number
  name: string
}

export interface OfficeLocationReference {
  id: number
  name: string
}

export interface InventoryItem {
  id: number
  item_code: string | null
  item_name: string
  stock: string
  unit: string | null
  description: string | null
  category: InventoryCategoryReference | null
  office_location: OfficeLocationReference | null
  job_title: Pick<JobTitle, 'id' | 'name' | 'division_id'> | null
  created_at: string
  updated_at: string
}

export interface InventoryReferences {
  categories: InventoryCategoryReference[]
  office_locations: OfficeLocationReference[]
  job_titles: Array<Pick<JobTitle, 'id' | 'name' | 'division_id'>>
}

export interface AdminEmployee {
  id: string
  nip: string | null
  employee_number: string | null
  nik: string | null
  name: string
  email: string
  phone: string | null
  gender: string | null
  employment_status: string | null
  employment_status_label?: string
  is_inactive?: boolean
  resigned_at?: string | null
  last_working_date?: string | null
  resignation_type?: 'resign' | 'terminated' | 'retired' | null
  resignation_reason?: string | null
  join_date: string | null
  birth_date: string | null
  birth_place: string | null
  address: string | null
  city: string | null
  salary_monthly: number | null
  annual_leave_balance: number
  salary_advance_balance: number | null
  avatar: string | null
  division: { id: number; name: string } | null
  job_title: { id: number; name: string } | null
  education: { id: number; name: string } | null
  created_at: string
  updated_at: string
}

export interface AdminEmployeePayload {
  nip: string
  name: string
  email: string
  phone: string
  password?: string
  gender: string
  birth_date?: string | null
  birth_place?: string | null
  address: string
  city: string
  division_id?: number | null
  job_title_id?: number | null
  education_id?: number | null
  employment_status?: string | null
  salary_monthly?: number | null
  join_date?: string | null
  annual_leave_balance?: number
}

export interface EmployeeReferences {
  divisions: Pick<Division, 'id' | 'name'>[]
  job_titles: Array<Pick<JobTitle, 'id' | 'name' | 'division_id'> & { division?: { id: number; name: string } | null }>
  educations: Pick<Education, 'id' | 'name'>[]
  employment_statuses: Record<string, string>
}

export interface AuditLog {
  id: number
  action: string
  entity_type: string | null
  entity_id: string | null
  meta: Record<string, unknown> | null
  ip_address: string | null
  user_agent: string | null
  created_at: string
  actor: {
    id: string
    name: string
    email: string
    group: string
  } | null
}

// ── Leave ─────────────────────────────────────────────────────────────────────

export type LeaveStatus = 'pending' | 'approved' | 'rejected'
export type LeaveType = 'annual' | 'sick' | 'personal' | 'medical_checkup' | 'other'

export interface Leave {
  id: number
  leave_type: { key: LeaveType; label: string }
  start_date: string
  end_date: string
  days_count: number
  reason: string
  status: LeaveStatus
  notes: string | null
  attachment_url: string | null
  approved_at: string | null
  created_at: string
  user?: {
    id: string
    name: string
    nip: string | null
    division: string | null
    job_title: string | null
    avatar: string | null
  }
}

export interface LeaveListResponse extends PaginatedResponse<Leave> {
  quota: { annual_days: number; sick_days: number } | null
  annual_balance: number
  used_by_type: Record<string, number>
  pending_count?: number
  summary?: {
    total_requests: number
    total_days: number
    status_counts: {
      pending: number
      approved: number
      rejected: number
    }
    type_counts: {
      annual: number
      sick: number
      personal: number
      medical_checkup: number
      other: number
    }
  }
}

// ── Overtime ──────────────────────────────────────────────────────────────────

export type RequestStatus = 'pending' | 'approved' | 'rejected'

export interface Overtime {
  id: number
  date: string
  start_time: string
  end_time: string
  total_hours: number
  reason: string
  status: RequestStatus
  notes: string | null
  attachment_url: string | null
  created_at: string
}

// ── Attendance ────────────────────────────────────────────────────────────────

export type AttendanceStatus = 'present' | 'absent' | 'sick' | 'excused' | 'late'
export type AttendanceCalendarStatus = AttendanceStatus | 'holiday' | 'weekend' | 'no_record'

export interface Attendance {
  id: number
  date: string
  status: AttendanceStatus
  time_in: string | null
  time_out: string | null
  note?: string | null
  shift?: string | null
  location: { lat: number; lng: number } | null
  selfie_in_url: string | null
  selfie_out_url: string | null
  selfie_in_address?: string | null
  selfie_out_address?: string | null
  selfie_in_at?: string | null
  selfie_out_at?: string | null
  attachment_url?: string | null
  created_at: string
}

export interface AttendanceCalendarDay {
  id: number | null
  date: string
  status: AttendanceCalendarStatus
  label: string | null
  time_in: string | null
  time_out: string | null
  is_recorded: boolean
}

export interface AdminAttendanceRecord {
  id: number
  date: string
  status: AttendanceStatus
  time_in: string | null
  time_out: string | null
  shift?: string | null
  note?: string | null
  selfie_in_url?: string | null
  selfie_out_url?: string | null
  face_check_in?: FaceCheck | null
  face_check_out?: FaceCheck | null
}

export interface AdminAttendanceEmployee {
  id: string
  name: string
  nip: string | null
  email: string
  phone: string | null
  division: { id: number; name: string } | null
  job_title: { id: number; name: string; division_id: number | null } | null
  attendances: AdminAttendanceRecord[]
}

export interface AdminAttendanceReferences {
  divisions: Pick<Division, 'id' | 'name'>[]
  job_titles: Array<Pick<JobTitle, 'id' | 'name' | 'division_id'>>
}

export interface ShiftScheduleReference {
  shifts: Array<Pick<Shift, 'id' | 'name' | 'start_time' | 'end_time'>>
  job_titles: Array<Pick<JobTitle, 'id' | 'name' | 'division_id'>>
  employees: Array<Pick<AdminEmployee, 'id' | 'name' | 'nip'> & { division_id: number | null; job_title_id: number | null }>
}

export interface AutoShift {
  id: number
  job_title_id: number
  shift_id: number
  job_title: Pick<JobTitle, 'id' | 'name' | 'division_id'> | null
  shift: Pick<Shift, 'id' | 'name' | 'start_time' | 'end_time'> | null
  created_at: string
  updated_at: string
}

export interface ShiftMapping {
  id: number
  user_id: string
  shift_id: number
  date: string
  user: Pick<AdminEmployee, 'id' | 'name' | 'nip'> & { division_id: number | null; job_title_id: number | null }
  shift: Pick<Shift, 'id' | 'name' | 'start_time' | 'end_time'> | null
  created_at: string
  updated_at: string
}

export interface AdminPayroll {
  id: number
  payroll_number: string
  period: {
    month: number
    year: number
    label: string
    start: string | null
    end: string | null
  }
  base_salary: number
  gross_total: number
  deduction_total: number
  net_total: number
  attendance_count: number
  attendance_percentage: number
  overtime_total: number
  reimbursement_total: number
  salary_advance_payment: number
  status: string
  user: {
    id: string
    name: string
    nip: string | null
    email: string
    division: string | null
    job_title: string | null
  } | null
  created_at: string
  updated_at: string
}

export interface AdminPayrollReferences {
  employees: Array<Pick<AdminEmployee, 'id' | 'name' | 'nip' | 'email'> & { division_id: number | null; job_title_id: number | null }>
  statuses: Record<string, string>
}

export interface AdminPayrollSummary {
  payroll_count: number
  gross_total: number
  deduction_total: number
  net_total: number
  status_counts: {
    draft: number
    approved: number
    paid: number
  }
}

// ── Salary Advance ────────────────────────────────────────────────────────────

export interface SalaryAdvance {
  id: number
  date: string
  amount: number
  purpose: string
  /** @deprecated Legacy UI alias; API uses purpose. */
  reason?: string
  status: RequestStatus
  notes: string | null
  approved_at: string | null
  hr_approved_at?: string | null
  hr_notes?: string | null
  settled_at?: string | null
  payment_reference?: string | null
  journal_id?: string | null
  created_at: string
  user?: { id: string; name: string; nip: string | null; division: string | null } | null
  approved_by?: { id: string; name: string } | null
  hr_approved_by?: { id: string; name: string } | null
  settled_by?: { id: string; name: string } | null
}

// ── Reimbursement ─────────────────────────────────────────────────────────────

export interface ReimbursementCategory {
  id: number
  name: string
  limit_amount: number | null
}

export interface Reimbursement {
  id: number
  date: string
  category: ReimbursementCategory | null
  event: string
  unit_price: number
  quantity: number
  total_amount: number
  approved_amount: number | null
  status: RequestStatus | 'hr_approved' | 'settled' | 'cancelled' | string
  notes: string | null
  attachment_url: string | null
  approved_at: string | null
  created_at: string
  // Workflow 2-tahap
  bank_name?: string | null
  bank_account_number?: string | null
  bank_account_holder?: string | null
  hr_approved_at?: string | null
  hr_notes?: string | null
  settled_at?: string | null
  payment_reference?: string | null
  payment_proof_url?: string | null
  receipt_number?: string | null
  receipt_pdf_url?: string | null
  user?: { id: string; name: string; nip: string | null; division: string | null } | null
}

export interface ReimbursementListResponse extends PaginatedResponse<Reimbursement> {
  categories: ReimbursementCategory[]
}

// ── Dashboard ─────────────────────────────────────────────────────────────────

export interface DashboardData {
  attendance: Attendance | null
  shift: { id: number; name: string; start_time: string; end_time: string } | null
  annual_balance: number
  pending_leave_count: number
  pending_overtime_count: number
  pending_salary_advance_count: number
  latest_payroll: { id: number; period: string; net_salary: number; status: string } | null
  recent_leaves: Leave[]
  admin_summary: {
    pending_leave_count: number | null
    pending_overtime_count: number | null
    pending_salary_advance_count: number | null
    pending_reimbursement_count: number | null
    pending_face_approval_count: number | null
  } | null
  admin_reporting: {
    period: {
      month: string
      label: string
    }
    attendance_today: {
      total_employees: number
      checked_in: number
      late: number
      sick: number
      excused: number
      absent_or_missing: number
    } | null
    leave: {
      total_requests: number
      total_days: number
      status_counts: { pending: number; approved: number; rejected: number }
      type_counts: { annual: number; sick: number; personal: number; medical_checkup: number; other: number }
    } | null
    overtime: {
      total_requests: number
      total_hours: number
      average_hours: number
      status_counts: { pending: number; approved: number; rejected: number }
    } | null
    reimbursement: {
      total_requests: number
      requested_total: number
      approved_total: number
      average_request: number
      status_counts: { pending: number; approved: number; rejected: number }
    } | null
    salary_advance: {
      total_requests: number
      requested_total: number
      average_request: number
      status_counts: { pending: number; approved: number; rejected: number }
    } | null
    payroll: AdminPayrollSummary | null
  } | null
  recent_audit_logs: AuditLog[]
}
