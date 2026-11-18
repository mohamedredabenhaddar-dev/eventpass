export function monitoringError(error, fallback = 'The monitoring data could not be loaded.') {
  const status = error.response?.status
  if (status === 401) return 'Your session has expired. Please sign in again.'
  if (status === 403) return 'Your account is not authorized to view this monitoring data.'
  if (status === 404) return 'The selected record could not be found.'
  if (status === 409) return error.response?.data?.message || 'The requested data is no longer available in this state.'
  if (status === 422) return error.response?.data?.message || 'The request was not valid.'
  if (!error.response) return 'The service could not be reached. Check your connection and try again.'
  return error.response?.data?.message || error.userMessage || fallback
}

export function monitoringDate(value) {
  if (!value) return '—'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString()
}
