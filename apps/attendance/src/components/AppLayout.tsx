import { useState, useRef, useEffect, useCallback, type ReactElement } from 'react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { useAuth } from '@/context/AuthContext'
import { usePullToRefresh } from '@/hooks/usePullToRefresh'
import { useIdleTimeout } from '@/hooks/useIdleTimeout'
import BroadcastInbox from '@/components/BroadcastInbox'
import WelcomeQuoteModal from '@/components/WelcomeQuoteModal'

type AdminNavItem = {
  capability: string
  label: string
  to: string
  icon: ReactElement
}

function AdminMenuLink({ to, icon, label }: { to: string; icon: ReactElement; label: string }) {
  return (
    <NavLink to={to} className="admin-menu-link">
      {icon}
      {label}
    </NavLink>
  )
}

export default function AppLayout() {
  const { user, logout, logoutAll } = useAuth()
  const isAdmin = user?.permissions?.is_admin
  const capabilities = user?.permissions?.capabilities ?? []
  const can = (capability: string) => capabilities.includes(capability)
  const location = useLocation()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const [menuOpen, setMenuOpen] = useState(false)
  const [moreMenuOpen, setMoreMenuOpen] = useState(false)
  const [darkMode, setDarkMode] = useState(() => {
    const saved = localStorage.getItem('attendance_theme')
    return saved ? saved === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches
  })
  const menuRef = useRef<HTMLDivElement>(null)

  useEffect(() => { setMoreMenuOpen(false) }, [location.pathname])
  useEffect(() => {
    document.documentElement.classList.toggle('dark', darkMode)
    document.documentElement.classList.toggle('light', !darkMode)
    localStorage.setItem('attendance_theme', darkMode ? 'dark' : 'light')
  }, [darkMode])

  const handleRefresh = useCallback(async () => {
    await qc.invalidateQueries()
  }, [qc])

  const { pullDistance, refreshing } = usePullToRefresh(handleRefresh)

  // Kalau SSO required, logout WAJIB redirect ke portal - karyawan tidak boleh
  // login langsung ke app ini lagi. Dua aksi yang beda maksud:
  //  - Keluar        → portal /login?fresh=1, sesi portal ikut dicabut (logout beneran)
  //  - Ganti Aplikasi → portal /platforms, sesi portal sengaja dipertahankan
  //
  // Dulu label "Keluar" dipasang di aksi yang kedua. Akibatnya orang mengira
  // sudah keluar padahal sesi portal masih hidup - di komputer bersama itu
  // lubang keamanan, bukan sekadar bingung. Label sekarang mengikuti apa yang
  // benar-benar terjadi.
  const portalLoginUrl = import.meta.env.VITE_SSO_PORTAL_URL ?? 'https://portal.ibereality.com/login'
  const portalBase = portalLoginUrl.replace(/\/login.*$/, '')
  const requireSso = (import.meta.env.VITE_REQUIRE_SSO ?? 'true') === 'true'

  // CRITICAL: Navigate FIRST, then fire-and-forget the API call. Kalau pakai
  // `await logout()`, AuthContext.setUser(null) trigger React re-render →
  // ProtectedRoute → Navigate /login → LoginPage useEffect → SSO redirect →
  // portal auto-launch balik ke absen = loop infinite. Solusinya: clear token
  // client-side, langsung window.location.replace SEBELUM React sempat
  // re-render. API call dijalankan background tanpa await - kalau gagal pun
  // token akan expire sendiri di server.
  const handleLogout = useCallback(() => {
    try { localStorage.removeItem('absen_token') } catch {}
    // Fire-and-forget: jangan await, jangan tunggu Promise resolution.
    void logoutAll().catch(() => { /* ignore - already cleared */ })
    const sep = portalLoginUrl.includes('?') ? '&' : '?'
    window.location.replace(`${portalLoginUrl}${sep}fresh=1`)
  }, [logoutAll, portalLoginUrl])

  // Pindah ke aplikasi lain tanpa mengulang login portal. Bukan logout.
  const handleSwitchApp = useCallback(() => {
    try { localStorage.removeItem('absen_token') } catch {}
    void logout().catch(() => { /* ignore */ })
    if (requireSso) {
      window.location.replace(`${portalBase}/platforms`)
    } else {
      navigate('/login')
    }
  }, [logout, navigate, requireSso, portalBase])

  // Auto-logout setelah 30 menit idle - pakai handler yang sama supaya redirect konsisten.
  const handleIdleLogout = useCallback(async () => {
    await handleLogout()
  }, [handleLogout])

  const { showWarning, secondsLeft, stayActive } = useIdleTimeout({
    idleMinutes: 30,
    warningMinutes: 2,
    onLogout: handleIdleLogout,
  })

  const navActive = (path: string) => location.pathname === path || location.pathname.startsWith(path + '/')
  const workforceItems: AdminNavItem[] = [
    {
      capability: 'attendance.manage',
      label: 'Absensi',
      to: '/admin/attendances',
      icon: <svg fill="currentColor" viewBox="0 0 16 16"><path d="M8 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6m2-3a2 2 0 1 1-4 0 2 2 0 0 1 4 0m4 8c0 1-1 1-1 1H3s-1 0-1-1 1-4 6-4 6 3 6 4m-1-.004c-.001-.246-.154-.986-.832-1.664C11.516 10.68 10.289 10 8 10s-3.516.68-4.168 1.332c-.678.678-.83 1.418-.832 1.664z"/></svg>,
    },
    {
      capability: 'employees.manage',
      label: 'Employee Master',
      to: '/admin/employees',
      icon: <svg fill="currentColor" viewBox="0 0 16 16"><path d="M7 14s-1 0-1-1 1-4 5-4 5 3 5 4-1 1-1 1zm4-6a3 3 0 1 0 0-6 3 3 0 0 0 0 6"/><path fillRule="evenodd" d="M5.216 14A2.24 2.24 0 0 1 5 13c0-1.355.68-2.75 1.936-3.72A6.3 6.3 0 0 0 5 9c-4 0-5 3-5 4s1 1 1 1zM4.5 8a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5"/></svg>,
    },
    {
      capability: 'attendance.manage',
      label: 'Shift Scheduling',
      to: '/admin/shift-schedules',
      icon: <svg fill="currentColor" viewBox="0 0 16 16"><path d="M8 3.5a.5.5 0 0 0-1 0V8a.5.5 0 0 0 .276.447l3 1.5a.5.5 0 1 0 .448-.894L8 7.691z"/><path d="M8 16A8 8 0 1 0 8 0a8 8 0 0 0 0 16m0-1A7 7 0 1 1 8 1a7 7 0 0 1 0 14"/></svg>,
    },
    {
      capability: 'leave.manage',
      label: 'Approval Cuti',
      to: '/admin/leaves',
      icon: <svg fill="currentColor" viewBox="0 0 16 16"><path d="M0 2a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2zm4 1v2h8V3zm8 4H4v1h8zm0 3H4v1h8zm0 3H4v1h8z"/></svg>,
    },
    {
      capability: 'overtime.manage',
      label: 'Approval Lembur',
      to: '/admin/overtimes',
      icon: <svg fill="currentColor" viewBox="0 0 16 16"><path d="M8 3.5a.5.5 0 0 0-1 0V9a.5.5 0 0 0 .252.434l3.5 2a.5.5 0 0 0 .496-.868L8 8.71z"/><path d="M8 16A8 8 0 1 0 8 0a8 8 0 0 0 0 16m7-8A7 7 0 1 1 1 8a7 7 0 0 1 14 0"/></svg>,
    },
    {
      capability: 'face_approval.manage',
      label: 'Approval Wajah',
      to: '/admin/face-approvals',
      icon: <svg fill="currentColor" viewBox="0 0 16 16"><path d="M11 1a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1zM5 0a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2V2a2 2 0 0 0-2-2z"/><path d="M8 14a1 1 0 1 0 0-2 1 1 0 0 0 0 2"/><path d="M8 5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3m0-4a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5"/></svg>,
    },
  ].filter((item) => can(item.capability))
  const financeItems: AdminNavItem[] = [
    {
      capability: 'salary_advance.manage',
      label: 'Approval Kasbon',
      to: '/admin/salary-advances',
      icon: <svg fill="currentColor" viewBox="0 0 16 16"><path d="M0 4a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2z"/></svg>,
    },
    {
      capability: 'reimbursement.manage',
      label: 'Approval Reimburse',
      to: '/admin/reimbursements',
      icon: <svg fill="currentColor" viewBox="0 0 16 16"><path d="M3 4.5a.5.5 0 0 1 .5-.5h6a.5.5 0 1 1 0 1h-6a.5.5 0 0 1-.5-.5m0 2a.5.5 0 0 1 .5-.5h6a.5.5 0 1 1 0 1h-6a.5.5 0 0 1-.5-.5"/></svg>,
    },
    {
      capability: 'payroll.manage',
      label: 'Payroll',
      to: '/admin/payrolls',
      icon: <svg fill="currentColor" viewBox="0 0 16 16"><path d="M2 2a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v12.5a.5.5 0 0 1-.777.416L12 14.101l-1.223.815a.5.5 0 0 1-.554 0L9 14.101l-1.223.815a.5.5 0 0 1-.554 0L6 14.101l-1.223.815A.5.5 0 0 1 4 14.5V2zm3.5 2a.5.5 0 0 0 0 1h5a.5.5 0 0 0 0-1zm0 2.5a.5.5 0 0 0 0 1h5a.5.5 0 0 0 0-1zm0 2.5a.5.5 0 0 0 0 1h3a.5.5 0 0 0 0-1z"/></svg>,
    },
    {
      capability: 'payroll.manage',
      label: 'Potongan Terlambat',
      to: '/admin/late-deductions',
      icon: <svg fill="currentColor" viewBox="0 0 16 16"><path d="M8 3.5a.5.5 0 0 0-1 0V9a.5.5 0 0 0 .252.434l3.5 2a.5.5 0 0 0 .496-.868L8 8.71z"/><path d="M8 16A8 8 0 1 0 8 0a8 8 0 0 0 0 16m7-8A7 7 0 1 1 1 8a7 7 0 0 1 14 0"/></svg>,
    },
  ].filter((item) => can(item.capability))
  const complianceItems: AdminNavItem[] = [
    {
      capability: 'audit_logs.view',
      label: 'Audit Log',
      to: '/admin/audit-logs',
      icon: <svg fill="currentColor" viewBox="0 0 16 16"><path d="M14 4.5V14a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V2a2 2 0 0 1 2-2h5.5z"/><path d="M13.5 3H10a1 1 0 0 1-1-1V.5z"/><path d="M4.5 6a.5.5 0 0 1 .5-.5h5a.5.5 0 0 1 0 1H5a.5.5 0 0 1-.5-.5m0 2.5A.5.5 0 0 1 5 8h6a.5.5 0 0 1 0 1H5a.5.5 0 0 1-.5-.5m0 2.5a.5.5 0 0 1 .5-.5h6a.5.5 0 0 1 0 1H5a.5.5 0 0 1-.5-.5"/></svg>,
    },
  ].filter((item) => can(item.capability))
  const masterDataItems: AdminNavItem[] = [
    {
      capability: 'master_data.manage',
      label: 'Workspace Master Data',
      to: '/admin/master-data',
      icon: <svg fill="currentColor" viewBox="0 0 16 16"><path d="M8.277.084a.5.5 0 0 0-.554 0l-7.5 5A.5.5 0 0 0 .5 6H2v2.5A.5.5 0 0 0 2 9H0v.5A.5.5 0 0 0 .5 10H1v5.5a.5.5 0 0 0 .5.5H4v-4h2v4h5.5a.5.5 0 0 0 .5-.5V10h.5a.5.5 0 0 0 .5-.5V9h-2a.5.5 0 0 0 0-1H14V6h1.5a.5.5 0 0 0 .277-.916z"/></svg>,
    },
  ].filter((item) => can(item.capability))

  // Close dropdown when clicking outside
  useEffect(() => {
    const handler = (e: MouseEvent) => {
      if (menuRef.current && !menuRef.current.contains(e.target as Node)) {
        setMenuOpen(false)
      }
    }
    document.addEventListener('mousedown', handler)
    return () => document.removeEventListener('mousedown', handler)
  }, [])

  const mins = Math.floor(secondsLeft / 60)
  const secs = secondsLeft % 60

  return (
    <div className="app-shell">

      {/* ── Idle timeout warning modal ── */}
      {showWarning && (
        <div style={{
          position: 'fixed', inset: 0, zIndex: 9999,
          background: 'rgba(0,0,0,0.55)', backdropFilter: 'blur(4px)',
          display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16,
        }}>
          <div style={{
            background: '#fff', borderRadius: 20, padding: '28px 24px',
            maxWidth: 360, width: '100%', textAlign: 'center',
            boxShadow: '0 20px 60px rgba(0,0,0,.25)',
          }}>
            <div style={{ fontSize: 44, marginBottom: 8 }}>⏰</div>
            <h5 style={{ fontWeight: 700, marginBottom: 8, color: '#1a2332' }}>
              Sesi Hampir Berakhir
            </h5>
            <p style={{ fontSize: 14, color: '#6b7280', marginBottom: 16 }}>
              Anda tidak aktif. Akan otomatis logout dalam:
            </p>
            <div style={{
              fontSize: 40, fontWeight: 800, color: secondsLeft <= 30 ? '#ef4444' : '#1a7a8c',
              letterSpacing: 2, marginBottom: 20,
              transition: 'color .3s',
            }}>
              {mins > 0 ? `${mins}:${String(secs).padStart(2,'0')}` : `${secs}s`}
            </div>
            <div style={{ display: 'flex', gap: 10 }}>
              <button
                onClick={stayActive}
                style={{
                  flex: 1, padding: '12px', borderRadius: 12, border: 'none',
                  background: 'linear-gradient(135deg,#1a7a8c,#0d5a6a)',
                  color: '#fff', fontWeight: 700, fontSize: 14, cursor: 'pointer',
                }}
              >
                ✓ Tetap Login
              </button>
              <button
                onClick={handleIdleLogout}
                style={{
                  flex: 1, padding: '12px', borderRadius: 12,
                  border: '1.5px solid #e5e7eb', background: '#fff',
                  color: '#6b7280', fontWeight: 600, fontSize: 14, cursor: 'pointer',
                }}
              >
                Logout
              </button>
            </div>
          </div>
        </div>
      )}
      {/* ══════════════ MOBILE: top header ══════════════ */}
      <div className="mobile-header">
        <div className="mobile-header-left">
          <img
            src={user?.company?.logo ?? '/logo.png'}
            alt={user?.company?.name ?? 'IBE Reality'}
            className="mobile-header-logo"
          />
          <div>
            <div className="mobile-header-brand">{user?.company?.name ?? 'IBE Reality'}</div>
            <div className="mobile-header-sub">Portal Karyawan</div>
          </div>
        </div>

        {/* Avatar with dropdown */}
        <div ref={menuRef} className="mobile-header-menu">
          <button
            onClick={() => setMenuOpen(v => !v)}
            className="mobile-header-avatar"
            style={{ padding: 0, overflow: 'hidden', background: 'none', border: 'none' }}
          >
            {user?.avatar
              ? <img src={user.avatar} alt={user.name} style={{ width: '100%', height: '100%', objectFit: 'cover', borderRadius: '50%' }} onError={e => { (e.target as HTMLImageElement).style.display='none'; (e.target as HTMLImageElement).parentElement!.textContent = user?.name?.[0]?.toUpperCase() ?? 'U' }} />
              : user?.name?.[0]?.toUpperCase() ?? 'U'
            }
          </button>

          {menuOpen && (
            <div className="mobile-header-dropdown">
              {/* User info */}
              <div className="mobile-header-dropdown__profile">
                <div className="mobile-header-dropdown__name">{user?.name}</div>
                <div className="mobile-header-dropdown__role">
                  {user?.group} {user?.employee_number ? `· NIP ${user.employee_number}` : ''}
                </div>
              </div>

              {/* Menu items */}
              <button
                onClick={() => { setMenuOpen(false); navigate('/face-setup') }}
                className="mobile-header-dropdown__item"
              >
                <span>🪪</span> Daftar Wajah
              </button>

              {isAdmin && (
                <>
                  <button
                    onClick={() => { setMenuOpen(false); navigate('/admin') }}
                    className="mobile-header-dropdown__item"
                  >
                    <span>🧭</span> Workspace Admin
                  </button>
                  {can('face_approval.manage') && (
                    <button
                      onClick={() => { setMenuOpen(false); navigate('/admin/face-approvals') }}
                      className="mobile-header-dropdown__item"
                    >
                      <span>👤</span> Approval Wajah
                    </button>
                  )}
                  {can('master_data.manage') && (
                    <button
                      onClick={() => { setMenuOpen(false); navigate('/admin/master-data') }}
                      className="mobile-header-dropdown__item"
                    >
                      <span>🏢</span> Master Data
                    </button>
                  )}
                  {can('audit_logs.view') && (
                    <button
                      onClick={() => { setMenuOpen(false); navigate('/admin/audit-logs') }}
                      className="mobile-header-dropdown__item"
                    >
                      <span>🧾</span> Audit Log
                    </button>
                  )}
                </>
              )}

              <div className="mobile-header-dropdown__divider" />

              <button
                onClick={() => setDarkMode(value => !value)}
                className="mobile-header-dropdown__item"
              >
                <span>{darkMode ? '☀️' : '🌙'}</span> Tema {darkMode ? 'terang' : 'gelap'}
              </button>

              <button
                onClick={() => { setMenuOpen(false); handleSwitchApp() }}
                className="mobile-header-dropdown__item"
              >
                <span>🔄</span> Ganti aplikasi
              </button>
              <button
                onClick={() => { setMenuOpen(false); handleLogout() }}
                className="mobile-header-dropdown__item mobile-header-dropdown__item--danger"
              >
                <span>🚪</span> Keluar
              </button>
            </div>
          )}
        </div>
      </div>

      {/* ══════════════ DESKTOP: sidebar ══════════════ */}
      <nav className="sidebar">
        <div className="sidebar-logo">
          <img
            src={user?.company?.logo ?? '/logo.png'}
            alt={user?.company?.name ?? 'IBE Reality'}
            className="sidebar-logo__image"
          />
          <div className="sidebar-logo__copy">
            <div className="sidebar-logo__title">
              {user?.company?.name ?? 'IBE Reality'}
            </div>
            <div className="sidebar-logo__subtitle">Portal Karyawan</div>
          </div>
        </div>

        <div className="sidebar-menu">
          <div className="menu-title">Menu Utama</div>
          <NavLink to="/dashboard">
            <svg fill="currentColor" viewBox="0 0 16 16"><path d="M8 4a.5.5 0 0 1 .5.5V6a.5.5 0 0 1-1 0V4.5A.5.5 0 0 1 8 4M3.732 5.732a.5.5 0 0 1 .707 0l.915.914a.5.5 0 1 1-.708.708l-.914-.915a.5.5 0 0 1 0-.707M2 10a.5.5 0 0 1 .5-.5h1.586a.5.5 0 0 1 0 1H2.5A.5.5 0 0 1 2 10m9.5 0a.5.5 0 0 1 .5-.5h1.5a.5.5 0 0 1 0 1H12a.5.5 0 0 1-.5-.5m.754-4.246a.39.39 0 0 0-.527-.02L7.547 9.31a.91.91 0 1 0 1.302 1.258l3.434-4.297a.39.39 0 0 0-.029-.518z"/><path fillRule="evenodd" d="M0 10a8 8 0 1 1 15.547 2.661c-.442 1.253-1.845 1.602-2.932 1.25C11.309 13.488 9.475 13 8 13c-1.474 0-3.31.488-4.615.911-1.087.352-2.49.003-2.932-1.25A8 8 0 0 1 0 10m8-7a7 7 0 0 0-6.603 9.329c.203.575.923.876 1.68.63C4.397 12.533 6.358 12 8 12s3.604.532 4.923.96c.757.245 1.477-.056 1.68-.63A7 7 0 0 0 8 3"/></svg>
            Dashboard
          </NavLink>
          <NavLink to="/attendance">
            <svg fill="currentColor" viewBox="0 0 16 16"><path d="M8 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6m2-3a2 2 0 1 1-4 0 2 2 0 0 1 4 0m4 8c0 1-1 1-1 1H3s-1 0-1-1 1-4 6-4 6 3 6 4m-1-.004c-.001-.246-.154-.986-.832-1.664C11.516 10.68 10.289 10 8 10s-3.516.68-4.168 1.332c-.678.678-.83 1.418-.832 1.664z"/></svg>
            Absensi
          </NavLink>
          <NavLink to="/attendance/history">
            <svg fill="currentColor" viewBox="0 0 16 16"><path d="M3.5 0a.5.5 0 0 1 .5.5V1h8V.5a.5.5 0 0 1 1 0V1h1a2 2 0 0 1 2 2v1H0V3a2 2 0 0 1 2-2h1V.5a.5.5 0 0 1 .5-.5M16 14a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V5h16z"/><path d="M4.5 7a.5.5 0 0 0 0 1h2a.5.5 0 0 0 0-1zm3 0a.5.5 0 0 0 0 1h2a.5.5 0 0 0 0-1zm3 0a.5.5 0 0 0 0 1h2a.5.5 0 0 0 0-1zm-6 2a.5.5 0 0 0 0 1h2a.5.5 0 0 0 0-1zm3 0a.5.5 0 0 0 0 1h2a.5.5 0 0 0 0-1z"/></svg>
            Riwayat Absensi
          </NavLink>
          {user?.is_permanent && (
            <NavLink to="/leaves">
              <svg fill="currentColor" viewBox="0 0 16 16"><path d="M4 .5a.5.5 0 0 0-1 0V1H2a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V3a2 2 0 0 0-2-2h-1V.5a.5.5 0 0 0-1 0V1H4zM1 4h14v10a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1zm2 2.5a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 0 1h-9a.5.5 0 0 1-.5-.5m0 2a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 0 1h-9a.5.5 0 0 1-.5-.5m0 2a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 0 1h-9a.5.5 0 0 1-.5-.5"/></svg>
              Cuti
            </NavLink>
          )}
          {user?.is_permanent && (
            <NavLink to="/overtimes">
              <svg fill="currentColor" viewBox="0 0 16 16"><path d="M8 3.5a.5.5 0 0 0-1 0V9a.5.5 0 0 0 .252.434l3.5 2a.5.5 0 0 0 .496-.868L8 8.71z"/><path d="M8 16A8 8 0 1 0 8 0a8 8 0 0 0 0 16m7-8A7 7 0 1 1 1 8a7 7 0 0 1 14 0"/></svg>
              Lembur
            </NavLink>
          )}
          {user?.is_permanent && (
            <NavLink to="/salary-advances">
              <svg fill="currentColor" viewBox="0 0 16 16"><path d="M0 4a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2zm2.5 1a.5.5 0 0 0-.5.5v1a.5.5 0 0 0 .5.5h1a.5.5 0 0 0 .5-.5v-1a.5.5 0 0 0-.5-.5zm3 0a.5.5 0 0 0-.5.5v1a.5.5 0 0 0 .5.5h1a.5.5 0 0 0 .5-.5v-1a.5.5 0 0 0-.5-.5zm3 0a.5.5 0 0 0-.5.5v1a.5.5 0 0 0 .5.5h1a.5.5 0 0 0 .5-.5v-1a.5.5 0 0 0-.5-.5z"/></svg>
              Kasbon
            </NavLink>
          )}
          <NavLink to="/reimbursements">
            <svg fill="currentColor" viewBox="0 0 16 16"><path d="M1.92.506a.5.5 0 0 1 .434.14L3 1.293l.646-.647a.5.5 0 0 1 .708 0L5 1.293l.646-.647a.5.5 0 0 1 .708 0L7 1.293l.646-.647a.5.5 0 0 1 .708 0L9 1.293l.646-.647a.5.5 0 0 1 .708 0l.646.647.646-.647a.5.5 0 0 1 .708 0l.646.647.646-.647a.5.5 0 0 1 .801.13l.5 1A.5.5 0 0 1 15 2v12a.5.5 0 0 1-.053.224l-.5 1a.5.5 0 0 1-.8.13L13 14.707l-.646.647a.5.5 0 0 1-.708 0L11 14.707l-.646.647a.5.5 0 0 1-.708 0L9 14.707l-.646.647a.5.5 0 0 1-.708 0L7 14.707l-.646.647a.5.5 0 0 1-.708 0L5 14.707l-.646.647a.5.5 0 0 1-.708 0L3 14.707l-.646.647a.5.5 0 0 1-.801-.13l-.5-1A.5.5 0 0 1 1 14V2a.5.5 0 0 1 .053-.224l.5-1a.5.5 0 0 1 .367-.27m.217 1.338L2 2.118v11.764l.137.274.51-.51a.5.5 0 0 1 .707 0l.646.647.646-.646a.5.5 0 0 1 .708 0l.646.646.646-.646a.5.5 0 0 1 .708 0l.646.646.646-.646a.5.5 0 0 1 .708 0l.646.646.646-.646a.5.5 0 0 1 .708 0l.646.646.646-.646a.5.5 0 0 1 .708 0l.509.509.137-.274V2.118l-.137-.274-.51.51a.5.5 0 0 1-.707 0L12 1.707l-.646.647a.5.5 0 0 1-.708 0L10 1.707l-.646.647a.5.5 0 0 1-.708 0L8 1.707l-.646.647a.5.5 0 0 1-.708 0L6 1.707l-.646.647a.5.5 0 0 1-.708 0L4 1.707l-.646.647a.5.5 0 0 1-.708 0z"/><path d="M3 4.5a.5.5 0 0 1 .5-.5h6a.5.5 0 1 1 0 1h-6a.5.5 0 0 1-.5-.5m0 2a.5.5 0 0 1 .5-.5h6a.5.5 0 1 1 0 1h-6a.5.5 0 0 1-.5-.5m0 2a.5.5 0 0 1 .5-.5h6a.5.5 0 1 1 0 1h-6a.5.5 0 0 1-.5-.5"/></svg>
            Reimburse
          </NavLink>
          {user?.is_permanent && (
            <NavLink to="/business-trips">
              <svg fill="currentColor" viewBox="0 0 16 16"><path d="M6.428 1.151C6.708.591 7.213 0 8 0s1.292.592 1.572 1.151C9.861 1.73 10 2.431 10 3v3.691l5.17 2.585a1.5 1.5 0 0 1 .83 1.342V12a.5.5 0 0 1-.582.493l-5.507-.918-.375 2.253 1.318 1.318A.5.5 0 0 1 10.5 16h-5a.5.5 0 0 1-.354-.854l1.319-1.318-.376-2.253-5.507.918A.5.5 0 0 1 0 12v-1.382a1.5 1.5 0 0 1 .83-1.342L6 6.691V3c0-.568.14-1.271.428-1.849"/></svg>
              Perjalanan Dinas
            </NavLink>
          )}
          {user?.is_permanent && (
            <NavLink to="/client-visits">
              <svg fill="currentColor" viewBox="0 0 16 16"><path d="M8 16s6-5.686 6-10A6 6 0 0 0 2 6c0 4.314 6 10 6 10m0-7a3 3 0 1 1 0-6 3 3 0 0 1 0 6"/></svg>
              Kunjungan Klien
            </NavLink>
          )}
          {user?.is_permanent && (
            <NavLink to="/payslips">
              <svg fill="currentColor" viewBox="0 0 16 16"><path d="M2 2a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v12.5a.5.5 0 0 1-.777.416L12 14.101l-1.223.815a.5.5 0 0 1-.554 0L9 14.101l-1.223.815a.5.5 0 0 1-.554 0L6 14.101l-1.223.815A.5.5 0 0 1 4 14.5V2zm3.5 2a.5.5 0 0 0 0 1h5a.5.5 0 0 0 0-1zm0 2.5a.5.5 0 0 0 0 1h5a.5.5 0 0 0 0-1zm0 2.5a.5.5 0 0 0 0 1h3a.5.5 0 0 0 0-1z"/></svg>
              Slip Gaji
            </NavLink>
          )}
          {user?.is_permanent && (
            <NavLink to="/contract/sign">
              <svg fill="currentColor" viewBox="0 0 16 16"><path d="M5.5 7a.5.5 0 0 0 0 1h5a.5.5 0 0 0 0-1zM5 9.5a.5.5 0 0 1 .5-.5h5a.5.5 0 0 1 0 1h-5a.5.5 0 0 1-.5-.5m0 2a.5.5 0 0 1 .5-.5h2a.5.5 0 0 1 0 1h-2a.5.5 0 0 1-.5-.5"/><path d="M9.5 0H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V4.5zM9 1v3a1 1 0 0 0 1 1h3z"/></svg>
              Kontrak Kerja
            </NavLink>
          )}

          <div className="menu-title" style={{ marginTop: 8 }}>Akun Saya</div>
          <NavLink to="/id-card">
            <svg fill="currentColor" viewBox="0 0 16 16"><path d="M0 4a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2zm2-1a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V4a1 1 0 0 0-1-1z"/><path d="M6.5 9.5a2 2 0 1 0 0-4 2 2 0 0 0 0 4M4 7.5a2.5 2.5 0 1 1 5 0 2.5 2.5 0 0 1-5 0M9 7.5a.5.5 0 0 1 .5-.5h2a.5.5 0 0 1 0 1h-2a.5.5 0 0 1-.5-.5m0 2a.5.5 0 0 1 .5-.5h2a.5.5 0 0 1 0 1h-2a.5.5 0 0 1-.5-.5"/></svg>
            Kartu Identitas
          </NavLink>
          <NavLink to="/work-certificate">
            <svg fill="currentColor" viewBox="0 0 16 16"><path d="M14 4.5V14a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V2a2 2 0 0 1 2-2h5.5zm-3 0A1.5 1.5 0 0 1 9.5 3V1H4a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V4.5zM4.5 9a.5.5 0 0 1 .5-.5h6a.5.5 0 0 1 0 1H5a.5.5 0 0 1-.5-.5m0 2a.5.5 0 0 1 .5-.5h6a.5.5 0 0 1 0 1H5a.5.5 0 0 1-.5-.5"/></svg>
            Surat Keterangan Kerja
          </NavLink>
          <NavLink to="/profile/edit">
            <svg fill="currentColor" viewBox="0 0 16 16"><path d="M11 5a3 3 0 1 1-6 0 3 3 0 0 1 6 0M8 7a2 2 0 1 0 0-4 2 2 0 0 0 0 4"/><path fillRule="evenodd" d="M8 0a8 8 0 1 0 0 16A8 8 0 0 0 8 0M2.5 6.5a5.5 5.5 0 0 1 11 0 5.5 5.5 0 0 1-3.156 4.974A4 4 0 0 0 8 10a4 4 0 0 0-2.344 1.474A5.5 5.5 0 0 1 2.5 6.5"/></svg>
            Edit Profil
          </NavLink>
          <NavLink to="/face-setup">
            <svg fill="currentColor" viewBox="0 0 16 16"><path d="M11 5a3 3 0 1 1-6 0 3 3 0 0 1 6 0M2 16a6 6 0 0 1 12 0z"/></svg>
            Daftar Wajah
          </NavLink>
          {/* Email diakses lewat portal saja - supaya logout flow konsisten. */}

          {isAdmin && (
            <>
              <div className="menu-title" style={{ marginTop: 8 }}>Admin Workspace</div>
              <AdminMenuLink
                to="/admin"
                label="Ikhtisar Modul"
                icon={<svg fill="currentColor" viewBox="0 0 16 16"><path d="M0 1.5A1.5 1.5 0 0 1 1.5 0h5A1.5 1.5 0 0 1 8 1.5v5A1.5 1.5 0 0 1 6.5 8h-5A1.5 1.5 0 0 1 0 6.5zm1.5-.5a.5.5 0 0 0-.5.5v5a.5.5 0 0 0 .5.5h5a.5.5 0 0 0 .5-.5v-5a.5.5 0 0 0-.5-.5zm7 0A1.5 1.5 0 0 1 10 0h4.5A1.5 1.5 0 0 1 16 1.5V6A1.5 1.5 0 0 1 14.5 7.5H10A1.5 1.5 0 0 1 8.5 6zm1.5 0a.5.5 0 0 0-.5.5V6a.5.5 0 0 0 .5.5h4.5A.5.5 0 0 0 15 6V1.5a.5.5 0 0 0-.5-.5zM0 10A1.5 1.5 0 0 1 1.5 8.5h5A1.5 1.5 0 0 1 8 10v4.5A1.5 1.5 0 0 1 6.5 16h-5A1.5 1.5 0 0 1 0 14.5zm1.5-.5a.5.5 0 0 0-.5.5v4.5a.5.5 0 0 0 .5.5h5a.5.5 0 0 0 .5-.5V10a.5.5 0 0 0-.5-.5zm7.5 0a.5.5 0 0 1 .5-.5h4.5a.5.5 0 0 1 .5.5v4.5a.5.5 0 0 1-.5.5H9.5a.5.5 0 0 1-.5-.5z"/></svg>}
              />

              {workforceItems.length > 0 && (
                <>
                  <div className="menu-title" style={{ marginTop: 8 }}>Workforce</div>
                  {workforceItems.map((item) => (
                    <AdminMenuLink key={item.label} to={item.to} label={item.label} icon={item.icon} />
                  ))}
                </>
              )}

              {financeItems.length > 0 && (
                <>
                  <div className="menu-title" style={{ marginTop: 8 }}>Finance</div>
                  {financeItems.map((item) => (
                    <AdminMenuLink key={item.label} to={item.to} label={item.label} icon={item.icon} />
                  ))}
                </>
              )}

              {complianceItems.length > 0 && (
                <>
                  <div className="menu-title" style={{ marginTop: 8 }}>Compliance</div>
                  {complianceItems.map((item) => (
                    <AdminMenuLink key={item.label} to={item.to} label={item.label} icon={item.icon} />
                  ))}
                </>
              )}

              {masterDataItems.length > 0 && (
                <>
                  <div className="menu-title" style={{ marginTop: 8 }}>Master Data</div>
                  {masterDataItems.map((item) => (
                    <AdminMenuLink key={item.label} to={item.to} label={item.label} icon={item.icon} />
                  ))}
                </>
              )}
            </>
          )}

          <div className="sidebar-footer">
            {/* Quick Email - SSO ticket flow auto-logs user into webmail */}
            {user?.email?.endsWith('@ibereality.com') && (
              <button
                onClick={async () => {
                  const t = localStorage.getItem('absen_token')
                  if (!t) return
                  try {
                    const res = await fetch(`${import.meta.env.VITE_API_BASE_URL ?? ''}/api/v1/auth/webmail-ticket`, {
                      method: 'POST',
                      headers: { Authorization: `Bearer ${t}`, Accept: 'application/json' },
                    })
                    const j = await res.json()
                    if (!res.ok) throw new Error(j?.message ?? 'Gagal')
                    window.open(j?.data?.redirect_url ?? j?.redirect_url, '_blank', 'noopener')
                  } catch (e) {
                    alert('Gagal membuka email: ' + (e as Error).message)
                  }
                }}
                className="btn btn-sm w-100"
                style={{ marginBottom: 6, background: '#dbeafe', color: '#1e3a8a', border: '1px solid #93c5fd', fontWeight: 600 }}
                title={`Buka email Anda (${user.email}) tanpa password`}
              >
                📧 Email Saya
              </button>
            )}
            <button
              onClick={handleSwitchApp}
              className="btn btn-sm w-100 sidebar-footer__logout"
              title="Pindah ke aplikasi lain tanpa login ulang"
            >
              🔄 Ganti Aplikasi
            </button>
            <button
              onClick={handleLogout}
              className="btn btn-sm w-100"
              style={{ marginTop: 6, background: '#fee2e2', color: '#b91c1c', border: '1px solid #fecaca' }}
              title="Keluar dari absensi dan portal sekaligus"
            >
              🚪 Keluar
            </button>
          </div>
        </div>
      </nav>

      {/* ══════════════ DESKTOP: top bar ══════════════ */}
      <div className="desktop-header">
        <div className="desktop-header__brand">
          <div className="desktop-header__eyebrow">Workspace</div>
          <div className="desktop-header__title">{user?.company?.name ?? 'PT. Integrasi Bisnis Eksekutif'}</div>
        </div>
        <div className="desktop-header__profile">
          <button
            onClick={() => setDarkMode(value => !value)}
            className="theme-mode-button"
            title={`Gunakan tema ${darkMode ? 'terang' : 'gelap'}`}
            aria-label={`Gunakan tema ${darkMode ? 'terang' : 'gelap'}`}
          >
            {darkMode ? '☀️' : '🌙'}
          </button>
          <div className="desktop-header__copy">
            <div className="desktop-header__name">{user?.name}</div>
            <div className="desktop-header__role">{user?.group}</div>
          </div>
          <div className="desktop-header__avatar" style={{ padding: 0, overflow: 'hidden' }}>
            {user?.avatar
              ? <img src={user.avatar} alt={user?.name} style={{ width: '100%', height: '100%', objectFit: 'cover', borderRadius: '50%' }} onError={e => { (e.target as HTMLImageElement).style.display='none' }} />
              : user?.name?.[0]?.toUpperCase() ?? 'U'
            }
          </div>
          <button
            onClick={handleSwitchApp}
            className="btn btn-sm desktop-header__logout"
            title="Pindah ke aplikasi lain tanpa login ulang"
          >
            🔄 Ganti Aplikasi
          </button>
          <button
            onClick={handleLogout}
            className="btn btn-sm desktop-header__logout"
            style={{ background: '#fee2e2', color: '#b91c1c', borderColor: '#fecaca' }}
            title="Keluar dari absensi dan portal sekaligus"
          >
            🚪 Keluar
          </button>
        </div>
      </div>

      {/* ══════════════ PAGE CONTENT ══════════════ */}
      <div className="mobile-body">
        {/* Pull-to-refresh indicator */}
        {(pullDistance > 0 || refreshing) && (
          <div style={{
            display: 'flex', justifyContent: 'center', alignItems: 'center',
            height: pullDistance || 48, overflow: 'hidden',
            transition: refreshing ? 'none' : 'height 0.2s',
            marginBottom: 4,
          }}>
            <div style={{
              width: 28, height: 28, borderRadius: '50%',
              border: '3px solid #e0e0e0', borderTopColor: 'var(--primary)',
              animation: refreshing ? 'ptr-spin 0.7s linear infinite' : 'none',
              transform: refreshing ? undefined : `rotate(${(pullDistance / 72) * 270}deg)`,
            }} />
          </div>
        )}
        {user?.is_permanent && <PendingContractBanner />}
        <BroadcastInbox />
        <WelcomeQuoteModal />
        <Outlet />
      </div>
      <style>{`@keyframes ptr-spin { to { transform: rotate(360deg); } }`}</style>

      {/* ══════════════ MOBILE: bottom navigation ══════════════ */}
      <nav className="bottom-nav">
        <NavLink to="/dashboard" className={`bottom-nav-item ${navActive('/dashboard') ? 'active' : ''}`}>
          <svg fill="currentColor" viewBox="0 0 16 16">
            <path d="M8.354 1.146a.5.5 0 0 0-.708 0l-6 6-.354.354 1.06 1.06.647-.647V13.5A1.5 1.5 0 0 0 4.5 15h3v-4h1v4h3a1.5 1.5 0 0 0 1.5-1.5V7.207l.354.353 1.354-1.353a.5.5 0 0 0 0-.708z"/>
          </svg>
          <span>Beranda</span>
        </NavLink>

        {user?.is_permanent ? (
          <NavLink to="/leaves" className={`bottom-nav-item ${navActive('/leaves') ? 'active' : ''}`}>
            <svg fill="currentColor" viewBox="0 0 16 16">
              <path d="M4 .5a.5.5 0 0 0-1 0V1H2a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V3a2 2 0 0 0-2-2h-1V.5a.5.5 0 0 0-1 0V1H4zM1 4h14v10a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1zm2 2.5a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 0 1h-9a.5.5 0 0 1-.5-.5m0 2a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 0 1h-9a.5.5 0 0 1-.5-.5m0 2a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 0 1h-9a.5.5 0 0 1-.5-.5"/>
            </svg>
            <span>Cuti</span>
          </NavLink>
        ) : (
          <NavLink to="/attendance/history" className={`bottom-nav-item ${navActive('/attendance/history') ? 'active' : ''}`}>
            <svg fill="currentColor" viewBox="0 0 16 16">
              <path d="M3.5 0a.5.5 0 0 1 .5.5V1h8V.5a.5.5 0 0 1 1 0V1h1a2 2 0 0 1 2 2v1H0V3a2 2 0 0 1 2-2h1V.5a.5.5 0 0 1 .5-.5M16 14a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V5h16z"/>
              <path d="M4.5 7a.5.5 0 0 0 0 1h2a.5.5 0 0 0 0-1zm3 0a.5.5 0 0 0 0 1h2a.5.5 0 0 0 0-1zm3 0a.5.5 0 0 0 0 1h2a.5.5 0 0 0 0-1zm-6 2a.5.5 0 0 0 0 1h2a.5.5 0 0 0 0-1zm3 0a.5.5 0 0 0 0 1h2a.5.5 0 0 0 0-1z"/>
            </svg>
            <span>Riwayat</span>
          </NavLink>
        )}

        {/* Center: Absen button */}
        <NavLink to="/attendance" className={`bottom-nav-absen ${navActive('/attendance') ? 'active' : ''}`}>
          <div className="absen-btn">
            <svg fill="currentColor" viewBox="0 0 16 16">
              <path d="M8 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6m2-3a2 2 0 1 1-4 0 2 2 0 0 1 4 0m4 8c0 1-1 1-1 1H3s-1 0-1-1 1-4 6-4 6 3 6 4m-1-.004c-.001-.246-.154-.986-.832-1.664C11.516 10.68 10.289 10 8 10s-3.516.68-4.168 1.332c-.678.678-.83 1.418-.832 1.664z"/>
            </svg>
          </div>
          <span style={{ marginTop: -6 }}>Absen</span>
        </NavLink>

        <NavLink to="/reimbursements" className={`bottom-nav-item ${navActive('/reimbursements') ? 'active' : ''}`}>
          <svg fill="currentColor" viewBox="0 0 16 16">
            <path d="M1.92.506a.5.5 0 0 1 .434.14L3 1.293l.646-.647a.5.5 0 0 1 .708 0L5 1.293l.646-.647a.5.5 0 0 1 .708 0L7 1.293l.646-.647a.5.5 0 0 1 .708 0L9 1.293l.646-.647a.5.5 0 0 1 .708 0l.646.647.646-.647a.5.5 0 0 1 .708 0l.646.647.646-.647a.5.5 0 0 1 .801.13l.5 1A.5.5 0 0 1 15 2v12a.5.5 0 0 1-.053.224l-.5 1a.5.5 0 0 1-.8.13L13 14.707l-.646.647a.5.5 0 0 1-.708 0L11 14.707l-.646.647a.5.5 0 0 1-.708 0L9 14.707l-.646.647a.5.5 0 0 1-.708 0L7 14.707l-.646.647a.5.5 0 0 1-.708 0L5 14.707l-.646.647a.5.5 0 0 1-.708 0L3 14.707l-.646.647a.5.5 0 0 1-.801-.13l-.5-1A.5.5 0 0 1 1 14V2a.5.5 0 0 1 .053-.224z"/>
          </svg>
          <span>Reimburse</span>
        </NavLink>

        <button
          type="button"
          onClick={() => setMoreMenuOpen(true)}
          className={`bottom-nav-item ${moreMenuOpen ? 'active' : ''}`}
          aria-label="Menu lainnya"
        >
          <svg fill="currentColor" viewBox="0 0 16 16">
            <path d="M2.5 12a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1H3a.5.5 0 0 1-.5-.5m0-4a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1H3a.5.5 0 0 1-.5-.5m0-4a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1H3a.5.5 0 0 1-.5-.5"/>
          </svg>
          <span>Menu</span>
        </button>
      </nav>

      {moreMenuOpen && (
        <MoreMenuSheet
          user={user}
          onClose={() => setMoreMenuOpen(false)}
          onLogout={handleLogout}
        />
      )}

    </div>
  )
}

