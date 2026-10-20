import { createContext, useCallback, useContext, useEffect, useState } from 'react'
import { clearStoredToken, getStoredToken, setStoredToken } from '@/api/client'
import { login as apiLogin, logout as apiLogout, logoutAll as apiLogoutAll, me as apiMe } from '@/api/auth'
import type { LoginPayload, User } from '@/types'

interface AuthContextValue {
  user: User | null
  isLoading: boolean
  login: (payload: LoginPayload) => Promise<void>
  logout: () => Promise<void>
  logoutAll: () => Promise<void>
  refreshUser: () => Promise<void>
}

const AuthContext = createContext<AuthContextValue | null>(null)

// Idle config - sama dengan portal authority (60 menit, warning 5 menit).
const IDLE_TIMEOUT_MS = 60 * 60 * 1000
const WARNING_BEFORE_MS = 5 * 60 * 1000
const ACTIVITY_EVENTS = ['mousedown', 'keydown', 'touchstart', 'scroll'] as const

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser] = useState<User | null>(null)
  const [isLoading, setIsLoading] = useState(true)
  // Soft logout - token expired tapi UI tetap render dengan modal.
  const [sessionExpired, setSessionExpired] = useState(false)
  const [lastActivity, setLastActivity] = useState(Date.now())

  useEffect(() => {
    if (!getStoredToken()) {
      setIsLoading(false)
      return
    }
    apiMe()
      .then(setUser)
      .catch(() => clearStoredToken())
      .finally(() => setIsLoading(false))
  }, [])

  // Listen for 401 forced-logout fired by the axios interceptor.
  // Soft handle - JANGAN langsung setUser(null) (yang akan trigger redirect
  // mendadak via ProtectedRoute). Tampilkan modal supaya user paham + URL
  // bisa direstore setelah re-login.
  useEffect(() => {
    const handler = () => {
      setUser((u) => {
        if (u) setSessionExpired(true)
        return u
      })
    }
    window.addEventListener('auth:force-logout', handler)
    return () => window.removeEventListener('auth:force-logout', handler)
  }, [])

  const login = useCallback(async (payload: LoginPayload) => {
    const { token, user } = await apiLogin(payload)
    setStoredToken(token)
    setUser(user)
  }, [])

  const logout = useCallback(async () => {
    await apiLogout().catch((err: unknown) => {
      console.warn('[auth] logout API call failed (token may already be invalid):', err)
    })
    clearStoredToken()
    setUser(null)
  }, [])

  /**
   * Logout from every platform (revokes all Sanctum tokens for the user).
   * Use this when the user explicitly wants to end the SSO session across
   * portal, absen, and projects in one go.
   */
  const logoutAll = useCallback(async () => {
    await apiLogoutAll().catch((err: unknown) => {
      console.warn('[auth] logout-all API call failed:', err)
    })
    clearStoredToken()
    setUser(null)
  }, [])

  const refreshUser = useCallback(async () => {
    const updated = await apiMe()
    setUser(updated)
  }, [])

  // ── Idle activity tracker (60 menit) ────────────────────────────────
  useEffect(() => {
    if (!user) return
    let lastUpdate = 0
    const onActivity = () => {
      const now = Date.now()
      if (now - lastUpdate > 1000) { lastUpdate = now; setLastActivity(now) }
    }
    ACTIVITY_EVENTS.forEach((e) => window.addEventListener(e, onActivity, { passive: true }))
    return () => ACTIVITY_EVENTS.forEach((e) => window.removeEventListener(e, onActivity))
  }, [user])

  const [, forceTick] = useState(0)
  useEffect(() => {
    if (!user) return
    const t = setInterval(() => {
      forceTick((x) => x + 1)
      if (Date.now() - lastActivity >= IDLE_TIMEOUT_MS) {
        // Idle limit - trigger soft logout modal (same path as 401)
        setSessionExpired(true)
      }
    }, 15000)
    return () => clearInterval(t)
  }, [user, lastActivity])

  const idleMs = user ? Math.max(0, IDLE_TIMEOUT_MS - (Date.now() - lastActivity)) : null

  // Modal "Login Ulang" - simpan URL terakhir + redirect ke /login.
  // Absen frontend pakai /login route lokal (bukan SSO portal), tapi LoginPage
  // akan check sessionStorage 'return_to' setelah login berhasil dan navigate
  // ke sana. URL juga di-pass via `?next=` agar kalau user buka tab langsung
  // tetap berfungsi.
  const handleReLogin = useCallback(() => {
    if (typeof window === 'undefined') return
    const here = window.location.pathname + window.location.search
    const safe = here.startsWith('/') && !here.startsWith('//') && !here.startsWith('/login')
    if (safe) {
      try { sessionStorage.setItem('return_to', here) } catch { /* ignore */ }
    }
    clearStoredToken()
    setUser(null)
    setSessionExpired(false)
    const next = safe ? `?next=${encodeURIComponent(here)}` : ''
    window.location.replace(`/login${next}`)
  }, [])

  return (
    <AuthContext.Provider value={{ user, isLoading, login, logout, logoutAll, refreshUser }}>
      {children}
      {user && idleMs !== null && idleMs <= WARNING_BEFORE_MS && idleMs > 0 && (
        <IdleWarningBanner
          msRemaining={idleMs}
          onStay={() => setLastActivity(Date.now())}
          onLogout={() => setSessionExpired(true)}
        />
      )}
      {sessionExpired && <SessionExpiredModal onReLogin={handleReLogin} />}
    </AuthContext.Provider>
  )
}

