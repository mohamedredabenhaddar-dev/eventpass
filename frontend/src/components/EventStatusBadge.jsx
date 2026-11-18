function EventStatusBadge({ status }) {
  return <span className={`event-status-badge status-${status}`}>{status?.replaceAll('_', ' ') || 'unknown'}</span>
}

export default EventStatusBadge
