import type { AxiosError } from 'axios'

export interface ApiValidationErrors {
  [field: string]: string[]
}

export interface ApiErrorBody {
  message?: string
  errors?: ApiValidationErrors
}

export type ApiError = AxiosError<ApiErrorBody>

/** Extract a user-friendly message from any thrown error. */
export function getErrorMessage(err: unknown, fallback = 'Terjadi kesalahan.'): string {
  const e = err as ApiError
  return e?.response?.data?.message ?? fallback
}

/** Extract per-field validation errors from a 422 response. */
export function getValidationErrors(err: unknown): ApiValidationErrors {
  const e = err as ApiError
  if (e?.response?.status === 422) {
    return e.response.data?.errors ?? {}
  }
  return {}
}
