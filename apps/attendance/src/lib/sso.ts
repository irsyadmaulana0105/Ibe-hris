const requireSso = import.meta.env.VITE_REQUIRE_SSO === 'true'
const ssoPortalUrl = (import.meta.env.VITE_SSO_PORTAL_URL ?? '').trim()

/** Identifier the portal recognises for auto-launch back to this app. */
export const SSO_TARGET_APP = 'attendance' as const

export function isSsoRequired(): boolean {
  return requireSso && ssoPortalUrl.length > 0
}

export function getSsoPortalUrl(): string {
  return ssoPortalUrl
}

/**
 * Redirect to the SSO portal, declaring this app as the target so the portal
 * can skip the picker and auto-launch back here after authentication.
 */
export function redirectToSsoPortal(nextPath?: string): void {
  if (!isSsoRequired()) {
    return
  }

  const target = new URL(ssoPortalUrl)
  target.searchParams.set('target_app', SSO_TARGET_APP)
  if (nextPath) {
    target.searchParams.set('next', nextPath)
  }

  window.location.assign(target.toString())
}

export function resolvePortalNextPath(nextPath: string | null | undefined): string | null {
  if (!nextPath) return null
  if (!nextPath.startsWith('/')) return null
  if (nextPath.startsWith('//')) return null
  return nextPath
}

/**
 * Hit the portal logout endpoint to revoke the portal session token, then
 * clear local app state. Failures are swallowed so the user is always logged
 * out locally even if the portal is unreachable.
 */
export async function logoutFromAllPlatforms(localToken?: string | null): Promise<void> {
  const portalBaseUrl = ssoPortalUrl.replace(/\/$/, '')
  if (portalBaseUrl && localToken) {
    try {
      // Portal & ERP share the same Sanctum issuer (app-erp.ibereality.com backend),
      // so the local token is valid for revoking portal-issued sessions too.
      await fetch(`${portalBaseUrl}/api/v1/auth/logout-all`, {
        method: 'POST',
        headers: {
          Authorization: `Bearer ${localToken}`,
          Accept: 'application/json',
        },
        keepalive: true,
      })
    } catch {
      // swallow - local logout still proceeds
    }
  }
}
