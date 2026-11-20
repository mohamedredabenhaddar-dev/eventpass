import { useEffect, useRef } from 'react'
import { X } from 'lucide-react'
import {
  actionLabel,
  auditActorLabel,
  auditActorTypeLabel,
  entityTypeLabel,
  formatAuditLocalTimestamp,
  formatAuditTimestamp,
  formatMetadataValue,
  humanize,
  metadataLabel,
  outcomeLabel,
} from './activityHistoryUtils.js'

function DetailRow({ label, children }) {
  return <div className="admin-activity-detail-row"><dt>{label}</dt><dd>{children}</dd></div>
}

function ActivityDetailsDrawer({ event, onClose }) {
  const closeRef = useRef(null)

  useEffect(() => {
    if (!event) return undefined

    const handleKeyDown = (keyEvent) => {
      if (keyEvent.key === 'Escape') onClose()
    }

    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    document.addEventListener('keydown', handleKeyDown)
    closeRef.current?.focus()

    return () => {
      document.removeEventListener('keydown', handleKeyDown)
      document.body.style.overflow = previousOverflow
    }
  }, [event, onClose])

  if (!event) return null

  const metadata = event.metadata && typeof event.metadata === 'object' && !Array.isArray(event.metadata)
    ? Object.entries(event.metadata)
    : []

  return <div
    className="admin-activity-drawer-backdrop"
    onMouseDown={(mouseEvent) => {
      if (mouseEvent.target === mouseEvent.currentTarget) onClose()
    }}
  >
    <aside className="admin-activity-drawer" role="dialog" aria-modal="true" aria-labelledby="admin-activity-drawer-title">
      <header className="admin-activity-drawer-head">
        <div>
          <span className="eyebrow">AUDIT EVENT #{event.id}</span>
          <h2 id="admin-activity-drawer-title">{actionLabel(event.action)}</h2>
          <p>{formatAuditTimestamp(event.occurred_at)}</p>
        </div>
        <button
          ref={closeRef}
          className="admin-activity-drawer-close"
          type="button"
          aria-label="Close activity details"
          onClick={onClose}
        >
          <X size={18} />
        </button>
      </header>

      <dl className="admin-activity-detail-grid">
        <DetailRow label="Timestamp (UTC)">{formatAuditTimestamp(event.occurred_at)}</DetailRow>
        <DetailRow label="Timestamp (local)">{formatAuditLocalTimestamp(event.occurred_at)}</DetailRow>
        <DetailRow label="Actor">
          {auditActorLabel(event)}
          {event.actor_role ? ` · ${humanize(event.actor_role)}` : ''}
        </DetailRow>
        <DetailRow label="Actor type">{auditActorTypeLabel(event.actor_type)}</DetailRow>
        <DetailRow label="Action">
          {actionLabel(event.action)} <small>({event.action})</small>
        </DetailRow>
        <DetailRow label="Entity">
          {event.entity_type ? `${entityTypeLabel(event.entity_type)}${event.entity_id === null || event.entity_id === undefined ? '' : ` #${event.entity_id}`}` : '—'}
        </DetailRow>
        <DetailRow label="Outcome">
          <span className={`admin-activity-outcome is-${event.outcome}`}>{outcomeLabel(event.outcome)}</span>
        </DetailRow>
      </dl>

      <section className="admin-activity-detail-metadata">
        <h3>Approved metadata</h3>
        {metadata.length === 0
          ? <p>No approved metadata is available for this event.</p>
          : <dl>{metadata.map(([key, value]) => <DetailRow key={key} label={metadataLabel(key)}>{formatMetadataValue(value)}</DetailRow>)}</dl>}
      </section>

      <p className="admin-activity-detail-note">
        Only allowlisted audit fields are returned by the API. Credentials, tokens, QR secrets,
        payment secrets, and personal contact details are never displayed.
      </p>
    </aside>
  </div>
}

export default ActivityDetailsDrawer
