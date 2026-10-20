import { useEffect, useState, FormEvent } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useAuth } from '@/context/AuthContext'
import { getSsoPortalUrl, isSsoRequired, redirectToSsoPortal, resolvePortalNextPath } from '@/lib/sso'

export default function LoginPage() {
  const { login } = useAuth()
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const ssoRequired = isSsoRequired()
  const ssoPortalUrl = getSsoPortalUrl()
  // Prefer ?next= di URL → sessionStorage return_to (dari soft-logout modal)
  // → default /platforms. Pakai useState supaya hanya di-resolve sekali di
  // mount (read sessionStorage punya side effect cleanup).
  const [nextPath] = useState(() => {
    const urlNext = resolvePortalNextPath(searchParams.get('next'))
    if (urlNext) return urlNext
    try {
      const stored = sessionStorage.getItem('return_to')
      if (stored && stored.startsWith('/') && !stored.startsWith('//') && !stored.startsWith('/login')) {
        sessionStorage.removeItem('return_to')
        return stored
      }
    } catch { /* ignore */ }
    return '/platforms'
  })
  const [identifier, setIdentifier] = useState('')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)

  useEffect(() => {
    if (!ssoRequired) {
      return
    }

    const timeout = window.setTimeout(() => {
      redirectToSsoPortal(nextPath)
    }, 1200)

    return () => window.clearTimeout(timeout)
  }, [nextPath, ssoRequired])

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault()
    setError('')
    setLoading(true)
    try {
      await login({ identifier, password })
      navigate(nextPath)
    } catch (err: unknown) {
      const e = err as { response?: { data?: { message?: string } } }
      setError(e?.response?.data?.message ?? 'Email/telepon atau password salah.')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div style={{
      minHeight: '100dvh',
      display: 'flex',
      flexDirection: 'column',
      background: 'linear-gradient(160deg, #1a7a8c 0%, #0d5a6a 100%)',
      fontFamily: "'Rubik', sans-serif",
    }}>

      {/* ── Hero section ── */}
      <div style={{
        flex: '0 0 auto',
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        justifyContent: 'center',
        padding: '52px 24px 36px',
        gap: 16,
      }}>
        {/* Logo */}
        <div style={{
          width: 88, height: 88, borderRadius: 22,
          background: '#fff',
          display: 'flex', alignItems: 'center', justifyContent: 'center',
          boxShadow: '0 8px 32px rgba(0,0,0,0.18)',
          padding: 10,
        }}>
          <img src="/logo.png" alt="IBE Reality" style={{ width: '100%', height: '100%', objectFit: 'contain' }} />
        </div>

        <div style={{ textAlign: 'center' }}>
          <div style={{ fontSize: 26, fontWeight: 800, color: '#fff', lineHeight: 1.2, letterSpacing: -0.5 }}>
            IBE Reality
          </div>
          <div style={{ fontSize: 13, color: 'rgba(255,255,255,0.75)', marginTop: 4 }}>
            Portal Karyawan · HR & Absensi
          </div>
        </div>

        {/* Decorative dots */}
        <div style={{ display: 'flex', gap: 6, marginTop: 4 }}>
          {[1,2,3].map(i => (
            <div key={i} style={{
              width: i === 2 ? 20 : 6, height: 6, borderRadius: 3,
              background: i === 2 ? '#fff' : 'rgba(255,255,255,0.4)',
              transition: 'all 0.3s',
            }} />
          ))}
        </div>
      </div>

      {/* ── Form card - slides up from bottom ── */}
      <div style={{
        flex: 1,
        background: '#fff',
        borderRadius: '28px 28px 0 0',
        padding: '32px 24px 40px',
        boxShadow: '0 -8px 40px rgba(0,0,0,0.12)',
      }}>
        <div style={{ marginBottom: 28 }}>
          <h2 style={{ fontSize: 22, fontWeight: 700, color: '#1a1a2e', marginBottom: 6 }}>
            {ssoRequired ? 'Login dialihkan ke SSO' : 'Selamat Datang 👋'}
          </h2>
          <p style={{ fontSize: 13, color: '#9e9e9e', margin: 0 }}>
            {ssoRequired
              ? 'Akses ERP ini wajib melalui portal SSO terpusat.'
              : 'Masuk untuk mengakses portal karyawan'}
          </p>
        </div>

        {error && !ssoRequired && (
          <div style={{
            background: '#fff5f5', border: '1px solid #ffcdd2', borderRadius: 10,
            padding: '10px 14px', marginBottom: 20, fontSize: 13, color: '#c62828',
            display: 'flex', alignItems: 'center', gap: 8,
          }}>
            <span>⚠️</span> {error}
          </div>
        )}

        {ssoRequired ? (
          <div style={{ display: 'grid', gap: 14 }}>
            <div style={{
              background: '#eff6ff',
              border: '1px solid #bfdbfe',
              borderRadius: 14,
              padding: '14px 16px',
              color: '#1e3a8a',
              fontSize: 13,
              lineHeight: 1.6,
            }}>
              Login lokal dinonaktifkan. Gunakan portal SSO untuk memilih platform dan masuk ke ERP Backend.
            </div>

            <button
              type="button"
              onClick={() => redirectToSsoPortal(nextPath)}
              style={{
                width: '100%', padding: '15px',
                background: 'linear-gradient(135deg, #1a7a8c 0%, #0d5a6a 100%)',
                border: 'none', borderRadius: 14, color: '#fff',
                fontSize: 15, fontWeight: 700, fontFamily: 'inherit',
                cursor: 'pointer',
                boxShadow: '0 6px 20px rgba(26,122,140,0.45)',
                transition: 'all 0.2s', letterSpacing: 0.3,
              }}
            >
              Lanjut ke Portal SSO
            </button>

            {ssoPortalUrl ? (
              <div style={{ fontSize: 11, color: '#94a3b8', textAlign: 'center' }}>
                Redirect tujuan: {ssoPortalUrl}
              </div>
            ) : null}
          </div>
        ) : (
        <form onSubmit={handleSubmit}>
          {/* Email / Phone */}
          <div style={{ marginBottom: 16 }}>
            <label style={{ fontSize: 12, fontWeight: 600, color: '#555', display: 'block', marginBottom: 6, textTransform: 'uppercase', letterSpacing: 0.5 }}>
              Email atau No. Telepon
            </label>
            <div style={{ position: 'relative' }}>
              <span style={{ position: 'absolute', left: 14, top: '50%', transform: 'translateY(-50%)', fontSize: 16 }}>
                👤
              </span>
              <input
                type="text"
                placeholder="email@perusahaan.com"
                value={identifier}
                onChange={e => setIdentifier(e.target.value)}
                required
                style={{
                  width: '100%', padding: '14px 14px 14px 44px',
                  border: '1.5px solid #ebebeb', borderRadius: 12,
                  fontSize: 14, fontFamily: 'inherit', outline: 'none',
                  background: '#fafafa', boxSizing: 'border-box',
                  transition: 'border-color 0.2s',
                }}
                onFocus={e => e.target.style.borderColor = '#1a7a8c'}
                onBlur={e => e.target.style.borderColor = '#ebebeb'}
              />
            </div>
          </div>

          {/* Password */}
          <div style={{ marginBottom: 28 }}>
            <label style={{ fontSize: 12, fontWeight: 600, color: '#555', display: 'block', marginBottom: 6, textTransform: 'uppercase', letterSpacing: 0.5 }}>
              Password
            </label>
            <div style={{ position: 'relative' }}>
              <span style={{ position: 'absolute', left: 14, top: '50%', transform: 'translateY(-50%)', fontSize: 16 }}>
                🔒
              </span>
              <input
                type={showPassword ? 'text' : 'password'}
                placeholder="••••••••"
                value={password}
                onChange={e => setPassword(e.target.value)}
                required
                style={{
                  width: '100%', padding: '14px 48px 14px 44px',
                  border: '1.5px solid #ebebeb', borderRadius: 12,
                  fontSize: 14, fontFamily: 'inherit', outline: 'none',
                  background: '#fafafa', boxSizing: 'border-box',
                  transition: 'border-color 0.2s',
                }}
                onFocus={e => e.target.style.borderColor = '#1a7a8c'}
                onBlur={e => e.target.style.borderColor = '#ebebeb'}
              />
              <button
                type="button"
                onClick={() => setShowPassword(!showPassword)}
                style={{
                  position: 'absolute', right: 14, top: '50%', transform: 'translateY(-50%)',
                  background: 'none', border: 'none', cursor: 'pointer', fontSize: 16, padding: 4,
                }}
              >
                {showPassword ? '🙈' : '👁️'}
              </button>
            </div>
          </div>

          {/* Submit button */}
          <button
            type="submit"
            disabled={loading}
            style={{
              width: '100%', padding: '15px',
              background: loading ? '#7ab8c4' : 'linear-gradient(135deg, #1a7a8c 0%, #0d5a6a 100%)',
              border: 'none', borderRadius: 14, color: '#fff',
              fontSize: 15, fontWeight: 700, fontFamily: 'inherit',
              cursor: loading ? 'not-allowed' : 'pointer',
              boxShadow: loading ? 'none' : '0 6px 20px rgba(26,122,140,0.45)',
              transition: 'all 0.2s', letterSpacing: 0.3,
              display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 8,
            }}
          >
            {loading ? (
              <>
                <span style={{
                  width: 18, height: 18, borderRadius: '50%',
                  border: '2.5px solid rgba(255,255,255,0.35)',
                  borderTopColor: '#fff',
                  display: 'inline-block',
                  animation: 'spin 0.7s linear infinite',
                }} />
                Memproses...
              </>
            ) : (
              <>Masuk <span style={{ fontSize: 18 }}>→</span></>
            )}
          </button>
        </form>
        )}

        {/* Footer */}
        <div style={{ textAlign: 'center', marginTop: 32 }}>
          <div style={{ fontSize: 11, color: '#ccc' }}>
            PT. Integrasi Bisnis Eksekutif &copy; {new Date().getFullYear()}
          </div>
          <div style={{ fontSize: 10, color: '#ddd', marginTop: 3 }}>
            v2.0 · Powered by IBE Reality
          </div>
        </div>
      </div>

      <style>{`
        @keyframes spin { to { transform: rotate(360deg); } }
        input::placeholder { color: #bbb; }
      `}</style>
    </div>
  )
}
