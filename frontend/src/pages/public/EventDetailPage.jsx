import { useEffect, useState } from 'react'
import { ArrowLeft, CalendarDays, MapPin } from 'lucide-react'
import { Link, useParams } from 'react-router-dom'
import { fetchPublishedEventBySlug } from '../../services/eventService.js'
import EventTicketSelection from '../../components/EventTicketSelection.jsx'

function formatDateRange(start, end) {
  const options = { dateStyle: 'long', timeStyle: 'short' }
  const format = (value) => {
    if (!value) return null
    const date = new Date(value)
    return Number.isNaN(date.getTime()) ? null : new Intl.DateTimeFormat(undefined, options).format(date)
  }
  const formattedStart = format(start)
  const formattedEnd = format(end)
  if (!formattedStart) return 'Date to be announced'
  return formattedEnd ? `${formattedStart} – ${formattedEnd}` : formattedStart
}

function EventDetailPage() {
  const { slug } = useParams()
  const [result, setResult] = useState({ key: null, event: null, error: '' })
  const [attempt, setAttempt] = useState(0)

  useEffect(() => {
    let active = true
    fetchPublishedEventBySlug(slug)
      .then((event) => { if (active) setResult({ key: `${slug}:${attempt}`, event, error: '' }) })
      .catch((requestError) => {
        if (active) setResult({ key: `${slug}:${attempt}`, event: null, error: requestError.response?.status === 404
          ? 'This event could not be found or is no longer published.'
          : requestError.userMessage || 'Event details could not be loaded. Please try again.' })
      })
    return () => { active = false }
  }, [slug, attempt])

  const loading = result.key !== `${slug}:${attempt}`
  const event = loading ? null : result.event
  const error = loading ? '' : result.error
  if (loading) return <main className="event-detail-page page-container"><div className="events-state" role="status">Loading event details…</div></main>
  if (error) return <main className="event-detail-page page-container"><div className="events-empty" role="alert"><span className="eyebrow">EVENT UNAVAILABLE</span><h1>We couldn't open this event.</h1><p>{error}</p><div className="detail-actions"><button className="button button-outline" type="button" onClick={() => setAttempt((value) => value + 1)}>Try again</button><Link className="button button-primary" to="/events">Browse events</Link></div></div></main>
  if (!event) return null

  const location = [event.venue_name, event.address, event.city, event.country].filter(Boolean)

  return <main className="event-detail-page page-container">
    <Link className="detail-back" to="/events"><ArrowLeft size={15} /> All events</Link>
    <article className="event-detail-card">
      <div className="event-detail-art">
        {event.cover_image ? <img src={event.cover_image} alt="" /> : <span className="event-art-orbit" aria-hidden="true" />}
        {event.category && <span className="detail-category">{event.category}</span>}
      </div>
      <div className="event-detail-copy">
        <span className="eyebrow">PUBLISHED EVENT</span>
        <h1>{event.title}</h1>
        <div className="detail-facts">
          <p><CalendarDays size={17} /><span>{formatDateRange(event.start_at, event.end_at)}</span></p>
          {location.length > 0 && <p><MapPin size={17} /><span>{location.join(', ')}</span></p>}
          {event.capacity != null && <p><span className="capacity-icon" aria-hidden="true">#</span><span>Capacity: {event.capacity}</span></p>}
        </div>
        {event.description && <section className="event-description"><h2>About this event</h2><p>{event.description}</p></section>}
        <EventTicketSelection event={event} />
      </div>
    </article>
  </main>
}

export default EventDetailPage
