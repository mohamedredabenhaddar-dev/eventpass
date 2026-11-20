import axios from 'axios'
import { resolveApiBaseUrl } from './apiBaseUrl.js'

export const TOKEN_STORAGE_KEY = 'eventpass_access_token'
export const AUTH_SESSION_EXPIRED_EVENT = 'eventpass:auth-session-expired'

const api = axios.create({
  baseURL: resolveApiBaseUrl(import.meta.env.VITE_API_BASE_URL || import.meta.env.VITE_API_URL),
  headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
})

api.interceptors.request.use((config) => {
  const token = sessionStorage.getItem(TOKEN_STORAGE_KEY)
  const path = (config.url || '').split('?')[0].replace(/\/+$/, '')
  const isAuthenticationRequest = /\/(login|register)$/.test(path)

  if (token && !isAuthenticationRequest) {
    config.headers.Authorization = `Bearer ${token}`
    config.eventPassAuthToken = token
  }

  return config
})

api.interceptors.response.use(
  (response) => response,
  (error) => {
    const requestToken = error.config?.eventPassAuthToken
    if (
      error.response?.status === 401
      && requestToken
      && sessionStorage.getItem(TOKEN_STORAGE_KEY) === requestToken
    ) {
      sessionStorage.removeItem(TOKEN_STORAGE_KEY)
      if (typeof window !== 'undefined') {
        window.dispatchEvent(new Event(AUTH_SESSION_EXPIRED_EVENT))
      }
    }

    error.userMessage = error.response?.data?.message || 'We could not complete that request. Please try again.'
    return Promise.reject(error)
  },
)

export default api
