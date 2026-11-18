import api from './api.js'

export async function fetchSuperAdminEventAccess(eventId, page = 1) {
  const { data } = await api.get(`/super-admin/events/${eventId}/access`, { params: { page } })
  return data.data ?? data
}

export async function fetchSuperAdminEventStaff(eventId, page = 1) {
  const { data } = await api.get(`/super-admin/events/${eventId}/staff`, { params: { page } })
  return data
}
