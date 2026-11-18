import { useEffect, useState } from 'react'
import { ArrowLeft, CalendarDays, MapPin } from 'lucide-react'
import { Link, useParams } from 'react-router-dom'
import EventStatusBadge from '../../components/EventStatusBadge.jsx'
import OrganizerEventActions from '../../components/OrganizerEventActions.jsx'
import { fetchOrganizerEvent } from '../../services/organizerEventService.js'

function formatRange(start, end) {
  const format = (value) => {
    if (!value) return 'Date unavailable'
    const date = new Date(value)
    return Number.isNaN(date.getTime()) ? 'Date unavailable' : date.toLocaleString()
  }
  return `${format(start)}${end ? ` – ${format(end)}` : ''}`
}

function OrganizerEventDetailPage() {
  const { id } = useParams()
  const [result, setResult] = useState({ key: null, event: null, error: '' })
  const [attempt, setAttempt] = useState(0)

  useEffect(() => {
    let active = true
    fetchOrganizerEvent(id)
      .then((event) => { if (active) setResult({ key: `${id}:${attempt}`, event, error: '' }) })
      .catch((requestError) => { if (active) setResult({ key: `${id}:${attempt}`, event: null, error: requestError.response?.data?.message || requestError.userMessage || 'Event details could not be loaded.' }) })
    return () => { active = false }
  }, [id, attempt])

  const currentKey = `${id}:${attempt}`
  const loading = result.key !== currentKey
  const event = loading ? null : result.event
  const error = loading ? '' : result.error
  if (loading) return <div className="organizer-state" role="status">Loading event…</div>
  if (error) return <div className="organizer-state organizer-error" role="alert"><p>{error}</p><button className="button button-outline" type="button" onClick={() => setAttempt((value) => value + 1)}>Try again</button><Link className="organizer-back-link" to="/organizer/events">Back to my events</Link></div>
  if (!event) return null

  const updateEvent = (updated) => setResult((current) => ({ ...current, event: updated }))
  const location = [event.venue_name, event.address, event.city, event.country].filter(Boolean).join(', ')

  return <section className="organizer-page">
    <Link className="organizer-back-link" to="/organizer/events"><ArrowLeft size={15} /> Back to my events</Link>
    <div className="organizer-event-detail-heading"><div><span className="eyebrow">EVENT DETAILS</span><h1>{event.title}</h1><p>{event.category} <span>·</span> {event.slug}</p></div><EventStatusBadge status={event.status} /></div>
    <article className="organizer-panel organizer-detail-panel">
      <div className="organizer-detail-actions"><OrganizerEventActions event={event} onUpdated={updateEvent} /></div>
      <nav className="ticketing-subnav organizer-access-nav" aria-label="Event management sections"><Link to={`/organizer/events/${event.id}`}>Overview</Link><Link to={`/organizer/events/${event.id}/ticket-types`}>Ticket types</Link><Link to={`/organizer/events/${event.id}/reservations`}>Reservations</Link><Link to={`/organizer/events/${event.id}/staff`}>Staff management</Link><Link to={`/organizer/events/${event.id}/access`}>Access reports</Link></nav>
      <div className="organizer-detail-facts"><p><CalendarDays size={17} /><span>{formatRange(event.start_at, event.end_at)}</span></p>{location && <p><MapPin size={17} /><span>{location}</span></p>}<p><strong>Capacity</strong><span>{event.capacity}</span></p></div>
      <section className="organizer-detail-description"><h2>About this event</h2><p>{event.description}</p></section>
      {event.cancellation_reason && <section className="organizer-detail-description"><h2>Cancellation reason</h2><p>{event.cancellation_reason}</p></section>}
      {event.status === 'suspended' && event.suspended_at && <p className="organizer-detail-note">Suspended on {new Date(event.suspended_at).toLocaleString()}. Only a super admin can reactivate this event.</p>}
    </article>
  </section>
}

export default OrganizerEventDetailPage
