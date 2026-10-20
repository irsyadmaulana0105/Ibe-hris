import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { exchangeSsoToken } from '@/api/auth'
import { setStoredToken } from '@/api/client'
import { useAuth } from '@/context/AuthContext'
import { usePageTitle } from '@/hooks/usePageTitle'
import { consumeSsoCallbackParams } from '@/lib/ssoCallback'

export default function SsoCallbackPage() {
  usePageTitle('Memproses SSO')
  const navigate = useNavigate()
  const { refreshUser } = useAuth()
  const [error, setError] = useState('')

  useEffect(() => {
    let cancelled = false

    async function exchange() {
      const params = consumeSsoCallbackParams()
      const ssoToken = params.get('sso_token')
      const targetApp = params.get('target_app')

      if (!ssoToken || !targetApp) {
        setError('Parameter SSO tidak lengkap.')
        return
      }

      try {
        const data = await exchangeSsoToken({
          sso_token: ssoToken,
          target_app: targetApp,
        })

        if (cancelled) return

        setStoredToken(data.access_token)
        await refreshUser()

        // This SPA hosts both ERP backend AND attendance - always land at /dashboard.
        // (Previously `/platforms` for non-erp targets caused infinite loop with the
        //  launcher page redirecting back to /launch/attendance → /sso/callback.)
        navigate('/dashboard', { replace: true })
      } catch (err: unknown) {
        if (cancelled) return
        const message = err instanceof Error ? err.message : 'Gagal menyelesaikan login SSO.'
        setError(message)
      }
    }

    void exchange()

    return () => {
      cancelled = true
    }
  }, [navigate, refreshUser])

  return (
    <div className="portal-shell">
      <section className="portal-launch-card">
        <div className="portal-launch-card__badge">SSO Callback</div>
        <h1 className="portal-launch-card__title">
          {error ? 'SSO gagal diproses' : 'Menyelesaikan login platform'}
        </h1>
        <p className="portal-launch-card__text">
          {error
            ? error
            : 'Token sementara sedang ditukar menjadi sesi aplikasi penuh.'}
        </p>

        {error ? (
          <Link to="/platforms" className="btn btn-primary">
            Kembali ke Portal
          </Link>
        ) : (
          <div className="portal-loading portal-loading--dark">
            <div className="spinner-border text-primary" role="status" />
            <span>Sinkronisasi identitas...</span>
          </div>
        )}
      </section>
    </div>
  )
}