/** Banner persistent di top content area kalau ada kontrak menunggu tanda tangan. */
function PendingContractBanner() {
  const navigate = useNavigate()
  const location = useLocation()
  const [hasPending, setHasPending] = useState(false)

  useEffect(() => {
    let cancelled = false
    const token = localStorage.getItem('absen_token')
    if (!token) return
    fetch(`${import.meta.env.VITE_API_BASE_URL ?? ''}/api/v1/me/contract`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    })
      .then((r) => r.ok ? r.json() : null)
      .then((j) => { if (!cancelled) setHasPending(!!j?.data?.pending) })
      .catch(() => {})
    return () => { cancelled = true }
  }, [location.pathname])

  if (!hasPending || location.pathname === '/contract/sign') return null

  return (
    <div style={{
      margin: '12px 16px 0',
      padding: '12px 14px',
      background: 'linear-gradient(135deg, #1a7a8c, #0d5a6a)',
      color: '#fff',
      borderRadius: 12,
      display: 'flex',
      alignItems: 'center',
      gap: 12,
      boxShadow: '0 8px 24px rgba(13, 90, 106, .25)',
    }}>
      <div style={{ fontSize: 26 }}>📄</div>
      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{ fontWeight: 700, fontSize: 13 }}>Kontrak Menunggu Tanda Tangan</div>
        <div style={{ fontSize: 11, opacity: .9 }}>HR sudah menerbitkan kontrak kerja kamu - silakan baca dan tanda tangan.</div>
      </div>
      <button
        onClick={() => navigate('/contract/sign')}
        style={{
          padding: '8px 14px', background: '#fff', color: '#0d5a6a',
          border: 'none', borderRadius: 8, fontWeight: 700, fontSize: 12,
          cursor: 'pointer', whiteSpace: 'nowrap',
        }}
      >
        Tanda Tangani
      </button>
    </div>
  )
}

