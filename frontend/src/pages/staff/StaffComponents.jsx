import { AlertTriangle, CalendarDays, MapPin } from 'lucide-react'
import { Link } from 'react-router-dom'
import { eventStatusClass, formatEventDate } from './staffUtils.js'

export function StaffPageHeading({ eyebrow = 'STAFF WORKSPACE', title, description, actions }) {
  return <div className="staff-page-heading"><div><span className="staff-eyebrow">{eyebrow}</span><h1>{title}</h1>{description && <p>{description}</p>}</div>{actions && <div className="staff-heading-actions">{actions}</div>}</div>
}

export function StaffLoading({ label = 'Loading…' }) {
  return <div className="staff-state" role="status"><span className="staff-spinner" />{label}</div>
}

export function StaffError({ message, onRetry }) {
  return <div className="staff-alert staff-alert-error" role="alert"><AlertTriangle size={20} /><span>{message}</span>{onRetry && <button className="button button-small button-outline" type="button" onClick={onRetry}>Retry</button>}</div>
}

export function StaffEventCard({ item }) {
  const event = item?.event || item
  const assignment = item?.assignment
  const location = [event.venue_name, event.city, event.country].filter(Boolean).join(', ')
  return <article className="staff-event-card">
    <div className="staff-event-card-top"><span className={eventStatusClass(event.status)}>{event.status || 'Unknown status'}</span>{assignment?.assigned_at && <small>Assigned {formatEventDate(assignment.assigned_at, { dateStyle: 'medium', timeStyle: undefined })}</small>}</div>
    <h2>{event.title}</h2>
    <p className="staff-event-fact"><CalendarDays size={16} />{formatEventDate(event.start_at)}</p>
    <p className="staff-event-fact"><MapPin size={16} />{location || event.address || 'Location not provided'}</p>
    <div className="staff-event-card-actions"><Link className="button button-small button-outline" to={`/staff/events/${event.id}`}>Event workspace</Link></div>
  </article>
}

export function StaffEmpty({ children = 'No assigned events are available.' }) {
  return <div className="staff-empty"><CalendarDays size={28} /><p>{children}</p></div>
}
