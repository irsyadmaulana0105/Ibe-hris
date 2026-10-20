import client from './client'
import type { PaginatedResponse } from '@/types'

export interface BusinessTrip {
  id: number
  date: string
  shift_id: number | null
  destination: string
  purpose: string
  status: 'pending' | 'approved' | 'rejected'
  notes: string | null
  created_at: string | null
}

export interface ShiftOption {
  id: number
  name: string
  start_time: string | null
  end_time: string | null
}

export async function listBusinessTrips(page = 1): Promise<PaginatedResponse<BusinessTrip>> {
  const { data } = await client.get<PaginatedResponse<BusinessTrip>>('/business-trips', { params: { page } })
  return data
}

export async function listBusinessTripShifts(): Promise<ShiftOption[]> {
  const { data } = await client.get<{ data: ShiftOption[] }>('/business-trips/shifts')
  return data.data
}

export async function submitBusinessTrip(payload: {
  date: string
  shift_id: number
  destination: string
  purpose: string
}): Promise<BusinessTrip> {
  const { data } = await client.post<{ data: BusinessTrip }>('/business-trips', payload)
  return data.data
}

export async function cancelBusinessTrip(id: number): Promise<void> {
  await client.delete(`/business-trips/${id}`)
}
