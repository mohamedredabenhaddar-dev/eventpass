export function adminUserError(error, fallback = 'The user request could not be completed.') {
  const status = error.response?.status
  if (status === 401) return 'Your session has expired. Please sign in again.'
  if (status === 403) return 'Your account is not allowed to manage users.'
  if (status === 404) return 'This user could not be found.'
  if (status === 409) return error.response?.data?.message || 'The account status changed and this action is no longer available.'
  if (status === 422) {
    const errors = error.response?.data?.errors
    return errors ? Object.values(errors).flat().join(' ') : error.response?.data?.message || 'The user request did not pass validation.'
  }
  if (!error.response) return 'The service could not be reached. Check your connection and try again.'
  return error.response?.data?.message || error.userMessage || fallback
}

export function adminUserValidationError(error, field) {
  const messages = error.response?.data?.errors?.[field]
  return Array.isArray(messages) ? messages.join(' ') : ''
}

export function formatAdminUserDate(value) {
  if (!value) return '—'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString()
}

export function formatAdminUserLabel(value) {
  if (!value) return 'Unknown'
  return value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase())
}
