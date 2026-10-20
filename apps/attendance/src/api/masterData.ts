import client from './client'
import type { Division, Education, InventoryItem, InventoryReferences, JobTitle, PaginatedResponse, Shift } from '@/types'

export async function adminListDivisions(params?: Record<string, string>): Promise<PaginatedResponse<Division>> {
  const { data } = await client.get('/admin/master-data/divisions', { params })
  return data
}

export async function adminCreateDivision(payload: { name: string }): Promise<Division> {
  const { data } = await client.post<{ data: Division }>('/admin/master-data/divisions', payload)
  return data.data
}

export async function adminUpdateDivision(id: number, payload: { name: string }): Promise<Division> {
  const { data } = await client.put<{ data: Division }>(`/admin/master-data/divisions/${id}`, payload)
  return data.data
}

export async function adminDeleteDivision(id: number): Promise<void> {
  await client.delete(`/admin/master-data/divisions/${id}`)
}

export async function adminListJobTitles(params?: Record<string, string>): Promise<PaginatedResponse<JobTitle> & { divisions: Pick<Division, 'id' | 'name'>[] }> {
  const { data } = await client.get('/admin/master-data/job-titles', { params })
  return data
}

export async function adminCreateJobTitle(payload: { name: string; division_id: number }): Promise<JobTitle> {
  const { data } = await client.post<{ data: JobTitle }>('/admin/master-data/job-titles', payload)
  return data.data
}

export async function adminUpdateJobTitle(id: number, payload: { name: string; division_id: number }): Promise<JobTitle> {
  const { data } = await client.put<{ data: JobTitle }>(`/admin/master-data/job-titles/${id}`, payload)
  return data.data
}

export async function adminDeleteJobTitle(id: number): Promise<void> {
  await client.delete(`/admin/master-data/job-titles/${id}`)
}

export async function adminListEducations(params?: Record<string, string>): Promise<PaginatedResponse<Education>> {
  const { data } = await client.get('/admin/master-data/educations', { params })
  return data
}

export async function adminCreateEducation(payload: { name: string }): Promise<Education> {
  const { data } = await client.post<{ data: Education }>('/admin/master-data/educations', payload)
  return data.data
}

export async function adminUpdateEducation(id: number, payload: { name: string }): Promise<Education> {
  const { data } = await client.put<{ data: Education }>(`/admin/master-data/educations/${id}`, payload)
  return data.data
}

export async function adminDeleteEducation(id: number): Promise<void> {
  await client.delete(`/admin/master-data/educations/${id}`)
}

export async function adminListShifts(params?: Record<string, string>): Promise<PaginatedResponse<Shift>> {
  const { data } = await client.get('/admin/master-data/shifts', { params })
  return data
}

export async function adminCreateShift(payload: { name: string; start_time: string; end_time: string | null }): Promise<Shift> {
  const { data } = await client.post<{ data: Shift }>('/admin/master-data/shifts', payload)
  return data.data
}

export async function adminUpdateShift(id: number, payload: { name: string; start_time: string; end_time: string | null }): Promise<Shift> {
  const { data } = await client.put<{ data: Shift }>(`/admin/master-data/shifts/${id}`, payload)
  return data.data
}

export async function adminDeleteShift(id: number): Promise<void> {
  await client.delete(`/admin/master-data/shifts/${id}`)
}

export async function adminListInventories(params?: Record<string, string>): Promise<PaginatedResponse<InventoryItem> & { references: InventoryReferences }> {
  const { data } = await client.get('/admin/master-data/inventories', { params })
  return data
}

export async function adminCreateInventory(payload: {
  item_code?: string | null
  item_name: string
  category_id?: number | null
  stock?: number
  unit?: string | null
  description?: string | null
  office_location_id?: number | null
  job_title_id?: number | null
}): Promise<InventoryItem> {
  const { data } = await client.post<{ data: InventoryItem }>('/admin/master-data/inventories', payload)
  return data.data
}

export async function adminUpdateInventory(id: number, payload: {
  item_code?: string | null
  item_name: string
  category_id?: number | null
  stock?: number
  unit?: string | null
  description?: string | null
  office_location_id?: number | null
  job_title_id?: number | null
}): Promise<InventoryItem> {
  const { data } = await client.put<{ data: InventoryItem }>(`/admin/master-data/inventories/${id}`, payload)
  return data.data
}
