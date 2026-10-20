import client from './client'
import type { PaginatedResponse, SalaryAdvance } from '@/types'

export async function listSalaryAdvances(page = 1): Promise<PaginatedResponse<SalaryAdvance>> {
  const { data } = await client.get<PaginatedResponse<SalaryAdvance>>('/salary-advances', { params: { page } })
  return data
}

export async function submitSalaryAdvance(payload: {
  date: string
  amount: number
  purpose: string
}): Promise<SalaryAdvance> {
  const { data } = await client.post<{ data: SalaryAdvance }>('/salary-advances', payload)
  return data.data
}

export async function cancelSalaryAdvance(id: number): Promise<void> {
  await client.delete(`/salary-advances/${id}`)
}
