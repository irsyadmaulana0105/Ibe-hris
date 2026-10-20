import client from './client'
import type {
  AuthResponse,
  InviteAcceptPayload,
  LoginPayload,
  SsoExchangeResponse,
  SsoGenerateResponse,
  SsoPlatform,
  User,
} from '@/types'

export async function login(payload: LoginPayload): Promise<AuthResponse> {
  const { data } = await client.post<{ data: AuthResponse }>('/auth/login', payload)
  return data.data
}

export async function logout(): Promise<void> {
  await client.post('/auth/logout')
}

/**
 * Revoke every Sanctum token for the current user across all platforms.
 * Used by the "Logout dari semua platform" button so the portal session
 * is also invalidated, forcing a fresh login when the user reopens any app.
 */
export async function logoutAll(): Promise<void> {
  await client.post('/auth/logout-all')
}

export async function me(): Promise<User> {
  const { data } = await client.get<{ data: User }>('/auth/me')
  return data.data
}

export async function setupFace(selfieBase64: string | null): Promise<void> {
  await client.post('/auth/face-setup', { selfie_base64: selfieBase64 })
}

export async function acceptInvite(payload: InviteAcceptPayload): Promise<AuthResponse> {
  const { data } = await client.post<{ data: AuthResponse }>('/auth/accept-invite', payload)
  return data.data
}

export async function getSsoPlatforms(): Promise<SsoPlatform[]> {
  const { data } = await client.get<{ data: SsoPlatform[] }>('/auth/sso/platforms')
  return data.data
}

export async function generateSsoToken(targetApp: string): Promise<SsoGenerateResponse> {
  const { data } = await client.post<{ data: SsoGenerateResponse }>('/auth/sso/generate', {
    target_app: targetApp,
  })
  return data.data
}

const ssoExchanges = new Map<string, Promise<SsoExchangeResponse>>()

export function exchangeSsoToken(payload: { sso_token: string; target_app: string }): Promise<SsoExchangeResponse> {
  const key = `${payload.target_app}:${payload.sso_token}`
  const existing = ssoExchanges.get(key)
  if (existing) return existing

  const request = client
    .post<{ data: SsoExchangeResponse }>('/auth/sso/exchange', payload)
    .then(({ data }) => data.data)
  ssoExchanges.set(key, request)
  return request
}
