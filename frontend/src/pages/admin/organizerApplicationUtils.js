export function adminRequestError(error, fallback = 'The request could not be completed.') {
  const status = error.response?.status
  if (status === 401) return 'Your session has expired. Please sign in again.'
  if (status === 403) return 'Your account is not allowed to access this information.'
  if (status === 404) return 'This organizer application could not be found.'
  if (status === 409) return error.response?.data?.message || 'This application has already been reviewed or can no longer be changed.'
  if (status === 422) {
    const errors = error.response?.data?.errors
    return errors ? Object.values(errors).flat().join(' ') : error.response?.data?.message || 'Some application information needs attention.'
  }
  if (!error.response) return 'The service could not be reached. Check your connection and try again.'
  return error.response?.data?.message || error.userMessage || fallback
}

export function formatAdminDate(value) {
  if (!value) return '—'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString()
}
