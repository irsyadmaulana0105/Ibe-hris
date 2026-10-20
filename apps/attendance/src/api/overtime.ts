import client from './client'
import type { Overtime, PaginatedResponse } from '@/types'

export async function listOvertimes(page = 1): Promise<PaginatedResponse<Overtime>> {
  const { data } = await client.get<PaginatedResponse<Overtime>>('/overtimes', { params: { page } })
  return data
}

export async function submitOvertime(payload: {
  date: string
  start_time: string
  end_time: string
  reason: string
}): Promise<Overtime> {
  const { data } = await client.post<{ data: Overtime }>('/overtimes', payload)
  return data.data
}

export async function cancelOvertime(id: number): Promise<void> {
  await client.delete(`/overtimes/${id}`)
}
