import client from './client'
import type { Reimbursement, ReimbursementListResponse } from '@/types'

export async function listReimbursements(page = 1): Promise<ReimbursementListResponse> {
  const { data } = await client.get<ReimbursementListResponse>('/reimbursements', { params: { page } })
  return data
}

export async function submitReimbursement(payload: FormData): Promise<Reimbursement> {
  const { data } = await client.post<{ data: Reimbursement }>('/reimbursements', payload, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data.data
}

export async function cancelReimbursement(id: number): Promise<void> {
  await client.delete(`/reimbursements/${id}`)
}

/** Download kwitansi PDF - fetch as blob supaya bisa preview di tab baru via blob URL (auth-safe). */
export async function downloadReceipt(id: number): Promise<Blob> {
  const res = await client.get<Blob>(`/reimbursements/${id}/receipt`, { responseType: 'blob' })
  return new Blob([res.data], { type: 'application/pdf' })
}
