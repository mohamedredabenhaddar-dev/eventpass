import { useEffect, useMemo, useState } from 'react'
import { ArrowUpRight, CalendarDays, Plus, Search } from 'lucide-react'
import { Link, useSearchParams } from 'react-router-dom'
import EventStatusBadge from '../../components/EventStatusBadge.jsx'
import OrganizerEventActions from '../../components/OrganizerEventActions.jsx'
import { fetchOrganizerEvents } from '../../services/organizerEventService.js'

const EMPTY_EVENTS = []

function OrganizerEventsPage() {
  const [params, setParams] = useSearchParams()
  const page = Math.max(1, Number(params.get('page')) || 1)
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState('')
  const [result, setResult] = useState({ key: null, payload: null, error: '' })
  const [attempt, setAttempt] = useState(0)

  useEffect(() => {
    let active = true
    fetchOrganizerEvents(page)
      .then((payload) => { if (active) setResult({ key: `${page}:${attempt}`, payload, error: '' }) })
      .catch((requestError) => { if (active) setResult({ key: `${page}:${attempt}`, payload: null, error: requestError.userMessage || 'Your events could not be loaded.' }) })
    return () => { active = false }
  }, [page, attempt])

  const currentKey = `${page}:${attempt}`
  const loading = result.key !== currentKey
  const payload = loading ? null : result.payload
  const error = loading ? '' : result.error
  const events = payload?.data ?? EMPTY_EVENTS
  const visibleEvents = useMemo(() => events.filter((event) => {
    const term = search.trim().toLocaleLowerCase()
    const matchesSearch = !term || [event.title, event.category, event.city, event.country, event.venue_name].some((value) => value?.toLocaleLowerCase().includes(term))
    return matchesSearch && (!statusFilter || event.status === statusFilter)
  }), [events, search, statusFilter])
  const changePage = (nextPage) => setParams((current) => { if (nextPage > 1) current.set('page', String(nextPage)); else current.delete('page'); return current })
  const updateEvent = (updated) => setResult((current) => current.payload ? { ...current, payload: { ...current.payload, data: current.payload.data.map((event) => event.id === updated.id ? updated : event) } } : current)

  return <section className="organizer-page">
    <div className="organizer-page-heading"><div><span className="eyebrow">YOUR EVENT LIBRARY</span><h1>My events</h1><p>Manage the gatherings you’ve created.</p></div><Link className="button button-primary" to="/organizer/events/new"><Plus size={17} /> Create event</Link></div>
    <div className="organizer-list-toolbar"><label className="organizer-search"><Search size={17} /><input type="search" value={search} onChange={(change) => setSearch(change.target.value)} placeholder="Search this page’s events" aria-label="Search loaded events" /></label><select className="organizer-status-filter" value={statusFilter} onChange={(change) => setStatusFilter(change.target.value)} aria-label="Filter by event status"><option value="">All statuses</option><option value="draft">Draft</option><option value="published">Published</option><option value="suspended">Suspended</option><option value="cancelled">Cancelled</option></select></div>
    <p className="organizer-filter-note">Search and status filters apply to the currently loaded page.</p>
    {loading && <div className="organizer-state" role="status">Loading your events…</div>}
    {error && <div className="organizer-state organizer-error" role="alert"><p>{error}</p><button className="button button-outline" type="button" onClick={() => setAttempt((value) => value + 1)}>Try again</button></div>}
    {!loading && !error && visibleEvents.length === 0 && <div className="organizer-empty"><CalendarDays size={24} /><h2>{events.length ? 'No events match your filters.' : 'No events yet.'}</h2><p>{events.length ? 'Try another search or status.' : 'Create a draft to start planning your next gathering.'}</p>{events.length ? <button className="button button-outline" type="button" onClick={() => { setSearch(''); setStatusFilter('') }}>Clear filters</button> : <Link className="button button-primary" to="/organizer/events/new"><Plus size={16} /> Create event</Link>}</div>}
    {!loading && !error && visibleEvents.length > 0 && <div className="organizer-event-list">{visibleEvents.map((event) => <article className="organizer-event-card" key={event.id}>
      <div className="organizer-event-card-main"><div className="organizer-event-symbol"><CalendarDays size={20} /></div><div className="organizer-event-card-copy"><div className="organizer-event-title-row"><Link to={`/organizer/events/${event.id}`}><h2>{event.title}</h2><ArrowUpRight size={15} /></Link><EventStatusBadge status={event.status} /></div><p>{event.category} <span>·</span> {[event.venue_name, event.city, event.country].filter(Boolean).join(', ')}</p><small>{event.start_at ? new Date(event.start_at).toLocaleString() : 'Date unavailable'}{event.capacity ? ` · Capacity ${event.capacity}` : ''}</small></div></div>
      <div className="organizer-event-card-actions"><Link className="organizer-view-link" to={`/organizer/events/${event.id}`}>View event</Link><Link className="organizer-view-link" to={`/organizer/events/${event.id}/ticket-types`}>Ticket types</Link><Link className="organizer-view-link" to={`/organizer/events/${event.id}/reservations`}>Reservations</Link><Link className="organizer-view-link" to={`/organizer/events/${event.id}/staff`}>Staff</Link><Link className="organizer-view-link" to={`/organizer/events/${event.id}/access`}>Access report</Link><OrganizerEventActions event={event} onUpdated={updateEvent} /></div>
    </article>)}</div>}
    {!loading && !error && payload?.meta?.last_page > 1 && <nav className="organizer-pagination" aria-label="Event pages"><button className="button button-outline" type="button" disabled={page <= 1} onClick={() => changePage(page - 1)}>Previous</button><span>Page {payload.meta.current_page} of {payload.meta.last_page} · {payload.meta.total} total</span><button className="button button-outline" type="button" disabled={page >= payload.meta.last_page} onClick={() => changePage(page + 1)}>Next</button></nav>}
  </section>
}

export default OrganizerEventsPage
