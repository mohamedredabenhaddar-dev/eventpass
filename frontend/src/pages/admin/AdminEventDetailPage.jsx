import { useEffect, useRef, useState } from 'react'
import { ArrowLeft, CalendarDays, MapPin, ShieldAlert } from 'lucide-react'
import { Link, useParams } from 'react-router-dom'
import ConfirmationDialog from '../../components/ConfirmationDialog.jsx'
import EventStatusBadge from '../../components/EventStatusBadge.jsx'
import { fetchSuperAdminEvent, suspendSuperAdminEvent, reactivateSuperAdminEvent, cancelSuperAdminEvent } from '../../services/superAdminEventService.js'
import { adminEventError, formatEventAdminDate } from './adminEventUtils.js'

function AdminEventDetailPage() {
  const { eventId } = useParams()
  const [state, setState] = useState({ key: '', event: null, error: '' })
  const [retry, setRetry] = useState(0)
  const [intent, setIntent] = useState('')
  const [cancelOpen, setCancelOpen] = useState(false)
  const [cancellationReason, setCancellationReason] = useState('')
  const [busy, setBusy] = useState(false)
  const busyRef = useRef(false)
  const [actionError, setActionError] = useState('')
  const [notice, setNotice] = useState('')

  useEffect(() => {
    let active = true
    fetchSuperAdminEvent(eventId)
      .then((event) => { if (active) setState({ key: `${eventId}:${retry}`, event, error: '' }) })
      .catch((error) => { if (active) setState({ key: `${eventId}:${retry}`, event: null, error: adminEventError(error, 'Event details could not be loaded.') }) })
    return () => { active = false }
  }, [eventId, retry])

  const key = `${eventId}:${retry}`
  const loading = state.key !== key
  const event = loading ? null : state.event

  const performAction = async (action, reason) => {
    if (busyRef.current) return
    busyRef.current = true
    setBusy(true)
    setActionError('')
    setNotice('')
    try {
      let updated
      if (action === 'suspend') updated = await suspendSuperAdminEvent(eventId)
      if (action === 'reactivate') updated = await reactivateSuperAdminEvent(eventId)
      if (action === 'cancel') updated = await cancelSuperAdminEvent(eventId, reason)
      if (action === 'suspend') setNotice(`Event suspended. The backend returned status “${updated.status}”; it is unavailable for normal public discovery.`)
      if (action === 'reactivate') setNotice(`Event reactivated. The backend returned status “${updated.status}”.`)
      if (action === 'cancel') setNotice(`Event cancelled. The backend returned status “${updated.status}”.`)
      setIntent('')
      setCancelOpen(false)
      setCancellationReason('')
      setRetry((value) => value + 1)
    } catch (error) {
      const actionLabel = { suspend: 'suspended', reactivate: 'reactivated', cancel: 'cancelled' }[action]
      setActionError(adminEventError(error, `The event could not be ${actionLabel}.`))
      if (action !== 'cancel' || [404, 409].includes(error.response?.status)) setIntent('')
      if (error.response?.status === 409 || error.response?.status === 404) {
        setCancelOpen(false)
        setRetry((value) => value + 1)
      }
    } finally {
      busyRef.current = false
      setBusy(false)
    }
  }

  const openCancel = () => {
    setActionError('')
    setCancellationReason('')
    setCancelOpen(true)
  }

  const submitCancel = (formEvent) => {
    formEvent.preventDefault()
    const reason = cancellationReason.trim()
    if (!reason) {
      setActionError('A cancellation reason is required.')
      return
    }
    if (reason.length > 2000) {
      setActionError('The cancellation reason must be 2,000 characters or fewer.')
      return
    }
    performAction('cancel', reason)
  }

  if (loading) return <div className="admin-events-state" role="status"><span className="admin-events-spinner" />Loading event details…</div>
  if (state.error) return <section className="admin-event-detail-page"><div className="admin-events-error" role="alert"><p>{state.error}</p><button className="button button-outline" type="button" onClick={() => setRetry((value) => value + 1)}>Retry</button></div><Link className="admin-back-link" to="/admin/events"><ArrowLeft size={15} /> All events</Link></section>
  if (!event) return null

  const location = [event.venue_name, event.address, event.city, event.country].filter(Boolean).join(', ')
  const canSuspend = ['draft', 'published'].includes(event.status)
  const canReactivate = event.status === 'suspended'
  const canCancel = event.status !== 'cancelled'

  return <section className="admin-event-detail-page">
    <Link className="admin-back-link" to="/admin/events"><ArrowLeft size={15} /> All events</Link>
    <header className="admin-event-detail-heading"><div><span className="eyebrow">EVENT #{event.id}</span><h1>{event.title}</h1><p>{event.organizer?.name || 'Organizer unavailable'} · {event.category || 'Category unavailable'}</p></div><EventStatusBadge status={event.status} /></header>
    {notice && <p className="admin-event-notice" role="status">{notice}</p>}
    {actionError && <p className="admin-event-action-error" role="alert">{actionError}</p>}
    <article className="admin-event-detail-card">
      <section className="admin-event-about"><span className="eyebrow">EVENT INFORMATION</span><h2>About this event</h2><p>{event.description || 'No description was provided.'}</p></section>
      <div className="admin-event-facts">
        <div><span><CalendarDays size={16} /> Schedule</span><strong>{formatEventAdminDate(event.start_at)}</strong><small>Ends {formatEventAdminDate(event.end_at)}</small></div>
        <div><span><MapPin size={16} /> Venue</span><strong>{event.venue_name || '—'}</strong><small>{location || 'Location unavailable'}</small></div>
        <div><span>Organizer</span><strong>{event.organizer?.name || '—'}</strong><small>Account ID {event.organizer?.id ?? event.organizer_id ?? '—'}</small></div>
        <div><span>Capacity</span><strong>{event.capacity ?? '—'}</strong><small>Created {formatEventAdminDate(event.created_at)}</small></div>
      </div>
    </article>
    {(event.suspended_at || event.status === 'suspended') && <article className="admin-event-system-note"><ShieldAlert size={18} /><div><strong>Suspension information</strong><span>Suspended at {formatEventAdminDate(event.suspended_at)}{event.suspended_by ? ` · Super Admin account ID ${event.suspended_by}` : ''}</span></div></article>}
    {event.cancellation_reason && <article className="admin-event-cancellation"><span className="eyebrow">CANCELLATION REASON</span><p>{event.cancellation_reason}</p></article>}
    <section className="admin-event-controls"><div><span className="eyebrow">EVENT STATUS ACTIONS</span><p>All actions are validated by Laravel. Some actions are available only for specific event statuses.</p></div><div className="admin-event-control-buttons">
      {canSuspend && <button className="button button-outline" type="button" disabled={busy} onClick={() => { setActionError(''); setIntent('suspend') }}>Suspend event</button>}
      {canReactivate && <button className="button button-primary" type="button" disabled={busy} onClick={() => { setActionError(''); setIntent('reactivate') }}>Reactivate event</button>}
      {canCancel && <button className="button button-outline admin-event-cancel-button" type="button" disabled={busy} onClick={openCancel}>Cancel event</button>}
    </div></section>
    {intent === 'suspend' && <ConfirmationDialog title="Suspend this event?" message="The event will become suspended and unavailable for normal public discovery. Only draft or published events can be suspended." confirmLabel="Suspend event" busy={busy} onCancel={() => setIntent('')} onConfirm={() => performAction('suspend')} />}
    {intent === 'reactivate' && <ConfirmationDialog title="Reactivate this event?" message="Reactivation returns the event to draft status. It will not be republished automatically." confirmLabel="Reactivate to draft" busy={busy} onCancel={() => setIntent('')} onConfirm={() => performAction('reactivate')} />}
    {cancelOpen && <div className="confirmation-backdrop admin-event-cancel-backdrop"><section className="confirmation-dialog admin-event-cancel-dialog" role="dialog" aria-modal="true" aria-labelledby="admin-event-cancel-title"><h2 id="admin-event-cancel-title">Cancel this event?</h2><p>This action marks the event cancelled. The cancellation reason is saved with the event record.</p><form onSubmit={submitCancel}><label>Cancellation reason<textarea value={cancellationReason} onChange={(change) => setCancellationReason(change.target.value)} maxLength={2000} rows={4} required /></label><small>{cancellationReason.length}/2,000 characters</small>{actionError && <p className="admin-event-action-error" role="alert">{actionError}</p>}<div className="confirmation-actions"><button className="button button-outline" type="button" disabled={busy} onClick={() => setCancelOpen(false)}>Keep event</button><button className="button button-primary admin-event-cancel-button" type="submit" disabled={busy || !cancellationReason.trim()}>{busy ? 'Cancelling…' : 'Confirm cancellation'}</button></div></form></section></div>}
  </section>
}

export default AdminEventDetailPage
