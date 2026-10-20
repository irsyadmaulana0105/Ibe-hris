import client from './client'
import type { PaginatedResponse } from '@/types'

export interface ClientVisit {
  id: number
  date: string
  visit_in: string | null
  visit_out: string | null
  notes_in: string | null
  notes_out: string | null
  photo_in_available: boolean
  photo_out_available: boolean
  photo_upload_enabled: boolean
  status: 'ongoing' | 'completed' | string
  duration_minutes: number | null
}

export async function listClientVisits(page = 1): Promise<PaginatedResponse<ClientVisit>> {
  const { data } = await client.get<PaginatedResponse<ClientVisit>>('/client-visits', { params: { page } })
  return data
}

export async function startClientVisit(payload: {
  date: string
  notes_in: string
}): Promise<{ id: number; status: string }> {
  const { data } = await client.post<{ data: { id: number; status: string } }>('/client-visits/in', payload)
  return data.data
}

export async function endClientVisit(id: number, payload: {
  notes_out: string
}): Promise<{ id: number; status: string }> {
  const { data } = await client.post<{ data: { id: number; status: string } }>(`/client-visits/${id}/out`, payload)
  return data.data
}
