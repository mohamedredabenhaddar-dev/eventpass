import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { fetchMyReservations } from '../../services/ticketingService.js'

function MyReservationsPage() {
  const [params, setParams] = useSearchParams()
  const page = Math.max(1, Number(params.get('page')) || 1)
  const [state, setState] = useState({ key: '', data: null, error: '' })
  const [retry, setRetry] = useState(0)
  useEffect(() => { let active = true; fetchMyReservations(page).then((data) => { if (active) setState({ key: `${page}:${retry}`, data, error: '' }) }).catch((error) => { if (active) setState({ key: `${page}:${retry}`, data: null, error: error.userMessage || 'Reservations could not be loaded.' }) }); return () => { active = false } }, [page, retry])
  const loading = state.key !== `${page}:${retry}`
  const payload = loading ? null : state.data
  const rows = payload?.data || []
  const changePage = (next) => setParams((current) => { if (next > 1) current.set('page', String(next)); else current.delete('page'); return current })
  return <main className="ticketing-page"><header className="ticketing-page-heading"><span className="eyebrow">YOUR TICKETS</span><h1>My reservations</h1><p>Find your reservation details and individual ticket references.</p></header>
    {loading && <p className="ticketing-state" role="status">Loading reservations…</p>}
    {!loading && state.error && <div className="ticketing-error" role="alert"><p>{state.error}</p><button className="button button-outline" type="button" onClick={() => setRetry((n) => n + 1)}>Try again</button></div>}
    {!loading && !state.error && rows.length === 0 && <div className="ticketing-empty"><h2>No reservations yet</h2><p>When you reserve tickets, they will appear here.</p><Link className="button button-primary" to="/events">Discover events</Link></div>}
    {!loading && !state.error && rows.length > 0 && <div className="reservation-list">{rows.map((item) => <article className="ticketing-panel reservation-card" key={item.id}><div><span className="eyebrow">{item.reference}</span><h2>{item.event?.title || `Event #${item.event_id}`}</h2><p>{item.event?.start_at ? new Date(item.event.start_at).toLocaleString() : 'Event date unavailable'}</p></div><div className="reservation-card-meta"><span className={`ticket-status status-${item.status}`}>{item.status}</span><span className={`ticket-status status-${item.payment_status}`}>{item.payment_status}</span><strong>{Number(item.total).toFixed(2)}</strong><Link className="button button-outline button-small" to={`/client/reservations/${item.id}`}>View details</Link></div></article>)}</div>}
    {!loading && payload?.meta?.last_page > 1 && <nav className="ticketing-pagination" aria-label="Reservation pages"><button className="button button-outline" disabled={page <= 1} onClick={() => changePage(page - 1)}>Previous</button><span>Page {payload.meta.current_page} of {payload.meta.last_page}</span><button className="button button-outline" disabled={page >= payload.meta.last_page} onClick={() => changePage(page + 1)}>Next</button></nav>}
  </main>
}
export default MyReservationsPage
