import { useEffect, useMemo, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { getSsoPlatforms } from '@/api/auth'
import { useAuth } from '@/context/AuthContext'
import { usePageTitle } from '@/hooks/usePageTitle'
import { resolvePortalNextPath } from '@/lib/sso'
import type { SsoPlatform } from '@/types'

const platformIcons: Record<string, string> = {
  erp: 'ERP',
  attendance: 'ABS',
  projects: 'PRJ',
}

const platformToneClass: Record<string, string> = {
  erp: 'portal-platform-card--erp',
  attendance: 'portal-platform-card--attendance',
  projects: 'portal-platform-card--projects',
}

export default function PlatformLauncherPage() {
  usePageTitle('Pilih Platform')
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const [platforms, setPlatforms] = useState<SsoPlatform[]>([])
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState('')
  const nextPath = resolvePortalNextPath(searchParams.get('next'))

  useEffect(() => {
    getSsoPlatforms()
      .then(setPlatforms)
      .catch(() => setError('Gagal memuat daftar platform.'))
      .finally(() => setIsLoading(false))
  }, [])

  useEffect(() => {
    if (!nextPath || nextPath === '/platforms' || isLoading || error) {
      return
    }

    navigate(nextPath, { replace: true })
  }, [error, isLoading, navigate, nextPath])

  const activePlatforms = useMemo(
    () => platforms.filter((platform) => platform.enabled && platform.allowed),
    [platforms],
  )

  return (
    <div className="portal-shell">
      <section className="portal-hero">
        <div className="portal-hero__copy">
          <span className="portal-eyebrow">IBE SSO Portal</span>
          <h1 className="portal-hero__title">Pilih platform kerja Anda</h1>
          <p className="portal-hero__subtitle">
            Satu akun untuk mengakses ERP internal, absensi karyawan, dan workspace projek IBE.
          </p>
        </div>

        <div className="portal-profile">
          <div className="portal-profile__avatar">
            {user?.name?.slice(0, 2).toUpperCase()}
          </div>
          <div className="portal-profile__meta">
            <strong>{user?.name}</strong>
            <span>{user?.company?.name ?? 'IBE Reality'}</span>
            <span>{user?.permissions?.is_admin ? 'Admin Workspace' : 'Employee Workspace'}</span>
          </div>
          <button type="button" className="btn btn-light btn-sm" onClick={() => void logout()}>
            Logout
          </button>
        </div>
      </section>

      {error ? (
        <div className="alert alert-danger">{error}</div>
      ) : null}

      <section className="portal-grid">
        {isLoading ? (
          <div className="portal-loading">
            <div className="spinner-border text-light" role="status" />
            <span>Memuat platform...</span>
          </div>
        ) : (
          platforms.map((platform) => {
            const disabled = !platform.enabled || !platform.allowed

            return (
              <article
                key={platform.key}
                className={`portal-platform-card ${platformToneClass[platform.key] ?? ''} ${disabled ? 'portal-platform-card--disabled' : ''}`}
              >
                <div className="portal-platform-card__icon">
                  {platformIcons[platform.key] ?? platform.key.slice(0, 3).toUpperCase()}
                </div>
                <div className="portal-platform-card__body">
                  <div className="portal-platform-card__top">
                    <div>
                      <div className="portal-platform-card__title">{platform.name}</div>
                      <div className="portal-platform-card__status">{platform.status_label}</div>
                    </div>
                  </div>
                  <p className="portal-platform-card__text">{platform.description}</p>
                </div>

                {disabled ? (
                  <button type="button" className="btn btn-light portal-platform-card__action" disabled>
                    {platform.enabled ? 'Akses Belum Dibuka' : 'Segera Hadir'}
                  </button>
                ) : (
                  <Link to={`/launch/${platform.key}`} className="btn btn-light portal-platform-card__action">
                    Masuk ke Platform
                  </Link>
                )}
              </article>
            )
          })
        )}
      </section>

      <section className="portal-summary">
        <div className="portal-summary__item">
          <span>Platform aktif</span>
          <strong>{activePlatforms.length}</strong>
        </div>
        <div className="portal-summary__item">
          <span>Akses utama</span>
          <strong>{user?.permissions?.is_admin ? 'ERP Backend' : 'Employee Self-Service'}</strong>
        </div>
      </section>
    </div>
  )
}
