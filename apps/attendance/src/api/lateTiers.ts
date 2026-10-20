import client from './client'

export interface LateTier {
  id?: number
  min_minutes: number
  max_minutes: number | null
  deduction_amount: number
  label?: string | null
}

export async function listCompanyTiers(): Promise<LateTier[]> {
  const { data } = await client.get<{ data: LateTier[] }>('/admin/late-tiers')
  return data.data
}

export async function saveCompanyTiers(tiers: LateTier[]): Promise<LateTier[]> {
  const { data } = await client.post<{ data: LateTier[] }>('/admin/late-tiers/bulk', { tiers })
  return data.data
}

export async function listUserTiers(userId: string): Promise<LateTier[]> {
  const { data } = await client.get<{ data: LateTier[] }>(`/admin/users/${userId}/late-tiers`)
  return data.data
}

export async function saveUserTiers(userId: string, tiers: LateTier[]): Promise<LateTier[]> {
  const { data } = await client.post<{ data: LateTier[] }>(`/admin/users/${userId}/late-tiers/bulk`, { tiers })
  return data.data
}

export async function clearUserTiers(userId: string): Promise<void> {
  await client.delete(`/admin/users/${userId}/late-tiers`)
}
