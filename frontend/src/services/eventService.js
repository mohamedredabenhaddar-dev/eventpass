import api from './api.js'

export async function fetchPublishedEvents(page = 1, filters = {}) {
  const { data } = await api.get('/events', { params: { page, ...filters } })
  return data
}

export async function fetchPublishedEventBySlug(slug) {
  const { data } = await api.get(`/events/${encodeURIComponent(slug)}`)
  return data.data || data
}