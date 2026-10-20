import client from './client'
import type { Leave, LeaveListResponse } from '@/types'

export async function listLeaves(page = 1): Promise<LeaveListResponse> {
  const { data } = await client.get<LeaveListResponse>('/leaves', { params: { page } })
  return data
}

export async function submitLeave(payload: {
  leave_type: string
  start_date: string
  end_date: string
  reason: string
}): Promise<Leave> {
  const { data } = await client.post<{ data: Leave }>('/leaves', payload)
  return data.data
}

export async function cancelLeave(id: number): Promise<void> {
  await client.delete(`/leaves/${id}`)
}
