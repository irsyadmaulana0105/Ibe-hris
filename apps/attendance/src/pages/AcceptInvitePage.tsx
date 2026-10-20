import { useState } from 'react'
import { useSearchParams, useNavigate } from 'react-router-dom'
import { acceptInvite } from '@/api/auth'
import { setStoredToken } from '@/api/client'
import { useAuth } from '@/context/AuthContext'
import { getErrorMessage, getValidationErrors } from '@/utils/apiError'

export default function AcceptInvitePage() {
  const [searchParams] = useSearchParams()
  const navigate = useNavigate()
  const { refreshUser } = useAuth()

  const tokenFromUrl = searchParams.get('token') ?? ''

  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')
  const [isLoading, setIsLoading] = useState(false)
  const [error, setError] = useState('')
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({})

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    setError('')
    setFieldErrors({})
    setIsLoading(true)

    try {
      const { token } = await acceptInvite({
        token: tokenFromUrl,
        password,
        password_confirmation: passwordConfirmation,
      })
      setStoredToken(token)
      await refreshUser()
      navigate('/dashboard', { replace: true })
    } catch (err: unknown) {
      setError(getErrorMessage(err, 'Gagal mengatur password.'))
      setFieldErrors(getValidationErrors(err))
    } finally {
      setIsLoading(false)
    }
  }

  if (!tokenFromUrl) {
    return (
      <div className="min-vh-100 d-flex align-items-center justify-content-center bg-light">
        <div className="card shadow-sm p-4 text-center" style={{ maxWidth: 420 }}>
          <div className="text-danger mb-2" style={{ fontSize: 40 }}>⚠</div>
          <h5 className="mb-1">Link Tidak Valid</h5>
          <p className="text-muted mb-0" style={{ fontSize: 14 }}>
            Link undangan ini tidak memiliki token. Hubungi admin untuk mendapatkan link baru.
          </p>
        </div>
      </div>
    )
  }

  return (
    <div className="min-vh-100 d-flex align-items-center justify-content-center bg-light">
      <div className="card shadow-sm p-4" style={{ maxWidth: 420, width: '100%' }}>
        <div className="text-center mb-4">
          <div className="fw-bold mb-1" style={{ fontSize: 22 }}>Atur Password Anda</div>
          <p className="text-muted mb-0" style={{ fontSize: 13 }}>
            Buat password baru untuk mengaktifkan akun Anda.
          </p>
        </div>

        {error && (
          <div className="alert alert-danger py-2 mb-3" style={{ fontSize: 13 }}>
            {error}
          </div>
        )}

        <form onSubmit={handleSubmit}>
          <div className="mb-3">
            <label className="form-label" style={{ fontSize: 13, fontWeight: 500 }}>
              Password Baru
            </label>
            <input
              type="password"
              className={`form-control${fieldErrors.password ? ' is-invalid' : ''}`}
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              placeholder="Minimal 6 karakter"
              required
              minLength={6}
              autoFocus
            />
            {fieldErrors.password && (
              <div className="invalid-feedback">{fieldErrors.password[0]}</div>
            )}
          </div>

          <div className="mb-4">
            <label className="form-label" style={{ fontSize: 13, fontWeight: 500 }}>
              Konfirmasi Password
            </label>
            <input
              type="password"
              className={`form-control${fieldErrors.password_confirmation ? ' is-invalid' : ''}`}
              value={passwordConfirmation}
              onChange={(e) => setPasswordConfirmation(e.target.value)}
              placeholder="Ulangi password"
              required
            />
            {fieldErrors.password_confirmation && (
              <div className="invalid-feedback">{fieldErrors.password_confirmation[0]}</div>
            )}
          </div>

          <button
            type="submit"
            className="btn btn-primary w-100"
            disabled={isLoading}
          >
            {isLoading
              ? <><span className="spinner-border spinner-border-sm me-2" />Menyimpan...</>
              : 'Aktifkan Akun'}
          </button>
        </form>

        <p className="text-center text-muted mt-3 mb-0" style={{ fontSize: 12 }}>
          Link ini hanya bisa digunakan sekali dan berlaku 72 jam.
        </p>
      </div>
    </div>
  )
}
