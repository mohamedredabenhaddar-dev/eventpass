export function adminEventError(error, fallback = 'The event request could not be completed.') {
  const status = error.response?.status
  if (status === 401) return 'Your session has expired. Please sign in again.'
  if (status === 403) return 'Your account is not allowed to administer this event.'
  if (status === 404) return 'This event could not be found.'
  if (status === 409) return error.response?.data?.message || 'The event status has changed and this action is no longer available.'
  if (status === 422) {
    const errors = error.response?.data?.errors
    return errors ? Object.values(errors).flat().join(' ') : error.response?.data?.message || 'The event request did not pass validation.'
  }
  if (!error.response) return 'The service could not be reached. Check your connection and try again.'
  return error.response?.data?.message || error.userMessage || fallback
}

export function formatEventAdminDate(value) {
  if (!value) return '—'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString()
}
