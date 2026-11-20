import api from './api.js'

// Read-only Super Admin audit history. The backend rejects unsupported query
// parameters, so callers must only pass the documented filter names.
export async function fetchSuperAdminAuditEvents(params = {}) {
  const { data } = await api.get('/super-admin/audit-events', { params })
  return data
}
