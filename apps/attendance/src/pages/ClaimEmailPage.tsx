import { useEffect, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { usePageTitle } from '@/hooks/usePageTitle'
import client from '@/api/client'

interface ClaimInfo {
  name: string
  suggested_username: string
  domain: string
  expires_at: string
}

interface ClaimResult {
  email: string
  password: string
  login_url: string
  webmail_url: string
}

export default function ClaimEmailPage() {
  usePageTitle('Aktivasi Email IBE')
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const token = params.get('token') ?? ''

  const [info, setInfo] = useState<ClaimInfo | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [username, setUsername] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [fieldErr, setFieldErr] = useState<string | null>(null)
  const [result, setResult] = useState<ClaimResult | null>(null)
  const [availability, setAvailability] = useState<{ checking: boolean; available: boolean | null; message: string }>({
    checking: false, available: null, message: '',
  })

  useEffect(() => {
    if (!token) { setLoadError('Token tidak ada di URL.'); return }
    client.get<{ data: ClaimInfo }>(`/auth/claim-email/${token}`)
      .then((r) => {
        setInfo(r.data.data)
        setUsername(r.data.data.suggested_username)
      })
      .catch((e) => setLoadError(e?.response?.data?.message ?? 'Link aktivasi sudah tidak berlaku.'))
  }, [token])

  const validUsername = /^[a-z0-9][a-z0-9._-]{1,30}$/.test(username)

  // Debounced availability check - query backend setiap user berhenti mengetik 400ms.
  useEffect(() => {
    if (!token || !validUsername) {
      setAvailability({ checking: false, available: null, message: '' })
      return
    }
    setAvailability({ checking: true, available: null, message: '' })
    const handle = setTimeout(async () => {
      try {
        const res = await fetch(
          `${import.meta.env.VITE_API_BASE_URL ?? ''}/api/v1/auth/claim-email/${token}/check?username=${encodeURIComponent(username)}`,
          { headers: { Accept: 'application/json' } },
        )
        const j = await res.json()
        setAvailability({ checking: false, available: !!j.available, message: j.message ?? '' })
      } catch {
        setAvailability({ checking: false, available: null, message: 'Gagal cek ketersediaan.' })
      }
    }, 400)
    return () => clearTimeout(handle)
  }, [token, username, validUsername])

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    setFieldErr(null)
    if (!validUsername) {
      setFieldErr('Username hanya huruf kecil, angka, titik/strip/underscore (2–32 karakter, mulai huruf/angka).')
      return
    }
    setSubmitting(true)
    try {
      const { data } = await client.post<{ data: ClaimResult }>('/auth/claim-email', { token, username })
      setResult(data.data)
    } catch (e: any) {
      setFieldErr(e?.response?.data?.errors?.username?.[0] ?? e?.response?.data?.message ?? 'Gagal memproses.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div style={{
      minHeight: '100dvh', display: 'flex', alignItems: 'center', justifyContent: 'center',
      padding: 16, background: 'linear-gradient(135deg,#1a7a8c 0%,#0a363d 100%)',
    }}>
      <div style={{
        background: '#fff', borderRadius: 18, padding: '28px 24px', maxWidth: 460, width: '100%',
        boxShadow: '0 30px 80px rgba(0,0,0,.25)',
      }}>
        <div style={{ textAlign: 'center', marginBottom: 16 }}>
          <div style={{
            width: 56, height: 56, margin: '0 auto 10px', borderRadius: 16,
            background: 'linear-gradient(135deg,#1a7a8c,#ff9d2e)',
            display: 'grid', placeItems: 'center', color: '#fff', fontWeight: 800, fontSize: 18,
          }}>IBE</div>
          <h2 style={{ margin: 0, fontSize: 20, fontWeight: 800, letterSpacing: '-.01em' }}>Aktivasi Email IBE Reality</h2>
        </div>

        {loadError && (
          <div style={{
            padding: 14, background: '#fee2e2', border: '1px solid #fecaca', color: '#991b1b',
            borderRadius: 10, fontSize: 13, marginBottom: 12,
          }}>
            {loadError}
          </div>
        )}

        {info && !result && (
          <form onSubmit={submit}>
            <p style={{ margin: '0 0 12px', fontSize: 13, color: '#475569' }}>
              Halo <strong>{info.name}</strong>, pilih username yang akan jadi alamat email perusahaan kamu.
            </p>

            <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#475569', marginBottom: 6 }}>
              Username
            </label>
            <input
              type="text"
              value={username}
              onChange={(e) => setUsername(e.target.value.toLowerCase().trim())}
              placeholder="contoh: arummp"
              style={{
                width: '100%', padding: '12px 14px', border: '1px solid #cbd5e1',
                borderRadius: 10, outline: 'none', fontSize: 16,
                fontFamily: 'monospace', boxSizing: 'border-box',
              }}
              autoFocus
            />
            {username && (
              <div style={{
                marginTop: 6, fontSize: 12, color: '#475569',
                background: '#f1f5f9', borderRadius: 8, padding: '8px 12px',
                fontFamily: 'monospace', wordBreak: 'break-all',
              }}>
                Email kamu: <strong style={{ color: '#0d5a6a' }}>{username}@{info.domain}</strong>
              </div>
            )}

            {/* Availability indicator */}
            {validUsername && (
              <div style={{
                marginTop: 8, padding: '8px 12px', borderRadius: 8, fontSize: 12, fontWeight: 600,
                display: 'flex', alignItems: 'center', gap: 8,
                background: availability.checking
                  ? '#f1f5f9'
                  : availability.available === true ? '#dcfce7'
                  : availability.available === false ? '#fef2f2'
                  : '#f1f5f9',
                color: availability.checking
                  ? '#475569'
                  : availability.available === true ? '#166534'
                  : availability.available === false ? '#b91c1c'
                  : '#475569',
              }}>
                {availability.checking ? (
                  <>⏳ Cek ketersediaan...</>
                ) : availability.available === true ? (
                  <>✓ Username tersedia - bisa kamu pakai.</>
                ) : availability.available === false ? (
                  <>✗ {availability.message || 'Sudah dipakai.'}</>
                ) : null}
              </div>
            )}
            <div style={{ fontSize: 11, color: '#94a3b8', marginTop: 6 }}>
              Huruf kecil, angka, dan tanda <code>._-</code> (2–32 karakter). Tidak bisa diubah setelah dipilih.
            </div>
            {fieldErr && (
              <div style={{
                marginTop: 8, padding: 10, background: '#fef2f2', color: '#b91c1c',
                borderRadius: 8, fontSize: 12,
              }}>
                {fieldErr}
              </div>
            )}

            <button
              type="submit"
              disabled={submitting || !validUsername || availability.available !== true}
              style={{
                marginTop: 16, width: '100%', padding: 12, fontSize: 14, fontWeight: 700,
                background: (validUsername && availability.available === true)
                  ? 'linear-gradient(135deg,#1a7a8c,#0d5a6a)' : '#cbd5e1',
                color: '#fff', border: 'none', borderRadius: 10,
                cursor: (validUsername && availability.available === true && !submitting) ? 'pointer' : 'not-allowed',
              }}
            >
              {submitting ? 'Memproses...' : 'Aktivasi Email Saya'}
            </button>
          </form>
        )}

        {result && (
          <div>
            <div style={{
              padding: 12, background: '#dcfce7', border: '1px solid #86efac', color: '#166534',
              borderRadius: 10, fontSize: 13, marginBottom: 14, textAlign: 'center', fontWeight: 600,
            }}>
              ✓ Email berhasil diaktifkan!
            </div>

            <CredRow label="Email baru" value={result.email} />
            <CredRow label="Password awal" value={result.password} mono />

            <div style={{ marginTop: 14, padding: 12, background: '#fef9c3', color: '#854d0e', borderRadius: 10, fontSize: 12 }}>
              Catat / screenshot kredensial ini sekarang. Password tidak ditampilkan ulang.
              Setelah login pertama kamu bisa ganti via menu Profil.
            </div>

            <button
              onClick={() => navigate('/login')}
              style={{
                marginTop: 16, width: '100%', padding: 12, fontSize: 14, fontWeight: 700,
                background: 'linear-gradient(135deg,#1a7a8c,#0d5a6a)', color: '#fff',
                border: 'none', borderRadius: 10, cursor: 'pointer',
              }}
            >
              Login dengan Email Baru →
            </button>
            <a
              href={result.webmail_url}
              target="_blank"
              rel="noreferrer"
              style={{
                display: 'block', marginTop: 8, textAlign: 'center', padding: 10,
                color: '#1a7a8c', fontSize: 12, fontWeight: 600, textDecoration: 'none',
              }}
            >
              Atau buka webmail (cek inbox/spam) →
            </a>
          </div>
        )}
      </div>
    </div>
  )
}

