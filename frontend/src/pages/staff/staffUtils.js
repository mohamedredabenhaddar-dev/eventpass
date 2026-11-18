export function staffErrorMessage(error) {
  const status = error.response?.status
  if (status === 401) return 'Your session has expired. Please sign in again.'
  if (status === 403) return 'You are not assigned to this event or do not have permission to view it.'
  return error.response?.data?.message || error.userMessage || 'We could not load this information. Please try again.'
}

export function eventStatusClass(status) {
  return `staff-status staff-status-${String(status || 'unknown').toLowerCase().replace(/[^a-z0-9]+/g, '-')}`
}

export function formatEventDate(value, options = {}) {
  if (!value) return 'Date to be announced'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short', ...options }).format(date)
}
