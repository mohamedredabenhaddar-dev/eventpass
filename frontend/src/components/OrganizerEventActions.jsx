import { useState } from 'react'
import { Link } from 'react-router-dom'
import { cancelOrganizerEvent, publishOrganizerEvent, unpublishOrganizerEvent } from '../services/organizerEventService.js'

function OrganizerEventActions({ event, onUpdated }) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [cancelOpen, setCancelOpen] = useState(false)
  const [reason, setReason] = useState('')

  const runAction = async (action) => {
    setBusy(true)
    setError('')
    try {
      const updated = await action()
      onUpdated?.(updated)
      return updated
    } catch (requestError) {
      setError(requestError.response?.data?.message || requestError.userMessage || 'The event could not be updated.')
      return null
    } finally {
      setBusy(false)
    }
  }

  const submitCancellation = async (submission) => {
    submission.preventDefault()
    if (!reason.trim()) return
    const updated = await runAction(() => cancelOrganizerEvent(event.id, reason.trim()))
    if (updated) {
      setCancelOpen(false)
      setReason('')
    }
  }

  if (event.status === 'suspended' || event.status === 'cancelled') {
    return <div className="organizer-action-note">{event.status === 'suspended' ? 'Suspended events cannot be changed by organizers.' : 'Cancelled events cannot be changed or republished.'}</div>
  }

  return <div className="organizer-event-actions">
    <div className="action-button-row">
      <Link className="button button-small button-outline" to={`/organizer/events/${event.id}/edit`}>Edit</Link>
      {event.status === 'draft' && <button className="button button-small button-primary" type="button" disabled={busy} onClick={() => runAction(() => publishOrganizerEvent(event.id))}>Publish</button>}
      {event.status === 'published' && <button className="button button-small button-outline" type="button" disabled={busy} onClick={() => runAction(() => unpublishOrganizerEvent(event.id))}>Unpublish</button>}
      <button className="button button-small button-danger" type="button" disabled={busy} onClick={() => { setError(''); setCancelOpen(true) }}>Cancel event</button>
    </div>
    {error && <p className="organizer-action-error" role="alert">{error}</p>}
    {cancelOpen && <div className="confirm-backdrop" role="presentation">
      <form className="confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="cancel-title" onSubmit={submitCancellation}>
        <span className="eyebrow">CONFIRM CANCELLATION</span>
        <h2 id="cancel-title">Cancel event: {event.title}?</h2>
        <p>This action is permanent. Cancelled events cannot be republished. Provide a reason to continue.</p>
        <label htmlFor={`cancel-reason-${event.id}`}>Cancellation reason</label>
        <textarea id={`cancel-reason-${event.id}`} value={reason} onChange={(change) => setReason(change.target.value)} maxLength={2000} required rows={4} />
        {error && <p className="organizer-action-error" role="alert">{error}</p>}
        <div className="dialog-actions">
          <button className="button button-outline" type="button" disabled={busy} onClick={() => setCancelOpen(false)}>Keep event</button>
          <button className="button button-danger" type="submit" disabled={busy || !reason.trim()}>{busy ? 'Cancelling...' : 'Confirm cancellation'}</button>
        </div>
      </form>
    </div>}
  </div>
}

export default OrganizerEventActions
