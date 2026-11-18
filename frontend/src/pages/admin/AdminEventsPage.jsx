import { useEffect, useState } from 'react'
import { ArrowRight, ChevronLeft, ChevronRight } from 'lucide-react'
import { Link, useSearchParams } from 'react-router-dom'
import EventStatusBadge from '../../components/EventStatusBadge.jsx'
import { fetchSuperAdminEvents } from '../../services/superAdminEventService.js'
import { adminEventError, formatEventAdminDate } from './adminEventUtils.js'

function AdminEventsPage() {
  const [params, setParams] = useSearchParams()
  const page = Math.max(1, Number(params.get('page')) || 1)
  const [state, setState] = useState({ key: '', payload: null, error: '' })
  const [retry, setRetry] = useState(0)

  useEffect(() => {
    let active = true
    fetchSuperAdminEvents(page)
      .then((payload) => { if (active) setState({ key: `${page}:${retry}`, payload, error: '' }) })
      .catch((error) => { if (active) setState({ key: `${page}:${retry}`, payload: null, error: adminEventError(error, 'Events could not be loaded.') }) })
    return () => { active = false }
  }, [page, retry])

  const key = `${page}:${retry}`
  const loading = state.key !== key
  const payload = loading ? null : state.payload
  const events = payload?.data || []
  const changePage = (nextPage) => setParams((current) => { if (nextPage > 1) current.set('page', String(nextPage)); else current.delete('page'); return current })

  return <section className="admin-events-page">
    <header className="admin-events-heading"><div><span className="eyebrow">EVENT ADMINISTRATION</span><h1>Events</h1><p>Review event details and administer event status across EventPass.</p></div><span className="admin-events-total">{payload?.meta?.total ?? '—'} events</span></header>
    <p className="admin-events-filter-note">The API does not provide search or status filters; this list is paginated and unfiltered.</p>
    {loading && <div className="admin-events-state" role="status"><span className="admin-events-spinner" />Loading events…</div>}
    {!loading && state.error && <div className="admin-events-error" role="alert"><p>{state.error}</p><button className="button button-outline" type="button" onClick={() => setRetry((value) => value + 1)}>Retry</button></div>}
    {!loading && !state.error && events.length === 0 && <div className="admin-events-empty"><h2>No events found.</h2><p>Events will appear here when available from the API.</p></div>}
    {!loading && !state.error && events.length > 0 && <div className="admin-events-table-wrap">
      <table className="admin-events-table"><thead><tr><th>Event</th><th>Organizer</th><th>City</th><th>Starts</th><th>Status</th><th>Capacity</th><th>Created</th><th /></tr></thead><tbody>{events.map((event) => <tr key={event.id}><td><strong>{event.title}</strong></td><td>{event.organizer?.name || '—'}</td><td>{event.city || '—'}</td><td>{formatEventAdminDate(event.start_at)}</td><td><EventStatusBadge status={event.status} /></td><td>{event.capacity ?? '—'}</td><td>{formatEventAdminDate(event.created_at)}</td><td><Link className="admin-event-view" to={`/admin/events/${event.id}`}>Details <ArrowRight size={14} /></Link></td></tr>)}</tbody></table>
      <div className="admin-events-mobile-list">{events.map((event) => <article className="admin-event-mobile-card" key={event.id}><div><EventStatusBadge status={event.status} /><small>{formatEventAdminDate(event.created_at)}</small></div><h2>{event.title}</h2><p>{event.organizer?.name || 'Organizer unavailable'} · {event.city || 'City unavailable'}</p><dl><div><dt>Starts</dt><dd>{formatEventAdminDate(event.start_at)}</dd></div><div><dt>Capacity</dt><dd>{event.capacity ?? '—'}</dd></div></dl><Link className="button button-small button-outline" to={`/admin/events/${event.id}`}>Event details <ArrowRight size={14} /></Link></article>)}</div>
    </div>}
    {!loading && !state.error && payload?.meta?.last_page > 1 && <nav className="admin-events-pagination" aria-label="Event pages"><button className="button button-outline button-small" type="button" disabled={page <= 1} onClick={() => changePage(page - 1)}><ChevronLeft size={15} /> Previous</button><span>Page {payload.meta.current_page || page} of {payload.meta.last_page} · {payload.meta.total} events</span><button className="button button-outline button-small" type="button" disabled={page >= payload.meta.last_page} onClick={() => changePage(page + 1)}>Next <ChevronRight size={15} /></button></nav>}
  </section>
}

export default AdminEventsPage
