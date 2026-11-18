import api from './api.js'

function resourceData(response) {
  return response.data?.data ?? response.data
}

export async function fetchSuperAdminEvents(page = 1) {
  const { data } = await api.get('/super-admin/events', { params: { page } })
  return data
}

export async function fetchAllSuperAdminEvents() {
  const firstPage = await fetchSuperAdminEvents(1)
  const lastPage = firstPage.meta?.last_page || 1
  if (lastPage <= 1) return firstPage.data || []
  const remainingPages = await Promise.all(
    Array.from({ length: lastPage - 1 }, (_, index) => fetchSuperAdminEvents(index + 2)),
  )
  return [firstPage, ...remainingPages].flatMap((page) => page.data || [])
}

export async function fetchSuperAdminEvent(id) {
  const response = await api.get(`/super-admin/events/${id}`)
  return resourceData(response)
}

export async function suspendSuperAdminEvent(id) {
  const response = await api.patch(`/super-admin/events/${id}/suspend`)
  return resourceData(response)
}

export async function reactivateSuperAdminEvent(id) {
  const response = await api.patch(`/super-admin/events/${id}/reactivate`)
  return resourceData(response)
}

export async function cancelSuperAdminEvent(id, cancellationReason) {
  const response = await api.patch(`/super-admin/events/${id}/cancel`, { cancellation_reason: cancellationReason })
  return resourceData(response)
}
