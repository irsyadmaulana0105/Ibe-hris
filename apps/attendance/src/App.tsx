import { lazy, Suspense, useEffect, useState, type ReactNode } from 'react'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { AuthProvider, useAuth } from '@/context/AuthContext'
import { ProtectedRoute, AdminRoute } from '@/components/ProtectedRoute'
import AppLayout from '@/components/AppLayout'
import SplashScreen from '@/components/SplashScreen'

const PlatformLaunchPage = lazy(() => import('@/pages/PlatformLaunchPage'))
const LoginPage = lazy(() => import('@/pages/LoginPage'))
const SsoCallbackPage = lazy(() => import('@/pages/SsoCallbackPage'))
const AcceptInvitePage = lazy(() => import('@/pages/AcceptInvitePage'))
const ClaimEmailPage = lazy(() => import('@/pages/ClaimEmailPage'))
const IdCardPage = lazy(() => import('@/pages/IdCardPage'))
const WorkCertificatePage = lazy(() => import('@/pages/WorkCertificatePage'))
const ProfileEditPage = lazy(() => import('@/pages/ProfileEditPage'))
const ContractSignPage = lazy(() => import('@/pages/ContractSignPage'))
const DashboardPage = lazy(() => import('@/pages/DashboardPage'))
const AttendancePage = lazy(() => import('@/pages/AttendancePage'))
const FaceSetupPage = lazy(() => import('@/pages/FaceSetupPage'))
const AttendanceHistoryPage = lazy(() => import('@/pages/employee/AttendanceHistoryPage'))
const LeavePage = lazy(() => import('@/pages/employee/LeavePage'))
const OvertimePage = lazy(() => import('@/pages/employee/OvertimePage'))
const SalaryAdvancePage = lazy(() => import('@/pages/employee/SalaryAdvancePage'))
const ReimbursementPage = lazy(() => import('@/pages/employee/ReimbursementPage'))
const PayslipPage = lazy(() => import('@/pages/employee/PayslipPage'))
const BusinessTripPage = lazy(() => import('@/pages/employee/BusinessTripPage'))
const ClientVisitPage = lazy(() => import('@/pages/employee/ClientVisitPage'))
const AdminLeavePage = lazy(() => import('@/pages/admin/AdminLeavePage'))
const AdminOvertimePage = lazy(() => import('@/pages/admin/AdminOvertimePage'))
const AdminSalaryAdvancePage = lazy(() => import('@/pages/admin/AdminSalaryAdvancePage'))
const AdminReimbursementPage = lazy(() => import('@/pages/admin/AdminReimbursementPage'))
const AdminPayrollPage = lazy(() => import('@/pages/admin/AdminPayrollPage'))
const AdminLateDeductionPage = lazy(() => import('@/pages/admin/AdminLateDeductionPage'))
const AdminFacePage = lazy(() => import('@/pages/admin/AdminFacePage'))
const AdminEmployeePage = lazy(() => import('@/pages/admin/AdminEmployeePage'))
const AdminAttendancePage = lazy(() => import('@/pages/admin/AdminAttendancePage'))
const AdminShiftSchedulePage = lazy(() => import('@/pages/admin/AdminShiftSchedulePage'))
const AdminCompanyPage = lazy(() => import('@/pages/admin/AdminCompanyPage'))
const AdminAuditLogPage = lazy(() => import('@/pages/admin/AdminAuditLogPage'))
const AdminWorkspacePage = lazy(() => import('@/pages/admin/AdminWorkspacePage'))
const AdminMasterDataPage = lazy(() => import('@/pages/admin/AdminMasterDataPage'))
const AdminDivisionPage = lazy(() => import('@/pages/admin/AdminDivisionPage'))
const AdminJobTitlePage = lazy(() => import('@/pages/admin/AdminJobTitlePage'))
const AdminEducationPage = lazy(() => import('@/pages/admin/AdminEducationPage'))
const AdminShiftPage = lazy(() => import('@/pages/admin/AdminShiftPage'))
const AdminInventoryPage = lazy(() => import('@/pages/admin/AdminInventoryPage'))

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      retry: 1,
    },
  },
})

function AppWithSplash() {
  const { isLoading } = useAuth()
  const [visible, setVisible] = useState(true)

  useEffect(() => {
    if (!isLoading) {
      const timeout = setTimeout(() => setVisible(false), 500)
      return () => clearTimeout(timeout)
    }
  }, [isLoading])

  return visible ? <SplashScreen authDone={!isLoading} /> : null
}

function RouteLoader() {
  return (
    <div className="d-flex min-vh-100 align-items-center justify-content-center bg-white">
      <div className="text-center">
        <div className="spinner-border text-primary" role="status" aria-hidden="true" />
        <div className="mt-3 text-muted" style={{ fontSize: 14 }}>
          Memuat halaman...
        </div>
      </div>
    </div>
  )
}

function renderLazyPage(element: ReactNode) {
  return <Suspense fallback={<RouteLoader />}>{element}</Suspense>
}

