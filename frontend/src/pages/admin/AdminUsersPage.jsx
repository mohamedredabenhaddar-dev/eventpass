import { useEffect, useState } from 'react'
import { ChevronLeft, ChevronRight, Search, UserRound } from 'lucide-react'
import { Link, useSearchParams } from 'react-router-dom'
import { fetchSuperAdminUsers } from '../../services/superAdminUserService.js'
import { adminUserError, formatAdminUserDate, formatAdminUserLabel } from './adminUserUtils.js'

const ROLES = ['super_admin', 'organizer', 'staff', 'client']
const PAGE_SIZES = [15, 25, 50, 100]

function AdminUsersPage() {
  const [params, setParams] = useSearchParams()
  const search = params.get('search')?.trim() || ''
  const role = params.get('role') || ''
  const accountStatus = params.get('account_status') || ''
  const requestedPage = Number(params.get('page'))
  const page = Number.isInteger(requestedPage) && requestedPage > 0 ? requestedPage : 1
  const requestedPageSize = Number(params.get('per_page'))
  const perPage = PAGE_SIZES.includes(requestedPageSize) ? requestedPageSize : 15
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
      .catch((error) => { if (active) setState({ key, payload: null, error: adminUserError(error, 'The user directory could not be loaded.') }) })
    return () => { active = false }
  }, [search, role, accountStatus, page, perPage, retry, key])

  const loading = state.key !== key
  const payload = loading ? null : state.payload
  const users = payload?.data ?? []

  const applyFilters = (event) => {
    event.preventDefault()
    const values = new FormData(event.currentTarget)
    const next = new URLSearchParams()
    const nextSearch = String(values.get('search') || '').trim()
    const nextRole = String(values.get('role') || '')
    const nextStatus = String(values.get('account_status') || '')
    const nextPageSize = Number(values.get('per_page'))
    if (nextSearch) next.set('search', nextSearch)
    if (nextRole) next.set('role', nextRole)
    if (nextStatus) next.set('account_status', nextStatus)
    if (PAGE_SIZES.includes(nextPageSize) && nextPageSize !== 15) next.set('per_page', String(nextPageSize))
    setParams(next)
  }

  const goToPage = (nextPage) => {
    const next = new URLSearchParams(params)
    if (nextPage > 1) next.set('page', String(nextPage))
    else next.delete('page')
    setParams(next)
  }

  return <section className="admin-users-page">
    <header className="admin-users-heading">
      <div><span className="eyebrow">ACCOUNT ADMINISTRATION</span><h1>Users</h1><p>Search and filter the full user directory, then inspect account status and registration details.</p></div>
      {payload && <span className="admin-users-total">{payload.meta?.total ?? 0} users</span>}
    </header>

    <form className="admin-users-filters" onSubmit={applyFilters}>
      <label className="admin-users-search"><span>Search users</span><div><Search size={16} aria-hidden="true" /><input key={`search:${search}`} name="search" type="search" defaultValue={search} maxLength={255} placeholder="Name or email" /></div></label>
      <label><span>Role</span><select key={`role:${role}`} name="role" defaultValue={role}><option value="">All roles</option>{ROLES.map((value) => <option key={value} value={value}>{formatAdminUserLabel(value)}</option>)}</select></label>
      <label><span>Account status</span><select key={`status:${accountStatus}`} name="account_status" defaultValue={accountStatus}><option value="">All statuses</option><option value="active">Active</option><option value="suspended">Suspended</option></select></label>
      <label><span>Rows per page</span><select key={`size:${perPage}`} name="per_page" defaultValue={perPage}>{PAGE_SIZES.map((size) => <option key={size} value={size}>{size}</option>)}</select></label>
      <button className="button button-primary" type="submit">Apply filters</button>
    </form>

    {loading && <div className="admin-users-state" role="status"><span className="admin-users-spinner" />Loading users…</div>}
    {!loading && state.error && <div className="admin-users-error" role="alert"><p>{state.error}</p><button className="button button-outline" type="button" onClick={() => setRetry((value) => value + 1)}>Retry</button></div>}
    {!loading && !state.error && users.length === 0 && <div className="admin-users-empty"><UserRound size={22} /><h2>{search || role || accountStatus ? 'No users match these filters.' : 'No users found.'}</h2><p>Try a different search or filter selection.</p></div>}

    {!loading && !state.error && users.length > 0 && <div className="admin-users-table-wrap">
      <table className="admin-users-table"><thead><tr><th>User</th><th>Role</th><th>Account status</th><th>Registered</th><th /></tr></thead><tbody>
        {users.map((user) => <tr key={user.id}>
          <td><strong>{user.name}</strong><small>{user.email}</small></td>
          <td><span className={`admin-user-role is-${user.role}`}>{formatAdminUserLabel(user.role)}</span></td>
          <td><span className={`admin-user-status is-${user.account_status}`}>{formatAdminUserLabel(user.account_status)}</span></td>
          <td>{formatAdminUserDate(user.created_at)}</td>
          <td><Link className="admin-user-details-link" to={`/admin/users/${user.id}`}>Details <ChevronRight size={15} /></Link></td>
        </tr>)}
      </tbody></table>
      <div className="admin-users-mobile-list">{users.map((user) => <article className="admin-user-mobile-card" key={user.id}>
        <div><span className={`admin-user-status is-${user.account_status}`}>{formatAdminUserLabel(user.account_status)}</span><span className={`admin-user-role is-${user.role}`}>{formatAdminUserLabel(user.role)}</span></div>
        <h2>{user.name}</h2><p>{user.email}</p><small>Registered {formatAdminUserDate(user.created_at)}</small>
        <Link className="button button-small button-outline" to={`/admin/users/${user.id}`}>View user details <ChevronRight size={14} /></Link>
      </article>)}</div>
    </div>}

    {!loading && !state.error && payload?.meta?.last_page > 1 && <nav className="admin-users-pagination" aria-label="User directory pages">
      <button className="button button-outline button-small" type="button" disabled={page <= 1} onClick={() => goToPage(page - 1)}><ChevronLeft size={15} /> Previous</button>
      <span>Page {payload.meta.current_page || page} of {payload.meta.last_page} · {payload.meta.total} users</span>
      <button className="button button-outline button-small" type="button" disabled={page >= payload.meta.last_page} onClick={() => goToPage(page + 1)}>Next <ChevronRight size={15} /></button>
    </nav>}
  </section>
}

export default AdminUsersPage
