import { useEffect, useState } from 'react'
import { fetchSuperAdminUsers, suspendSuperAdminUser, reactivateSuperAdminUser } from '../../services/superAdminUserService.js'
import { formatAdminUserLabel } from './adminUserUtils.js'

const PAGE_SIZES = [15, 25, 50, 100]

function AdminStaffProvisioningPage() {
  const search = ''
  const role = ''
  const accountStatus = ''
  const requestedPage = 1
  const page = requestedPage > 0 ? requestedPage : 1
  const perPage = PAGE_SIZES[0]
  const [state, setState] = useState({ key: '', payload: null, error: '' })
  const [retry, setRetry] = useState(0)
  const key = JSON.stringify([search, role, accountStatus, page, perPage, retry])

  useEffect(() => {
    let active = true
    const filters = { page, per_page: perPage }
    if (search) filters.search = search
    if (role) filters.role = role
    if (accountStatus) filters.account_status = accountStatus

    fetchSuperAdminUsers(filters)
      .then((payload) => { if (active) setState({ key, payload, error: '' }) })
      .catch(() => { setState({ key, payload: null, error: 'The user directory could not be loaded.' }) })
    return () => { active = false }
  }, [search, role, accountStatus, page, perPage, retry, key])

  const loading = state.key !== key
  const payload = loading ? null : state.payload
  const users = payload?.data ?? []

  const [provisionMode, setProvisionMode] = useState('new')
  const [provisionForm, setProvisionForm] = useState({
    name: '',
    email: '',
    password: '',
    existing_user_id: '',
    staff_user_id: '',
    event_id: '',
    assign_to_event_id: '',
  })
  const [provisionError, setProvisionError] = useState('')
  const [provisionSuccess, setProvisionSuccess] = useState(false)
  const [suspendTarget, setSuspendTarget] = useState(null)
  const [suspendReason, setSuspendReason] = useState('')
  const [showSuspendConfirm, setShowSuspendConfirm] = useState(false)
  const [reactivateTarget, setReactivateTarget] = useState(null)
  const [reactivateReason, setReactivateReason] = useState('')
  const [showReactivateConfirm, setShowReactivateConfirm] = useState(false)

  const modeOptions = [
    { value: 'new', label: 'Create new staff user' },
    { value: 'promote', label: 'Promote existing user to staff' },
    { value: 'assign', label: 'Assign staff to event' },
  ]

  const handleModeChange = (e) => {
    setProvisionMode(e.target.value)
    setProvisionForm({
      name: '',
      email: '',
      password: '',
      existing_user_id: '',
      staff_user_id: '',
      event_id: '',
      assign_to_event_id: '',
    })
    setProvisionError('')
  }

  const handleProvision = async () => {
    setProvisionError('')
    setProvisionSuccess(false)

    try {
      await fetch('/api/super-admin/staff/provision', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${localStorage.getItem('token')}`,
        },
        body: JSON.stringify({
          mode: provisionMode,
          ...provisionForm,
        }),
      })

      setProvisionSuccess(true)
      const filters = { page, per_page: perPage }
      if (search) filters.search = search
      if (role) filters.role = role
      if (accountStatus) filters.account_status = accountStatus
      fetchSuperAdminUsers(filters).then((payload) => {
        setState({ key: JSON.stringify([search, role, accountStatus, page, perPage, retry]), payload, error: '' })
      })
    } catch {
      setProvisionError('Failed to provision staff account.')
    }
  }

  const handleSuspendConfirm = async () => {
    if (!suspendTarget) return
    try {
      await suspendSuperAdminUser(suspendTarget.id, suspendReason)
      setShowSuspendConfirm(false)
      setSuspendReason('')
      setSuspendTarget(null)
      const filters = { page, per_page: perPage }
      if (search) filters.search = search
      if (role) filters.role = role
      if (accountStatus) filters.account_status = accountStatus
      fetchSuperAdminUsers(filters).then((payload) => {
        setState({ key: JSON.stringify([search, role, accountStatus, page, perPage, retry]), payload, error: '' })
      })
    } catch (error) {
      setProvisionError(error?.userMessage || 'Failed to suspend the staff account.')
    }
  }

  const handleReactivateConfirm = async () => {
    if (!reactivateTarget) return
    try {
      await reactivateSuperAdminUser(reactivateTarget.id)
      setShowReactivateConfirm(false)
      setReactivateTarget(null)
      const filters = { page, per_page: perPage }
      if (search) filters.search = search
      if (role) filters.role = role
      if (accountStatus) filters.account_status = accountStatus
      fetchSuperAdminUsers(filters).then((payload) => {
        setState({ key: JSON.stringify([search, role, accountStatus, page, perPage, retry]), payload, error: '' })
      })
    } catch (error) {
      setProvisionError(error?.userMessage || 'Failed to reactivate the staff account.')
    }
  }

  return (
    <section className="admin-staff-provisioning-page">
      <header className="admin-users-heading">
        <div><span className="eyebrow">STAFF PROVISIONING</span><h1>Provision Staff Accounts</h1><p>Create new staff users or promote existing users and assign them to events.</p></div>
      </header>

      <div className="provision-form-card">
        <form onSubmit={(e) => { e.preventDefault(); handleProvision(); }}>

          <label className="provision-mode-select">
            <span>Mode</span>
            <select
              value={provisionMode}
              onChange={handleModeChange}
            >
              {modeOptions.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          </label>

          {provisionMode === 'new' && (
            <div className="provision-field-group">
              <label>Name <span>Required</span></label>
              <input
                type="text"
                name="name"
                value={provisionForm.name}
                onChange={(e) =>
                  setProvisionForm({ ...provisionForm, name: e.target.value })}
                required
              />
              <label>Email <span>Required</span></label>
              <input
                type="email"
                name="email"
                value={provisionForm.email}
                onChange={(e) =>
                  setProvisionForm({ ...provisionForm, email: e.target.value })}
                required
              />
              <label>Password <span>Required</span></label>
              <input
                type="password"
                name="password"
                value={provisionForm.password}
                onChange={(e) =>
                  setProvisionForm({ ...provisionForm, password: e.target.value })}
                required
                minLength={8}
              />
              <label>
                <input
                  type="checkbox"
                  checked={provisionForm.assign_to_event_id}
                  onChange={(e) => setProvisionForm({ ...provisionForm, assign_to_event_id: e.target.value })}
                />
                Assign to event (optional)
              </label>
              <input
                type="hidden"
                name="assign_to_event_id"
                value={provisionForm.assign_to_event_id}
              />
            </div>
          )}

          {provisionMode === 'promote' && (
            <div className="provision-field-group">
              <label>Existing user ID <span>Required</span></label>
              <input
                type="number"
                name="existing_user_id"
                value={provisionForm.existing_user_id}
                onChange={(e) =>
                  setProvisionForm({ ...provisionForm, existing_user_id: e.target.value })}
                required
              />
              <label>Assign to event (optional)</label>
              <input
                type="hidden"
                name="assign_to_event_id"
                value={provisionForm.assign_to_event_id}
              />
            </div>
          )}

          {provisionMode === 'assign' && (
            <div className="provision-field-group">
              <label>Staff user ID <span>Required</span></label>
              <input
                type="number"
                name="staff_user_id"
                value={provisionForm.staff_user_id}
                onChange={(e) =>
                  setProvisionForm({ ...provisionForm, staff_user_id: e.target.value })}
                required
              />
              <label>Event ID <span>Required</span></label>
              <input
                type="number"
                name="event_id"
                value={provisionForm.event_id}
                onChange={(e) =>
                  setProvisionForm({ ...provisionForm, event_id: e.target.value })}
                required
              />
            </div>
          )}

          {provisionError && (
            <div className="provision-error" role="alert">
              <p>{provisionError}</p>
            </div>
          )}

          {provisionSuccess && (
            <div className="provision-success" role="status">
              Provisioned successfully
            </div>
          )}

          <button type="submit" className="button button-primary provision-submit" disabled={provisionMode === ''}>
            {provisionSuccess ? 'Provisioned' : 'Provision Staff'}
          </button>
        </form>
      </div>

      <section className="admin-users-section">
        <h2>Current Users</h2>
        {users.length === 0 && <p>No users match these filters.</p>}

        {loading && <div><span className="spinner-border spinner-border-sm" /> Loading…</div>}
        {state.error && <div className="error"><p>{state.error}</p><button onClick={() => setRetry((r) => r + 1)}>Retry</button></div>}

        {loading && users.length === 0 && <div>No users found.</div>}

        {users.length > 0 && (
          <div className="admin-users-table-wrap">
            <table>
              <thead>
                <tr>
                  <th>User</th>
                  <th>Role</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {users.map((user) => (
                  <tr key={user.id}>
                    <td>
                      <strong>{user.name}</strong>{' '}<small>{user.email}</small>
                    </td>
                    <td>
                      <span className={`admin-user-role is-${user.role}`}>
                        {formatAdminUserLabel(user.role)}
                      </span>
                    </td>
                    <td>
                      <span className={`admin-user-status is-${user.account_status}`}>
                        {formatAdminUserLabel(user.account_status)}
                      </span>
                    </td>
                    <td>
                      {user.account_status === 'active' && user.role !== 'super_admin' ? (
                        <button onClick={() => setSuspendTarget(user)}>Suspend</button>
                      ) : null}
                      {user.account_status === 'suspended' ? (
                        <button onClick={() => setReactivateTarget(user)}>Reactivate</button>
                      ) : null}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {!loading && payload?.meta?.last_page > 1 && (
          <nav aria-label="User directory pages">
            <span>
              Page {payload.meta.current_page || page} of {payload.meta.last_page}
              · {payload.meta.total} users
            </span>
          </nav>
        )}
      </section>

      {showSuspendConfirm && (
        <div className="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
          <div className="bg-white rounded-lg p-8 max-w-md w-full shadow-lg">
            <h3 className="text-lg font-bold mb-4">Suspend User</h3>
            <p className="text-muted mb-6">
              Are you sure you want to suspend {suspendTarget?.name}?
            </p>
            <div className="mb-4">
              <label className="block text-sm mb-2">Suspension reason <span className="text-red-500">*</span></label>
              <input
                type="text"
                value={suspendReason}
                onChange={(e) => setSuspendReason(e.target.value)}
                required
                className="w-full border rounded p-2"
              />
            </div>
            <div className="flex justify-end gap-3">
              <button className="button button-outline" onClick={() => setShowSuspendConfirm(false)}>
                Cancel
              </button>
              <button className="button button-primary" onClick={() => handleSuspendConfirm()}>
                Suspend
              </button>
            </div>
          </div>
        </div>
      )}

      {showReactivateConfirm && (
        <div className="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
          <div className="bg-white rounded-lg p-8 max-w-md w-full shadow-lg">
            <h3 className="text-lg font-bold mb-4">Reactivate User</h3>
            <p className="text-muted mb-6">
              Are you sure you want to reactivate {reactivateTarget?.name}?
            </p>
            <div className="mb-4">
              <label className="block text-sm mb-2">Reactivation reason <span className="text-red-500">*</span></label>
              <input
                type="text"
                value={reactivateReason || ''}
                onChange={(e) => setReactivateReason(e.target.value)}
                required
                className="w-full border rounded p-2"
              />
            </div>
            <div className="flex justify-end gap-3">
              <button className="button button-outline" onClick={() => setShowReactivateConfirm(false)}>
                Cancel
              </button>
              <button className="button button-primary" onClick={() => handleReactivateConfirm()}>
                Reactivate
              </button>
            </div>
          </div>
        </div>
      )}
    </section>
  )
}
export default AdminStaffProvisioningPage