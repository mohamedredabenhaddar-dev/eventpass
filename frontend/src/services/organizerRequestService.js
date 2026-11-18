import api from './api.js'

export async function fetchMyOrganizerRequests(page = 1) {
  const { data } = await api.get('/organizer-requests/my', { params: { page } })
  return data
}

export async function submitOrganizerRequest(application) {
  const { data } = await api.post('/organizer-requests', application)
  return data.data ?? data
}

export async function fetchSuperAdminOrganizerRequests(page = 1) {
  const { data } = await api.get('/super-admin/organizer-requests', { params: { page } })
  return data
}

export async function fetchSuperAdminOrganizerRequest(id) {
  const { data } = await api.get(`/super-admin/organizer-requests/${id}`)
  return data.data ?? data
}

export async function approveSuperAdminOrganizerRequest(id) {
  const { data } = await api.patch(`/super-admin/organizer-requests/${id}/approve`)
  return data.data ?? data
}

export async function rejectSuperAdminOrganizerRequest(id, rejectionReason) {
  const { data } = await api.patch(`/super-admin/organizer-requests/${id}/reject`, { rejection_reason: rejectionReason })
  return data.data ?? data
}
