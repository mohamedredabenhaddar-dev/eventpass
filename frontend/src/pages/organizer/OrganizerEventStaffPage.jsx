import { useEffect, useState } from 'react'
import { ArrowLeft, RefreshCw, UserPlus, UserRoundX } from 'lucide-react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import ConfirmationDialog from '../../components/ConfirmationDialog.jsx'
import { fetchOrganizerEvent } from '../../services/organizerEventService.js'
import { assignOrganizerEventStaff, fetchOrganizerEventStaff, removeOrganizerEventStaff } from '../../services/ticketingService.js'

function OrganizerEventStaffPage() {
  const { eventId } = useParams()
  const [params, setParams] = useSearchParams()
  const page = Math.max(1, Number(params.get('page')) || 1)
  const [state, setState] = useState({ key: '', event: null, payload: null, error: '' })
  const [retry, setRetry] = useState(0)
  const [staffUserId, setStaffUserId] = useState('')
  const [formError, setFormError] = useState('')
  const [formBusy, setFormBusy] = useState(false)
  const [removeTarget, setRemoveTarget] = useState(null)
  const [removeBusy, setRemoveBusy] = useState(false)
  const [removeError, setRemoveError] = useState('')

  useEffect(() => {
    let active = true
    Promise.all([fetchOrganizerEvent(eventId), fetchOrganizerEventStaff(eventId, page)])
      .then(([event, payload]) => { if (active) setState({ key: `${eventId}:${page}:${retry}`, event, payload, error: '' }) })
      .catch((error) => { if (active) setState({ key: `${eventId}:${page}:${retry}`, event: null, payload: null, error: error.response?.data?.message || error.userMessage || 'Assigned staff could not be loaded.' }) })
    return () => { active = false }
  }, [eventId, page, retry])

  const key = `${eventId}:${page}:${retry}`
  const loading = state.key !== key
  const event = loading ? null : state.event
  const payload = loading ? null : state.payload
  const rows = payload?.data || []
  const changePage = (nextPage) => setParams((current) => { if (nextPage > 1) current.set('page', String(nextPage)); else current.delete('page'); return current })
  const apiError = (error, fallback) => {
    const errors = error.response?.data?.errors
    return errors ? Object.values(errors).flat().join(' ') : error.response?.data?.message || error.userMessage || fallback
  }

  const handleAssign = async (submitEvent) => {
    submitEvent.preventDefault()
    const id = Number(staffUserId)
    if (!Number.isInteger(id) || id < 1) {
      setFormError('Enter the ID of an existing staff account.')
      return
    }
    setFormBusy(true)
    setFormError('')
    try {
      await assignOrganizerEventStaff(eventId, id)
      setStaffUserId('')
      setRetry((value) => value + 1)
    } catch (error) {
      setFormError(apiError(error, 'This staff member could not be assigned.'))
    } finally { setFormBusy(false) }
  }

  const handleRemove = async () => {
    if (!removeTarget || removeBusy) return
    setRemoveBusy(true)
    setRemoveError('')
    try {
      await removeOrganizerEventStaff(eventId, removeTarget.id)
      setRemoveTarget(null)
      setRetry((value) => value + 1)
    } catch (error) {
      setRemoveError(apiError(error, 'The assignment could not be removed.'))
      setRemoveTarget(null)
    } finally { setRemoveBusy(false) }
  }

  return <section className="organizer-page organizer-access-page">
    <Link className="organizer-back-link" to={`/organizer/events/${eventId}`}><ArrowLeft size={15} /> Event overview</Link>
    <header className="organizer-page-heading"><div><span className="eyebrow">EVENT ACCESS</span><h1>{event?.title || 'Staff management'}</h1><p>Assign existing staff accounts to help manage event entry and exit.</p></div><button className="button button-outline" type="button" disabled={loading} onClick={() => setRetry((value) => value + 1)}><RefreshCw size={15} /> Refresh</button></header>
    <nav className="ticketing-subnav organizer-access-nav" aria-label="Event management sections"><Link to={`/organizer/events/${eventId}`}>Overview</Link><Link to={`/organizer/events/${eventId}/ticket-types`}>Ticket types</Link><Link to={`/organizer/events/${eventId}/reservations`}>Reservations</Link><Link className="active" to={`/organizer/events/${eventId}/staff`}>Staff management</Link><Link to={`/organizer/events/${eventId}/access`}>Access reports</Link></nav>
    {loading && <div className="organizer-state" role="status">Loading event staff…</div>}
    {!loading && state.error && <div className="organizer-state organizer-error" role="alert"><p>{state.error}</p><button className="button button-outline" type="button" onClick={() => setRetry((value) => value + 1)}>Try again</button></div>}
    {!loading && !state.error && <>
      <section className="organizer-panel organizer-assign-panel"><div className="organizer-panel-heading"><div><span className="eyebrow">NEW ASSIGNMENT</span><h2>Assign staff member</h2></div></div>
        <p className="organizer-access-note">Enter the account ID for an existing user with the staff role. The API has no staff directory or search endpoint; Laravel verifies the account and event ownership.</p>
        <form className="organizer-assign-form" onSubmit={handleAssign}>
          <label>Staff account ID<input type="number" min="1" step="1" inputMode="numeric" value={staffUserId} onChange={(change) => setStaffUserId(change.target.value)} placeholder="For example, 42" required /></label>
          <button className="button button-primary" type="submit" disabled={formBusy || !staffUserId}><UserPlus size={16} /> {formBusy ? 'Assigning…' : 'Assign staff'}</button>
        </form>
        {formError && <p className="organizer-inline-error" role="alert">{formError}</p>}
      </section>
      {removeError && <div className="ticketing-error" role="alert">{removeError}</div>}
      <section className="organizer-panel"><div className="organizer-panel-heading"><div><span className="eyebrow">ASSIGNED TEAM</span><h2>Staff members</h2></div><span className="organizer-access-count">{payload?.meta?.total ?? rows.length} assigned</span></div>
        {rows.length === 0 ? <div className="organizer-empty"><UserRoundX size={23} /><h3>No staff assigned yet.</h3><p>Assign a staff account using its existing account ID above.</p></div> : <div className="ticketing-table-wrap"><table className="ticketing-table organizer-staff-table"><thead><tr><th>Staff member</th><th>Email</th><th>Assigned</th><th>Assignment</th><th /></tr></thead><tbody>{rows.map((assignment) => <tr key={assignment.id}><td>{assignment.staff?.name || 'Staff member'}</td><td>{assignment.staff?.email || '—'}</td><td>{assignment.created_at ? new Date(assignment.created_at).toLocaleString() : '—'}</td><td>#{assignment.id}</td><td><button className="text-action organizer-remove-action" type="button" onClick={() => { setRemoveError(''); setRemoveTarget(assignment) }}>Remove</button></td></tr>)}</tbody></table></div>}
      </section>
      {payload?.meta?.last_page > 1 && <nav className="ticketing-pagination" aria-label="Assigned staff pages"><button className="button button-outline" type="button" disabled={page <= 1} onClick={() => changePage(page - 1)}>Previous</button><span>Page {payload.meta.current_page || page} of {payload.meta.last_page}</span><button className="button button-outline" type="button" disabled={page >= payload.meta.last_page} onClick={() => changePage(page + 1)}>Next</button></nav>}
    </>}
    {removeTarget && <ConfirmationDialog title="Remove staff assignment?" message={`Remove ${removeTarget.staff?.name || 'this staff member'} from ${event?.title || 'this event'}? They will no longer be assigned to this event.`} confirmLabel="Remove assignment" busy={removeBusy} onCancel={() => setRemoveTarget(null)} onConfirm={handleRemove} />}
  </section>
}

export default OrganizerEventStaffPage
