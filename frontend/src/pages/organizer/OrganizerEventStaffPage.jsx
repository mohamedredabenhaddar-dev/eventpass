import { useEffect, useState } from 'react'
import { ArrowLeft, BadgeCheck, CircleAlert, RefreshCw, Search, UserPlus, UserRoundX, Users } from 'lucide-react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import ConfirmationDialog from '../../components/ConfirmationDialog.jsx'
import { fetchOrganizerEvent } from '../../services/organizerEventService.js'
import {
  assignOrganizerEventStaff,
  fetchAvailableOrganizerEventStaff,
  fetchOrganizerEventStaff,
  removeOrganizerEventStaff,
} from '../../services/ticketingService.js'

function readApiError(error, fallback) {
  const errors = error?.response?.data?.errors
  if (errors) return Object.values(errors).flat().join(' ')
  return error?.response?.data?.message || error?.userMessage || fallback
}

function initialsFor(name) {
  const parts = String(name || '').trim().split(/\s+/).filter(Boolean)
  if (parts.length === 0) return '?'
  return (parts[0][0] + (parts[1]?.[0] || '')).toUpperCase()
}

function OrganizerEventStaffPage() {
  const { eventId } = useParams()
  const [params, setParams] = useSearchParams()
  const page = Math.max(1, Number(params.get('page')) || 1)
  const [state, setState] = useState({ key: '', event: null, payload: null, error: '' })
  const [retry, setRetry] = useState(0)
  const [search, setSearch] = useState('')
  const [directory, setDirectory] = useState({ key: '', items: [], error: '' })
  const [directoryNonce, setDirectoryNonce] = useState(0)
  const [assignBusyId, setAssignBusyId] = useState(null)
  const [formError, setFormError] = useState('')
  const [removeTarget, setRemoveTarget] = useState(null)
  const [removeBusy, setRemoveBusy] = useState(false)
  const [removeError, setRemoveError] = useState('')

  useEffect(() => {
    let active = true
    Promise.all([fetchOrganizerEvent(eventId), fetchOrganizerEventStaff(eventId, page)])
      .then(([event, payload]) => { if (active) setState({ key: `${eventId}:${page}:${retry}`, event, payload, error: '' }) })
      .catch((error) => { if (active) setState({ key: `${eventId}:${page}:${retry}`, event: null, payload: null, error: readApiError(error, 'Assigned staff could not be loaded.') }) })
    return () => { active = false }
  }, [eventId, page, retry])

  useEffect(() => {
    let active = true
    const key = `${search}:${directoryNonce}`
    const timer = setTimeout(() => {
      fetchAvailableOrganizerEventStaff(eventId, search)
        .then((items) => { if (active) setDirectory({ key, items, error: '' }) })
        .catch((error) => { if (active) setDirectory({ key, items: [], error: readApiError(error, 'The staff directory could not be loaded.') }) })
    }, 250)
    return () => { active = false; clearTimeout(timer) }
  }, [eventId, search, directoryNonce])

  const key = `${eventId}:${page}:${retry}`
  const loading = state.key !== key
  const event = loading ? null : state.event
  const payload = loading ? null : state.payload
  const rows = payload?.data || []
  const directoryKey = `${search}:${directoryNonce}`
  const directoryLoading = directory.key !== directoryKey
  const directoryItems = directoryLoading ? [] : directory.items
  const directoryError = directoryLoading ? '' : directory.error
  const changePage = (nextPage) => setParams((current) => { if (nextPage > 1) current.set('page', String(nextPage)); else current.delete('page'); return current })

  const refreshEverything = () => {
    setRetry((value) => value + 1)
    setDirectoryNonce((value) => value + 1)
  }

  const handleAssign = async (person) => {
    if (assignBusyId !== null) return
    setAssignBusyId(person.id)
    setFormError('')
    try {
      await assignOrganizerEventStaff(eventId, person.id)
      refreshEverything()
    } catch (error) {
      setFormError(readApiError(error, 'This staff member could not be assigned.'))
    } finally { setAssignBusyId(null) }
  }

  const handleRemove = async () => {
    if (!removeTarget || removeBusy) return
    setRemoveBusy(true)
    setRemoveError('')
    try {
      await removeOrganizerEventStaff(eventId, removeTarget.id)
      setRemoveTarget(null)
      refreshEverything()
    } catch (error) {
      setRemoveError(readApiError(error, 'The assignment could not be removed.'))
      setRemoveTarget(null)
    } finally { setRemoveBusy(false) }
  }

  return <section className="organizer-page organizer-access-page">
    <Link className="organizer-back-link" to={`/organizer/events/${eventId}`}><ArrowLeft size={15} /> Event overview</Link>
    <header className="organizer-page-heading"><div><span className="eyebrow">EVENT ACCESS</span><h1>{event?.title || 'Staff management'}</h1><p>Assign existing staff accounts to help manage event entry and exit.</p></div><button className="button button-outline" type="button" disabled={loading} onClick={refreshEverything}><RefreshCw size={15} /> Refresh</button></header>
    <nav className="ticketing-subnav organizer-access-nav" aria-label="Event management sections"><Link to={`/organizer/events/${eventId}`}>Overview</Link><Link to={`/organizer/events/${eventId}/ticket-types`}>Ticket types</Link><Link to={`/organizer/events/${eventId}/reservations`}>Reservations</Link><Link className="active" to={`/organizer/events/${eventId}/staff`}>Staff management</Link><Link to={`/organizer/events/${eventId}/access`}>Access reports</Link></nav>
    {loading && <div className="organizer-state" role="status">Loading event staff…</div>}
    {!loading && state.error && <div className="organizer-state organizer-error" role="alert"><p>{state.error}</p><button className="button button-outline" type="button" onClick={() => setRetry((value) => value + 1)}>Try again</button></div>}
    {!loading && !state.error && <>
      <section className="organizer-panel organizer-directory-panel">
        <div className="organizer-panel-heading"><div><span className="eyebrow">STAFF DIRECTORY</span><h2>Available staff</h2></div><span className="organizer-access-count">{directoryLoading ? 'Searching…' : `${directoryItems.length} found`}</span></div>
        <p className="organizer-access-note">Search active staff accounts by name or email, then assign them to this event with one click. No account IDs to memorize.</p>
        <div className="organizer-directory-search">
          <Search size={16} aria-hidden="true" />
          <input
            type="search"
            value={search}
            aria-label="Search available staff by name or email"
            placeholder="Search by staff name or email"
            onChange={(change) => setSearch(change.target.value)}
          />
          {search && <button className="organizer-directory-clear" type="button" onClick={() => setSearch('')}>Clear</button>}
        </div>
        {formError && <p className="organizer-inline-error" role="alert">{formError}</p>}
        {directoryLoading && <div className="organizer-directory-state" role="status"><RefreshCw size={16} className="organizer-spin" /> Loading staff directory…</div>}
        {!directoryLoading && directoryError && <div className="organizer-directory-state is-error" role="alert"><CircleAlert size={17} /><span>{directoryError}</span><button className="button button-outline button-small" type="button" onClick={() => setDirectoryNonce((value) => value + 1)}>Retry</button></div>}
        {!directoryLoading && !directoryError && directoryItems.length === 0 && <div className="organizer-directory-state"><Users size={20} /><span>{search.trim() ? `No active staff match “${search.trim()}”.` : 'No active staff accounts are available to assign.'}</span></div>}
        {!directoryLoading && !directoryError && directoryItems.length > 0 && <div className="organizer-directory-list">
          {directoryItems.map((person) => <article className={`organizer-directory-row${person.already_assigned ? ' is-assigned' : ''}`} key={person.id}>
            <span className="organizer-staff-avatar" aria-hidden="true">{initialsFor(person.name)}</span>
            <div className="organizer-staff-identity"><strong>{person.name}</strong><span>{person.email}</span></div>
            {person.already_assigned
              ? <span className="organizer-badge is-assigned"><BadgeCheck size={13} /> Already assigned</span>
              : <button className="button button-primary button-small organizer-directory-assign" type="button" disabled={assignBusyId !== null} onClick={() => handleAssign(person)}><UserPlus size={14} /> {assignBusyId === person.id ? 'Assigning…' : 'Assign'}</button>}
          </article>)}
        </div>}
      </section>

      {removeError && <div className="ticketing-error" role="alert">{removeError}</div>}
      <section className="organizer-panel"><div className="organizer-panel-heading"><div><span className="eyebrow">ASSIGNED TEAM</span><h2>Staff members</h2></div><span className="organizer-access-count">{payload?.meta?.total ?? rows.length} assigned</span></div>
        {rows.length === 0
          ? <div className="organizer-empty"><UserRoundX size={23} /><h3>No staff assigned yet.</h3><p>Use the staff directory above to assign your first team member.</p></div>
          : <div className="organizer-staff-cards">{rows.map((assignment) => <article className="organizer-staff-card" key={assignment.id}>
            <span className="organizer-staff-avatar" aria-hidden="true">{initialsFor(assignment.staff?.name)}</span>
            <div className="organizer-staff-identity"><strong>{assignment.staff?.name || 'Staff member'}</strong><span>{assignment.staff?.email || 'No email on record'}</span></div>
            <span className="organizer-badge is-assigned"><BadgeCheck size={13} /> Assigned</span>
            <span className="organizer-staff-time">{assignment.created_at ? `Assigned ${new Date(assignment.created_at).toLocaleDateString()}` : 'Assignment date unavailable'}</span>
            <button className="button button-outline button-small organizer-remove-action" type="button" onClick={() => { setRemoveError(''); setRemoveTarget(assignment) }}>Remove</button>
          </article>)}</div>}
      </section>
      {payload?.meta?.last_page > 1 && <nav className="ticketing-pagination" aria-label="Assigned staff pages"><button className="button button-outline" type="button" disabled={page <= 1} onClick={() => changePage(page - 1)}>Previous</button><span>Page {payload.meta.current_page || page} of {payload.meta.last_page}</span><button className="button button-outline" type="button" disabled={page >= payload.meta.last_page} onClick={() => changePage(page + 1)}>Next</button></nav>}
    </>}
    {removeTarget && <ConfirmationDialog title="Remove staff assignment?" message={`Remove ${removeTarget.staff?.name || 'this staff member'} from ${event?.title || 'this event'}? They will no longer be assigned to this event.`} confirmLabel="Remove assignment" busy={removeBusy} onCancel={() => setRemoveTarget(null)} onConfirm={handleRemove} />}
  </section>
}

export default OrganizerEventStaffPage
