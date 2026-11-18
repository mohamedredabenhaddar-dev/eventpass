import api from './api.js'

function resourceData(response) {
  return response.data?.data ?? response.data
}

export async function fetchOrganizerEvents(page = 1) {
  const { data } = await api.get('/organizer/events', { params: { page } })
  return data
}

export async function fetchAllOrganizerEvents() {
  const firstPage = await fetchOrganizerEvents(1)
  const lastPage = firstPage.meta?.last_page || 1
  if (lastPage <= 1) return firstPage.data || []

  const remainingPages = await Promise.all(
    Array.from({ length: lastPage - 1 }, (_, index) => fetchOrganizerEvents(index + 2)),
  )
  return [firstPage, ...remainingPages].flatMap((page) => page.data || [])
}

export async function fetchOrganizerEvent(id) {
  const response = await api.get(`/organizer/events/${id}`)
  return resourceData(response)
}

export async function createOrganizerEvent(values) {
  const response = await api.post('/organizer/events', values)
  return resourceData(response)
}

export async function updateOrganizerEvent(id, values) {
  const response = await api.patch(`/organizer/events/${id}`, values)
  return resourceData(response)
}

export async function publishOrganizerEvent(id) {
  const response = await api.patch(`/organizer/events/${id}/publish`)
  return resourceData(response)
}

export async function unpublishOrganizerEvent(id) {
  const response = await api.patch(`/organizer/events/${id}/unpublish`)
  return resourceData(response)
}

export async function cancelOrganizerEvent(id, cancellationReason) {
  const response = await api.patch(`/organizer/events/${id}/cancel`, { cancellation_reason: cancellationReason })
  return resourceData(response)
}
