import client from './client'
import type { AdminPayroll, PaginatedResponse } from '@/types'

export async function listMyPayrolls(params?: Record<string, string>): Promise<PaginatedResponse<AdminPayroll>> {
  const { data } = await client.get('/payrolls', { params })
  return data
}

export async function getMyPayroll(id: number): Promise<AdminPayroll> {
  const { data } = await client.get<{ data: AdminPayroll }>(`/payrolls/${id}`)
  return data.data
}

export async function downloadMyPayrollSlip(id: number): Promise<Blob> {
  const response = await client.get(`/payrolls/${id}/slip`, {
    responseType: 'blob',
    headers: {
      Accept: 'application/pdf',
    },
  })

  return response.data
}
