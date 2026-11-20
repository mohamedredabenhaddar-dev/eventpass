export const DEFAULT_API_BASE_URL = 'http://127.0.0.1:8000/api'

/**
 * Normalize the configured API base URL so every request lands on the `/api`
 * route group. Services call paths such as `/events` or `/login` relative to
 * the axios baseURL, so a bare origin (e.g. `http://127.0.0.1:8000`) silently
 * produces 404s and failed CORS preflights that surface to users as
 * "We could not complete that request. Please try again."
 */
export function resolveApiBaseUrl(configured = '') {
  const candidate = String(configured ?? '').trim() || DEFAULT_API_BASE_URL
  const trimmed = candidate.replace(/\/+$/, '')

  if (/\/api$/i.test(trimmed)) {
    return trimmed
  }

  // Only a bare origin (scheme + host + optional port) gets the `/api` suffix.
  if (/^[a-z][a-z0-9+.-]*:\/\/[^/]+$/i.test(trimmed)) {
    return `${trimmed}/api`
  }

  return trimmed
}
