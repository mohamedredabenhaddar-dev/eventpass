import { useEffect, useState } from 'react'
import { ArrowLeft, ChevronLeft, ChevronRight, LogIn, LogOut, RefreshCw } from 'lucide-react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { fetchOrganizerEvent } from '../../services/organizerEventService.js'
import { fetchOrganizerAccessReport } from '../../services/ticketingService.js'

function formatTimestamp(value) {
  if (!value) return '—'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString()
}

function OrganizerEventAccessPage() {
  const { eventId } = useParams()
  const [params, setParams] = useSearchParams()
  const page = Math.max(1, Number(params.get('page')) || 1)
  const [state, setState] = useState({ key: '', event: null, report: null, error: '' })
  const [retry, setRetry] = useState(0)

  useEffect(() => {
    let active = true
    Promise.all([fetchOrganizerEvent(eventId), fetchOrganizerAccessReport(eventId, page)])
      .then(([event, response]) => { if (active) setState({ key: `${eventId}:${page}:${retry}`, event, report: response.data, error: '' }) })
      .catch((error) => { if (active) setState({ key: `${eventId}:${page}:${retry}`, event: null, report: null, error: error.response?.data?.message || error.userMessage || 'Access report could not be loaded.' }) })
    return () => { active = false }
  }, [eventId, page, retry])

  const key = `${eventId}:${page}:${retry}`
  const loading = state.key !== key
  const event = loading ? null : state.event
  const report = loading ? null : state.report
  const stats = report?.statistics || {}
  const history = report?.history || {}
  const records = history.data || []
  const meta = history.meta
  const changePage = (nextPage) => setParams((current) => { if (nextPage > 1) current.set('page', String(nextPage)); else current.delete('page'); return current })

  return <section className="organizer-page organizer-access-page">
    <Link className="organizer-back-link" to={`/organizer/events/${eventId}`}><ArrowLeft size={15} /> Event overview</Link>
    <header className="organizer-page-heading"><div><span className="eyebrow">EVENT ACCESS</span><h1>{event?.title || 'Access reports'}</h1><p>Attendance totals and recorded entry and exit activity for this event.</p></div><button className="button button-outline" type="button" disabled={loading} onClick={() => setRetry((value) => value + 1)}><RefreshCw size={15} /> Refresh report</button></header>
    <nav className="ticketing-subnav organizer-access-nav" aria-label="Event management sections"><Link to={`/organizer/events/${eventId}`}>Overview</Link><Link to={`/organizer/events/${eventId}/ticket-types`}>Ticket types</Link><Link to={`/organizer/events/${eventId}/reservations`}>Reservations</Link><Link to={`/organizer/events/${eventId}/staff`}>Staff management</Link><Link className="active" to={`/organizer/events/${eventId}/access`}>Access reports</Link></nav>
    {loading && <div className="organizer-state" role="status">Loading access report…</div>}
    {!loading && state.error && <div className="organizer-state organizer-error" role="alert"><p>{state.error}</p><button className="button button-outline" type="button" onClick={() => setRetry((value) => value + 1)}>Try again</button></div>}
    {!loading && !state.error && <>
      <div className="organizer-access-stats">
        <article><span>Current attendees inside</span><strong>{stats.attendees_inside ?? 0}</strong><LogIn size={18} /></article>
        <article><span>Unique tickets entered</span><strong>{stats.unique_tickets_entered ?? 0}</strong><span className="organizer-access-hash">#</span></article>
        <article><span>Successful entries</span><strong>{stats.successful_entries ?? 0}</strong><LogIn size={18} /></article>
        <article><span>Successful exits</span><strong>{stats.successful_exits ?? 0}</strong><LogOut size={18} /></article>
      </div>
      <section className="organizer-panel"><div className="organizer-panel-heading"><div><span className="eyebrow">SCAN ACTIVITY</span><h2>Access history</h2></div><span className="organizer-access-count">{meta?.total ?? records.length} records</span></div>
        {records.length === 0 ? <div className="organizer-empty"><h3>No access activity yet.</h3><p>Staff scans recorded for this event will appear here.</p></div> : <div className="ticketing-table-wrap"><table className="ticketing-table organizer-access-table"><thead><tr><th>Ticket reference</th><th>Action</th><th>Gate</th><th>Staff member</th><th>Timestamp</th></tr></thead><tbody>{records.map((record) => <tr key={record.id}><td>{record.ticket_reference || '—'}</td><td><span className={`organizer-access-action ${record.action === 'ENTRY' ? 'is-entry' : 'is-exit'}`}>{record.action === 'ENTRY' ? <LogIn size={14} /> : <LogOut size={14} />}{record.action}</span></td><td>{record.gate || '—'}</td><td>{record.staff?.name || '—'}</td><td>{formatTimestamp(record.scanned_at)}</td></tr>)}</tbody></table></div>}
      </section>
      {meta?.last_page > 1 && <nav className="ticketing-pagination" aria-label="Access history pages"><button className="button button-outline" type="button" disabled={page <= 1} onClick={() => changePage(page - 1)}><ChevronLeft size={15} /> Previous</button><span>Page {meta.current_page || page} of {meta.last_page}</span><button className="button button-outline" type="button" disabled={page >= meta.last_page} onClick={() => changePage(page + 1)}>Next <ChevronRight size={15} /></button></nav>}
    </>}
  </section>
}

export default OrganizerEventAccessPage
