import { Link, Navigate } from 'react-router-dom'
import { useAuth } from '@/context/AuthContext'
import { isSsoRequired, redirectToSsoPortal } from '@/lib/sso'

function hasCapability(user: NonNullable<ReturnType<typeof useAuth>['user']>, capability?: string) {
  if (!capability) return user.permissions.is_admin
  return user.permissions.capabilities.includes(capability)
}

export function ProtectedRoute({ children }: { children: React.ReactNode }) {
  const { user, isLoading } = useAuth()

  if (isLoading) {
    return (
      <div className="flex h-screen items-center justify-center">
        <div className="h-8 w-8 animate-spin rounded-full border-4 border-blue-600 border-t-transparent" />
      </div>
    )
  }

  if (!user && isSsoRequired()) {
    redirectToSsoPortal('/launch/attendance')
    return null
  }

  if (!user) return <Navigate to="/login" replace />

  return <>{children}</>
}

export function AdminRoute({
  children,
  capability,
}: {
  children: React.ReactNode
  capability?: string
}) {
  const { user, isLoading } = useAuth()

  if (isLoading) {
    return (
      <div className="flex h-screen items-center justify-center">
        <div className="h-8 w-8 animate-spin rounded-full border-4 border-blue-600 border-t-transparent" />
      </div>
    )
  }

  if (!user && isSsoRequired()) {
    redirectToSsoPortal('/launch/attendance')
    return null
  }

  if (!user) return <Navigate to="/login" replace />

  // Explicit forbidden screen so users understand WHY they were blocked
  // instead of silently bouncing back to the dashboard.
  if (!hasCapability(user, capability)) {
    return <AccessDenied capability={capability} userGroup={user.group} />
  }

  return <>{children}</>
}

function AccessDenied({ capability, userGroup }: { capability?: string; userGroup?: string }) {
  return (
    <div style={{
      minHeight: '60vh',
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center',
      padding: 24,
    }}>
      <div style={{
        maxWidth: 480,
        background: '#fff',
        border: '1px solid #e5e9ef',
        borderRadius: 14,
        padding: 32,
        textAlign: 'center',
        boxShadow: '0 4px 16px rgba(15,23,42,.06)',
      }}>
        <div style={{
          width: 64,
          height: 64,
          margin: '0 auto 16px',
          borderRadius: 16,
          background: 'rgba(239,68,68,.1)',
          color: '#ef4444',
          display: 'grid',
          placeItems: 'center',
          fontSize: 32,
        }}>
          🔒
        </div>
        <h2 style={{ margin: 0, fontSize: 22, fontWeight: 800, color: '#0f172a' }}>
          Akses Terbatas
        </h2>
        <p style={{ marginTop: 12, fontSize: 14, color: '#64748b', lineHeight: 1.6 }}>
          Halaman ini memerlukan capability <code style={{ background: '#f1f5f9', padding: '2px 6px', borderRadius: 4 }}>{capability ?? 'admin.access'}</code>.
          {' '}Akun Anda saat ini di-grup <strong>{userGroup ?? 'unknown'}</strong>, yang belum memilikinya.
        </p>
        <p style={{ marginTop: 8, fontSize: 12, color: '#94a3b8' }}>
          Minta Super Admin untuk menambahkan capability ini via menu <strong>Settings → Hak Akses (RBAC)</strong>.
        </p>
        <div style={{ marginTop: 20, display: 'flex', gap: 8, justifyContent: 'center' }}>
          <Link to="/dashboard" style={{
            padding: '10px 18px',
            background: '#1a7a8c',
            color: '#fff',
            borderRadius: 10,
            textDecoration: 'none',
            fontWeight: 600,
            fontSize: 13,
          }}>
            ← Kembali ke Dashboard
          </Link>
        </div>
      </div>
    </div>
  )
}