function MoreMenuSheet({ user, onClose, onLogout }: {
  user: ReturnType<typeof useAuth>['user']
  onClose: () => void
  onLogout: () => void | Promise<void>
}) {
  const navigate = useNavigate()
  const go = (path: string) => { onClose(); navigate(path) }
  const hasWebmail = !!user?.email?.endsWith('@ibereality.com')

  return (
    <div
      onClick={onClose}
      style={{
        position: 'fixed', inset: 0, background: 'rgba(15,23,42,.45)', backdropFilter: 'blur(2px)',
        zIndex: 1500, display: 'flex', alignItems: 'flex-end',
      }}
    >
      <div
        onClick={(e) => e.stopPropagation()}
        style={{
          background: '#fff', width: '100%', borderRadius: '20px 20px 0 0',
          padding: '12px 16px 24px', maxHeight: '85vh', overflowY: 'auto',
          boxShadow: '0 -10px 40px rgba(0,0,0,.18)',
          animation: 'slide-up .22s ease-out',
        }}
      >
        <div style={{
          width: 40, height: 4, background: '#cbd5e1', borderRadius: 2,
          margin: '4px auto 14px',
        }} />
        <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 14, padding: '0 4px' }}>
          <img
            src={user?.avatar ?? undefined}
            alt={user?.name}
            style={{ width: 44, height: 44, borderRadius: '50%', objectFit: 'cover' }}
          />
          <div style={{ flex: 1, minWidth: 0 }}>
            <div style={{ fontWeight: 700, fontSize: 14, color: '#0f172a', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{user?.name}</div>
            <div style={{ fontSize: 12, color: '#64748b', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{user?.email}</div>
          </div>
        </div>

        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 10, marginBottom: 8 }}>
          <MoreTile label="Riwayat" icon="📅" onClick={() => go('/attendance/history')} />
          <MoreTile label="Kartu ID" icon="🪪" onClick={() => go('/id-card')} />
          <MoreTile label="Edit Profil" icon="👤" onClick={() => go('/profile/edit')} />
          {user?.is_permanent && <MoreTile label="Kontrak" icon="📄" onClick={() => go('/contract/sign')} />}
          {user?.is_permanent && <MoreTile label="Slip Gaji" icon="📄" onClick={() => go('/payslips')} />}
          {user?.is_permanent && <MoreTile label="Cuti" icon="🌴" onClick={() => go('/leaves')} />}
          {user?.is_permanent && <MoreTile label="Lembur" icon="⏰" onClick={() => go('/overtimes')} />}
          {user?.is_permanent && <MoreTile label="Kasbon" icon="💰" onClick={() => go('/salary-advances')} />}
          {hasWebmail && <MoreTile label="Email (Portal)" icon="📧" onClick={() => window.open('https://portal.ibereality.com/platforms', '_blank')} />}
          {user?.permissions?.is_admin && <MoreTile label="Admin" icon="🛠️" onClick={() => go('/admin')} />}
          <MoreTile label="Face Setup" icon="🙂" onClick={() => go('/face-setup')} />
        </div>

        <button
          type="button"
          onClick={onLogout}
          style={{
            width: '100%', padding: '12px', marginTop: 12,
            background: '#fef2f2', color: '#b91c1c', border: '1px solid #fecaca',
            borderRadius: 12, fontWeight: 600, fontSize: 14, cursor: 'pointer',
          }}
        >
          Keluar
        </button>
      </div>
      <style>{`@keyframes slide-up { from { transform: translateY(100%); } to { transform: translateY(0); } }`}</style>
    </div>
  )
}

function MoreTile({ label, icon, onClick }: { label: string; icon: string; onClick: () => void }) {
  return (
    <button
      type="button"
      onClick={onClick}
      style={{
        display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center',
        gap: 6, padding: '14px 6px', background: '#f8fafc', border: '1px solid #e2e8f0',
        borderRadius: 14, fontSize: 12, fontWeight: 600, color: '#334155', cursor: 'pointer',
      }}
    >
      <span style={{ fontSize: 24, lineHeight: 1 }}>{icon}</span>
      <span>{label}</span>
    </button>
  )
}
