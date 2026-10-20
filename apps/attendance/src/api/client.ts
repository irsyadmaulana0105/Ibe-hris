import axios, { type AxiosError, type AxiosRequestConfig, type InternalAxiosRequestConfig } from 'axios'

const TOKEN_KEY = 'absen_token'

export function getStoredToken(): string | null { return localStorage.getItem(TOKEN_KEY) }
export function setStoredToken(token: string): void { localStorage.setItem(TOKEN_KEY, token) }
export function clearStoredToken(): void { localStorage.removeItem(TOKEN_KEY) }

const BASE = (import.meta.env.VITE_API_BASE_URL as string | undefined) ?? ''

const client = axios.create({
  baseURL: `${BASE}/api/v1`,
  headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
  withCredentials: true,
})

client.interceptors.request.use((config) => {
  const token = getStoredToken()
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

// Auto-refresh on 401 - singleton promise + queue
let refreshPromise: Promise<string> | null = null
async function performRefresh(): Promise<string> {
  const { data } = await axios.post(`${BASE}/api/v1/auth/refresh`, {}, {
    withCredentials: true,
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
  })
  const t = data?.data?.token ?? data?.token
  if (!t) throw new Error('No token in refresh')
  setStoredToken(t)
  return t
}

client.interceptors.response.use(
  (r) => r,
  async (error: AxiosError) => {
    const cfg = error.config as InternalAxiosRequestConfig & { _retry?: boolean } | undefined
    const status = error.response?.status
    if (!cfg) return Promise.reject(error)

    const isRefresh = cfg.url?.includes('/auth/refresh')
    if (status === 401 && !isRefresh && !cfg._retry) {
      cfg._retry = true
      try {
        if (!refreshPromise) refreshPromise = performRefresh()
        const t = await refreshPromise
        refreshPromise = null
        cfg.headers = cfg.headers ?? {}
        cfg.headers['Authorization'] = `Bearer ${t}`
        return client(cfg as AxiosRequestConfig)
      } catch (e) {
        refreshPromise = null
        clearStoredToken()
        window.dispatchEvent(new Event('auth:force-logout'))
        return Promise.reject(e)
      }
    }

    if (status === 401) {
      clearStoredToken()
      window.dispatchEvent(new Event('auth:force-logout'))
    }
    return Promise.reject(error)
  },
)

export default client
