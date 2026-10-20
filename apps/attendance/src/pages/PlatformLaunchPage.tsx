import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { generateSsoToken, getSsoPlatforms } from '@/api/auth'
import type { SsoPlatform } from '@/types'
import { usePageTitle } from '@/hooks/usePageTitle'

export default function PlatformLaunchPage() {
  usePageTitle('Menghubungkan Platform')
  const navigate = useNavigate()
  const { platformKey = '' } = useParams()
  const [platform, setPlatform] = useState<SsoPlatform | null>(null)
  const [error, setError] = useState('')

  useEffect(() => {
    let cancelled = false

    async function launch() {
      try {
        const platforms = await getSsoPlatforms()
        const current = platforms.find((item) => item.key === platformKey) ?? null

        if (!current) {
          throw new Error('Platform tidak ditemukan.')
        }

        if (cancelled) return
        setPlatform(current)

        if (!current.enabled) {
          throw new Error('Platform ini belum aktif.')
        }

        if (!current.allowed) {
          throw new Error('Akses Anda ke platform ini belum tersedia.')
        }

        const data = await generateSsoToken(platformKey)

        if (cancelled) return
        window.location.assign(data.redirect_url)
      } catch (err: unknown) {
        if (cancelled) return
        const message = err instanceof Error ? err.message : 'Gagal memulai sesi platform.'
        setError(message)
      }
    }

    void launch()

    return () => {
      cancelled = true
    }
  }, [platformKey, navigate])

  return (
    <div className="portal-shell">
      <section className="portal-launch-card">
        <div className="portal-launch-card__badge">SSO Bridge</div>
        <h1 className="portal-launch-card__title">
          {error ? 'Platform belum dapat dibuka' : `Menghubungkan ke ${platform?.name ?? 'platform'}...`}
        </h1>
        <p className="portal-launch-card__text">
          {error
            ? error
            : 'Portal sedang membuat token SSO sekali pakai dan menyiapkan sesi kerja Anda.'}
        </p>

        {error ? (
          <div className="d-flex flex-column flex-sm-row gap-2 justify-content-center">
            <Link to="/platforms" className="btn btn-primary">
              Kembali ke Portal
            </Link>
          </div>
        ) : (
          <div className="portal-loading portal-loading--dark">
            <div className="spinner-border text-primary" role="status" />
            <span>Menyiapkan redirect aman...</span>
          </div>
        )}
      </section>
    </div>
  )
}
