import api from './api.js'

function resourceData(response) {
  return response.data?.data ?? response.data
}

export async function fetchSuperAdminUsers(filters = {}) {
  const { data } = await api.get('/super-admin/users', { params: filters })
  return data
}

export async function fetchSuperAdminUser(id) {
  return resourceData(await api.get(`/super-admin/users/${id}`))
}

export async function suspendSuperAdminUser(id, suspensionReason) {
  return resourceData(await api.patch(`/super-admin/users/${id}/suspend`, {
    suspension_reason: suspensionReason,
  }))
}

export async function reactivateSuperAdminUser(id) {
  return resourceData(await api.patch(`/super-admin/users/${id}/reactivate`))
}