export default function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <BrowserRouter>
        <AuthProvider>
          <AppWithSplash />
          <Routes>
            <Route path="/sso/callback" element={renderLazyPage(<SsoCallbackPage />)} />
            <Route path="/login" element={renderLazyPage(<LoginPage />)} />
            <Route path="/set-password" element={renderLazyPage(<AcceptInvitePage />)} />
            <Route path="/claim-email" element={renderLazyPage(<ClaimEmailPage />)} />

            {/*
              `/platforms` redirects to portal.ibereality.com - portal is the master
              launcher hub. Local /platforms page caused infinite loop with SSO callback.
            */}
            <Route
              path="/platforms"
              element={<Navigate to="/login" replace />}
            />
            <Route
              path="/launch/:platformKey"
              element={
                <ProtectedRoute>
                  {renderLazyPage(<PlatformLaunchPage />)}
                </ProtectedRoute>
              }
            />

            <Route
              element={
                <ProtectedRoute>
                  <AppLayout />
                </ProtectedRoute>
              }
            >
              <Route path="/dashboard" element={renderLazyPage(<DashboardPage />)} />
              <Route path="/attendance" element={renderLazyPage(<AttendancePage />)} />
              <Route path="/attendance/history" element={renderLazyPage(<AttendanceHistoryPage />)} />
              <Route path="/face-setup" element={renderLazyPage(<FaceSetupPage />)} />
              <Route path="/leaves" element={renderLazyPage(<LeavePage />)} />
              <Route path="/overtimes" element={renderLazyPage(<OvertimePage />)} />
              <Route path="/salary-advances" element={renderLazyPage(<SalaryAdvancePage />)} />
              <Route path="/reimbursements" element={renderLazyPage(<ReimbursementPage />)} />
              <Route path="/payslips" element={renderLazyPage(<PayslipPage />)} />
              <Route path="/business-trips" element={renderLazyPage(<BusinessTripPage />)} />
              <Route path="/client-visits" element={renderLazyPage(<ClientVisitPage />)} />
              <Route path="/id-card" element={renderLazyPage(<IdCardPage />)} />
              <Route path="/work-certificate" element={renderLazyPage(<WorkCertificatePage />)} />
              <Route path="/profile/edit" element={renderLazyPage(<ProfileEditPage />)} />
              <Route path="/contract/sign" element={renderLazyPage(<ContractSignPage />)} />

              <Route path="/admin/leaves" element={<AdminRoute capability="leave.manage">{renderLazyPage(<AdminLeavePage />)}</AdminRoute>} />
              <Route path="/admin" element={<AdminRoute>{renderLazyPage(<AdminWorkspacePage />)}</AdminRoute>} />
              <Route path="/admin/employees" element={<AdminRoute capability="employees.manage">{renderLazyPage(<AdminEmployeePage />)}</AdminRoute>} />
              <Route path="/admin/attendances" element={<AdminRoute capability="attendance.manage">{renderLazyPage(<AdminAttendancePage />)}</AdminRoute>} />
              <Route path="/admin/shift-schedules" element={<AdminRoute capability="attendance.manage">{renderLazyPage(<AdminShiftSchedulePage />)}</AdminRoute>} />
              <Route path="/admin/overtimes" element={<AdminRoute capability="overtime.manage">{renderLazyPage(<AdminOvertimePage />)}</AdminRoute>} />
              <Route path="/admin/salary-advances" element={<AdminRoute capability="salary_advance.manage">{renderLazyPage(<AdminSalaryAdvancePage />)}</AdminRoute>} />
              <Route path="/admin/reimbursements" element={<AdminRoute capability="reimbursement.manage">{renderLazyPage(<AdminReimbursementPage />)}</AdminRoute>} />
              <Route path="/admin/payrolls" element={<AdminRoute capability="payroll.manage">{renderLazyPage(<AdminPayrollPage />)}</AdminRoute>} />
              <Route path="/admin/late-deductions" element={<AdminRoute capability="payroll.manage">{renderLazyPage(<AdminLateDeductionPage />)}</AdminRoute>} />
              <Route path="/admin/face-approvals" element={<AdminRoute capability="face_approval.manage">{renderLazyPage(<AdminFacePage />)}</AdminRoute>} />
              <Route path="/admin/company" element={<AdminRoute capability="company.manage">{renderLazyPage(<AdminCompanyPage />)}</AdminRoute>} />
              <Route path="/admin/master-data" element={<AdminRoute capability="master_data.manage">{renderLazyPage(<AdminMasterDataPage />)}</AdminRoute>} />
              <Route path="/admin/master-data/divisions" element={<AdminRoute capability="master_data.manage">{renderLazyPage(<AdminDivisionPage />)}</AdminRoute>} />
              <Route path="/admin/master-data/job-titles" element={<AdminRoute capability="master_data.manage">{renderLazyPage(<AdminJobTitlePage />)}</AdminRoute>} />
              <Route path="/admin/master-data/educations" element={<AdminRoute capability="master_data.manage">{renderLazyPage(<AdminEducationPage />)}</AdminRoute>} />
              <Route path="/admin/master-data/shifts" element={<AdminRoute capability="master_data.manage">{renderLazyPage(<AdminShiftPage />)}</AdminRoute>} />
              <Route path="/admin/master-data/inventories" element={<AdminRoute capability="master_data.manage">{renderLazyPage(<AdminInventoryPage />)}</AdminRoute>} />
              <Route path="/admin/audit-logs" element={<AdminRoute capability="audit_logs.view">{renderLazyPage(<AdminAuditLogPage />)}</AdminRoute>} />
            </Route>

            <Route path="*" element={<Navigate to="/dashboard" replace />} />
          </Routes>
        </AuthProvider>
      </BrowserRouter>
    </QueryClientProvider>
  )
}