function IdleWarningBanner({ msRemaining, onStay, onLogout }: { msRemaining: number; onStay: () => void; onLogout: () => void }) {
  const min = Math.floor(msRemaining / 60000)
  const sec = Math.floor((msRemaining % 60000) / 1000)
  return (
    <div role="alertdialog" aria-live="assertive" style={{
      position: 'fixed', bottom: 20, right: 20, zIndex: 9999,
      background: '#fff', border: '1px solid #f97316', borderLeft: '4px solid #f97316',
      borderRadius: 8, padding: '14px 18px', boxShadow: '0 10px 25px rgba(0,0,0,0.15)',
      maxWidth: 320, fontSize: 13, color: '#0f172a',
    }}>
      <div style={{ fontWeight: 600, marginBottom: 6 }}>⏱️ Session akan berakhir</div>
      <p style={{ margin: '0 0 10px', color: '#475569' }}>
        Tidak ada aktivitas. Otomatis logout dalam{' '}
        <strong>{min}:{String(sec).padStart(2, '0')}</strong>.
      </p>
      <div style={{ display: 'flex', gap: 8 }}>
        <button type="button" onClick={onStay} style={{
          flex: 1, padding: '6px 10px', background: '#0d9488', color: 'white',
          border: 'none', borderRadius: 6, cursor: 'pointer', fontWeight: 500,
        }}>Saya masih di sini</button>
        <button type="button" onClick={onLogout} style={{
          padding: '6px 10px', background: '#fff', color: '#475569',
          border: '1px solid #cbd5e1', borderRadius: 6, cursor: 'pointer',
        }}>Logout</button>
      </div>
    </div>
  )
}

function SessionExpiredModal({ onReLogin }: { onReLogin: () => void }) {
  return (
    <div
      role="alertdialog" aria-modal="true" aria-labelledby="session-expired-title"
      style={{
        position: 'fixed', inset: 0, zIndex: 10000,
        background: 'rgba(15,23,42,0.6)',
        display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20,
      }}
    >
      <div style={{
        background: 'white', borderRadius: 12, padding: '28px 32px',
        maxWidth: 400, width: '100%', boxShadow: '0 25px 50px rgba(0,0,0,0.25)', textAlign: 'center',
      }}>
        <div style={{ fontSize: 48, marginBottom: 12 }} aria-hidden>🔒</div>
        <h2 id="session-expired-title" style={{ margin: '0 0 8px', fontSize: 20, fontWeight: 700, color: '#0f172a' }}>
          Sesi Anda telah berakhir
        </h2>
        <p style={{ margin: '0 0 24px', fontSize: 14, color: '#475569', lineHeight: 1.5 }}>
          Demi keamanan, silakan login kembali untuk melanjutkan.
          Halaman terakhir akan dibuka otomatis setelah login berhasil.
        </p>
        <button
          type="button" onClick={onReLogin} autoFocus
          style={{
            width: '100%', padding: '12px 24px',
            background: '#0d9488', color: 'white', border: 'none', borderRadius: 8,
            fontSize: 15, fontWeight: 600, cursor: 'pointer',
          }}
        >
          Login Ulang
        </button>
      </div>
    </div>
  )
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used within AuthProvider')
  return ctx
}
