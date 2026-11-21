import api from './api.js'

function resourceData(response) {
  return response.data?.data ?? response.data
}

export async function fetchPublicTicketTypes(eventId) {
  const { data } = await api.get(`/events/${eventId}/ticket-types`)
  return data.data || []
}

export async function createClientReservation(eventId, items) {
  const response = await api.post(`/events/${eventId}/reservations`, { items })
  return resourceData(response)
}

export async function fetchMyReservations(page = 1) {
  const { data } = await api.get('/reservations', { params: { page } })
  return data
}

export async function fetchReservation(id) {
  const response = await api.get(`/reservations/${id}`)
  return resourceData(response)
}

export async function cancelReservation(id) {
  const response = await api.patch(`/reservations/${id}/cancel`)
  return resourceData(response)
}

export async function fetchTicketQrCredential(ticketId) {
  const response = await api.post(`/tickets/${ticketId}/qr-credential`)
  return resourceData(response)
}

export async function createPaymentAttempt(reservationId) {
  return resourceData(await api.post(`/reservations/${reservationId}/payments`))
}

export async function simulatePaymentSuccess(paymentId) {
  return resourceData(await api.post(`/payments/${paymentId}/simulate-success`))
}

export async function simulatePaymentFailure(paymentId) {
  return resourceData(await api.post(`/payments/${paymentId}/simulate-failure`))
}

export async function fetchPayment(paymentId) {
  return resourceData(await api.get(`/payments/${paymentId}`))
}

export async function fetchMyPayments(page = 1) {
  const { data } = await api.get('/payments', { params: { page } })
  return data
}

export async function fetchOrganizerTicketTypes(eventId, page = 1) {
  const { data } = await api.get(`/organizer/events/${eventId}/ticket-types`, { params: { page } })
  return data
}

export async function fetchOrganizerTicketType(eventId, ticketTypeId) {
  const response = await api.get(`/organizer/events/${eventId}/ticket-types/${ticketTypeId}`)
  return resourceData(response)
}

export async function createOrganizerTicketType(eventId, values) {
  const response = await api.post(`/organizer/events/${eventId}/ticket-types`, values)
  return resourceData(response)
}

export async function updateOrganizerTicketType(eventId, ticketTypeId, values) {
  const response = await api.patch(`/organizer/events/${eventId}/ticket-types/${ticketTypeId}`, values)
  return resourceData(response)
}

export async function deleteOrganizerTicketType(eventId, ticketTypeId) {
  const { data } = await api.delete(`/organizer/events/${eventId}/ticket-types/${ticketTypeId}`)
  return data
}

export async function fetchOrganizerReservations(eventId, page = 1) {
  const { data } = await api.get(`/organizer/events/${eventId}/reservations`, { params: { page } })
  return data
}

export async function fetchOrganizerReservation(eventId, reservationId) {
  const response = await api.get(`/organizer/events/${eventId}/reservations/${reservationId}`)
  return resourceData(response)
}

export async function fetchAllOrganizerReservations(eventId) {
  const firstPage = await fetchOrganizerReservations(eventId, 1)
  const lastPage = firstPage.meta?.last_page || 1
  if (lastPage <= 1) return firstPage.data || []

  const nextPages = await Promise.all(
    Array.from({ length: lastPage - 1 }, (_, index) => fetchOrganizerReservations(eventId, index + 2)),
  )
  return [firstPage, ...nextPages].flatMap((page) => page.data || [])
}

export async function fetchSuperAdminTicketTypes(page = 1) {
  const { data } = await api.get('/super-admin/ticket-types', { params: { page } })
  return data
}

export async function fetchSuperAdminTicketType(id) {
  const response = await api.get(`/super-admin/ticket-types/${id}`)
  return resourceData(response)
}

export async function fetchSuperAdminReservations(page = 1) {
  const { data } = await api.get('/super-admin/reservations', { params: { page } })
  return data
}

export async function fetchSuperAdminReservation(id) {
  const response = await api.get(`/super-admin/reservations/${id}`)
  return resourceData(response)
}

export async function fetchOrganizerEventStaff(eventId, page = 1) {
  const { data } = await api.get(`/organizer/events/${eventId}/staff`, { params: { page } })
  return data
}

export async function fetchAvailableOrganizerEventStaff(eventId, search = '') {
  const trimmed = String(search || '').trim()
  const { data } = await api.get(`/organizer/events/${eventId}/staff/available`, {
    params: trimmed ? { search: trimmed } : undefined,
  })
  return data.data || []
}

export async function assignOrganizerEventStaff(eventId, staffUserId) {
  const response = await api.post(`/organizer/events/${eventId}/staff`, { staff_user_id: staffUserId })
  return resourceData(response)
}

export async function removeOrganizerEventStaff(eventId, assignmentId) {
  const { data } = await api.delete(`/organizer/events/${eventId}/staff/${assignmentId}`)
  return data
}

export async function fetchOrganizerAccessReport(eventId, page = 1) {
  const { data } = await api.get(`/organizer/events/${eventId}/access`, { params: { page } })
  return data
}

export async function fetchStaffAssignedEvents(page = 1) {
  const { data } = await api.get('/staff/events', { params: { page } })
  return data
}

export async function fetchAllStaffAssignedEvents() {
  const firstPage = await fetchStaffAssignedEvents(1)
  const lastPage = firstPage.meta?.last_page || 1
  if (lastPage <= 1) return firstPage.data || []

  const nextPages = await Promise.all(
    Array.from({ length: lastPage - 1 }, (_, index) => fetchStaffAssignedEvents(index + 2)),
  )
  return [firstPage, ...nextPages].flatMap((page) => page.data || [])
}

export async function scanStaffTicket({ qr_token, action, gate }) {
  const payload = { qr_token, action }
  if (gate?.trim()) payload.gate = gate.trim()
  const response = await api.post('/staff/scans', payload)
  return resourceData(response)
}

export async function fetchStaffAccessReport(eventId, page = 1) {
  const { data } = await api.get(`/staff/events/${eventId}/access`, { params: { page } })
  return data
}