function CredRow({ label, value, mono }: { label: string; value: string; mono?: boolean }) {
  const [copied, setCopied] = useState(false)
  return (
    <div style={{ marginBottom: 10 }}>
      <div style={{ fontSize: 11, fontWeight: 700, color: '#64748b', textTransform: 'uppercase', letterSpacing: '.06em', marginBottom: 4 }}>{label}</div>
      <div style={{ display: 'flex', gap: 6 }}>
        <input
          readOnly
          value={value}
          onClick={(e) => (e.target as HTMLInputElement).select()}
          style={{
            flex: 1, padding: '8px 10px', background: '#f8fafc', border: '1px solid #e2e8f0',
            borderRadius: 8, fontSize: 13, fontFamily: mono ? 'monospace' : 'inherit',
          }}
        />
        <button
          type="button"
          onClick={() => {
            navigator.clipboard.writeText(value)
            setCopied(true)
            setTimeout(() => setCopied(false), 1800)
          }}
          style={{
            padding: '0 12px', background: copied ? '#22c55e' : '#1a7a8c', color: '#fff',
            border: 'none', borderRadius: 8, fontSize: 12, fontWeight: 600, cursor: 'pointer',
          }}
        >
          {copied ? '✓' : 'Salin'}
        </button>
      </div>
    </div>
  )
}
