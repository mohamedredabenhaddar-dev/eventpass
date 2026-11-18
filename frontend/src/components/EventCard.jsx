import { ArrowUpRight, MapPin } from 'lucide-react'
import { Link } from 'react-router-dom'

function formatEventDate(value, options = { month: 'short', day: 'numeric' }) {
  if (!value) return 'Date to be announced'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? 'Date to be announced' : new Intl.DateTimeFormat(undefined, options).format(date)
}

function EventCard({ event }) {
  const categoryClass = `event-art-${(event.category || 'event').toLowerCase().replace(/[^a-z0-9]+/g, '-')}`

  return (
    <article className="event-card">
      <Link className={`event-art ${categoryClass}`} to={`/events/${encodeURIComponent(event.slug)}`} aria-label={`View ${event.title}`}>
        {event.cover_image ? <img className="event-cover" src={event.cover_image} alt="" loading="lazy" /> : <span className="event-art-orbit" aria-hidden="true" />}
        <span className="event-date-badge">{formatEventDate(event.start_at)}</span>
        <span className="event-art-open"><ArrowUpRight size={17} /></span>
      </Link>
      <div className="event-card-copy">
        <div className="event-card-topline"><span>{event.category}</span><span>{formatEventDate(event.start_at, { hour: 'numeric', minute: '2-digit' })}</span></div>
        <Link to={`/events/${encodeURIComponent(event.slug)}`} className="event-title-link"><h3>{event.title}</h3><ArrowUpRight size={17} /></Link>
        <p><MapPin size={14} /> {[event.city, event.country].filter(Boolean).join(', ')}</p>
      </div>
    </article>
  )
}

export default EventCard
