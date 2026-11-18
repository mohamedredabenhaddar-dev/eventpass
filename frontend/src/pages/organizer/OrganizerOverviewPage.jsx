import { useEffect, useState } from 'react'
import { ArrowUpRight, CalendarDays, CircleCheck, FileClock, Plus } from 'lucide-react'
import { Link } from 'react-router-dom'
import EventStatusBadge from '../../components/EventStatusBadge.jsx'
import { useAuth } from '../../hooks/useAuth.js'
import { fetchAllOrganizerEvents } from '../../services/organizerEventService.js'

function OrganizerOverviewPage() {
  const { user } = useAuth()
  const [result, setResult] = useState({ key: null, events: [], error: '' })
  const [attempt, setAttempt] = useState(0)

  useEffect(() => {
    let active = true
    fetchAllOrganizerEvents()
      .then((events) => { if (active) setResult({ key: attempt, events, error: '' }) })
      .catch((requestError) => { if (active) setResult({ key: attempt, events: [], error: requestError.userMessage || 'Your event summary could not be loaded.' }) })
    return () => { active = false }
  }, [attempt])

  const loading = result.key !== attempt
  const events = loading ? [] : result.events
  const error = loading ? '' : result.error
  const stats = [
    { label: 'Total events', value: events.length, icon: CalendarDays, note: 'Across all event pages' },
    { label: 'Published', value: events.filter((event) => event.status === 'published').length, icon: CircleCheck, note: 'Visible to the public' },
    { label: 'Drafts', value: events.filter((event) => event.status === 'draft').length, icon: FileClock, note: 'Ready for your next edit' },
    { label: 'Cancelled', value: events.filter((event) => event.status === 'cancelled').length, icon: CalendarDays, note: 'Kept in your history' },
  ]
  const recentEvents = [...events].sort((left, right) => new Date(right.updated_at || right.created_at) - new Date(left.updated_at || left.created_at)).slice(0, 5)

  return <section className="organizer-page">
    <div className="organizer-welcome"><div><span className="eyebrow">YOUR ORGANIZER SPACE</span><h1>Good to see you, {user?.name?.split(' ')[0] || 'organizer'}.</h1><p>Keep your events moving and make the next gathering happen.</p></div><Link className="button button-primary" to="/organizer/events/new"><Plus size={17} /> Create event</Link></div>
    {error && <div className="organizer-state organizer-error" role="alert"><p>{error}</p><button className="button button-outline" type="button" onClick={() => setAttempt((value) => value + 1)}>Try again</button></div>}
    {!error && <>
      <div className="organizer-stats">{stats.map(({ label, value, icon: Icon, note }) => <article className="organizer-stat-card" key={label}><div className="organizer-stat-top"><span>{label}</span><Icon size={17} /></div><strong>{loading ? '—' : value}</strong><small>{note}</small></article>)}</div>
      {loading ? <div className="organizer-state" role="status">Loading your events…</div> : <section className="organizer-panel"><div className="organizer-panel-heading"><div><span className="eyebrow">RECENT ACTIVITY</span><h2>Your latest events</h2></div><Link to="/organizer/events">All events <ArrowUpRight size={15} /></Link></div>
        {recentEvents.length === 0 ? <div className="organizer-empty"><h3>Start with your first event.</h3><p>Your event summary will show here once you save a draft.</p><Link className="button button-outline" to="/organizer/events/new">Create your first event <ArrowUpRight size={15} /></Link></div> : <div className="organizer-recent-list">{recentEvents.map((event) => <Link className="organizer-recent-row" to={`/organizer/events/${event.id}`} key={event.id}><span className="organizer-recent-icon"><CalendarDays size={17} /></span><span className="organizer-recent-info"><strong>{event.title}</strong><small>{event.city}, {event.country} · {event.start_at ? new Date(event.start_at).toLocaleDateString() : 'Date unavailable'}</small></span><EventStatusBadge status={event.status} /><ArrowUpRight className="organizer-row-arrow" size={16} /></Link>)}</div>}
      </section>}
    </>}
  </section>
}

export default OrganizerOverviewPage
