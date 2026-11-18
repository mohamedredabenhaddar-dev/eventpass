import { useEffect, useRef, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { ArrowUpRight, CalendarDays, MapPin, Ticket } from 'lucide-react'
import { fetchMyReservations } from '../../services/ticketingService.js'

function formatDate(value) {
  if (!value) return 'Date unavailable'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? 'Date unavailable' : date.toLocaleString()
}

function MyTicketsPage() {
  const [params, setParams] = useSearchParams()
  const page = Math.max(1, Number(params.get('page')) || 1)
  const [result, setResult] = useState({ key: '', payload: null, tickets: [], error: '' })
  const [retry, setRetry] = useState(0)
  const requestRef = useRef({ key: null, promise: null })

  useEffect(() => {
    let active = true
    const key = `${page}:${retry}`
    let promise
    if (requestRef.current.key === key && requestRef.current.promise) {
      promise = requestRef.current.promise
    } else {
      promise = fetchMyReservations(page)
      requestRef.current = { key, promise }
    }

    promise.then((payload) => {
      const tickets = (payload.data || []).flatMap((reservation) => (reservation.items || []).flatMap((item) => (item.tickets || []).map((ticket) => ({
          ticket,
          item,
          reservation,
          event: reservation.event,
      }))))
      if (active) setResult({ key, payload, tickets, error: '' })
    }).catch((error) => {
      if (active) setResult({ key, payload: null, tickets: [], error: error.userMessage || 'Your tickets could not be loaded.' })
    }).finally(() => {
      if (requestRef.current.key === key) requestRef.current = { key: null, promise: null }
    })

    return () => { active = false }
  }, [page, retry])

  const loading = result.key !== `${page}:${retry}`
  const payload = loading ? null : result.payload
  const tickets = loading ? [] : result.tickets
  const changePage = (nextPage) => setParams((current) => {
    if (nextPage > 1) current.set('page', String(nextPage))
    else current.delete('page')
    return current
  })

  return <main className="ticketing-page client-tickets-page">
    <header className="ticketing-page-heading"><span className="eyebrow">YOUR EVENTPASS WALLET</span><h1>My tickets</h1><p>Your individual event tickets, validity, and current entry status.</p></header>
    {loading && <div className="ticketing-state" role="status">Loading your tickets…</div>}
    {!loading && result.error && <div className="ticketing-error" role="alert"><p>{result.error}</p><button className="button button-outline" type="button" onClick={() => setRetry((value) => value + 1)}>Try again</button></div>}
    {!loading && !result.error && tickets.length === 0 && <section className="ticketing-empty"><Ticket size={26} /><h2>No tickets yet</h2><p>Tickets from your reservations will appear here.</p><Link className="button button-primary" to="/events">Discover events</Link></section>}
    {!loading && !result.error && tickets.length > 0 && <div className="client-ticket-list">{tickets.map(({ ticket, item, reservation, event }) => {
      const valid = ticket.status === 'valid' && reservation.status === 'confirmed' && reservation.payment_status === 'paid'
      const ticketLabel = valid ? 'Valid' : reservation.status === 'pending_payment' ? 'Payment pending' : reservation.status === 'expired' ? 'Expired' : 'Cancelled'
      const location = [event?.venue_name, event?.city, event?.country].filter(Boolean).join(', ')
      return <article className="client-ticket-card" key={ticket.id}>
        <div className="client-ticket-accent"><Ticket size={19} /><span>EVENTPASS / ADMISSION</span></div>
        <div className="client-ticket-main"><span className="eyebrow">{event?.category || 'EVENT TICKET'}</span><h2>{event?.title || `Event #${reservation.event_id}`}</h2>
          <div className="client-ticket-facts"><p><CalendarDays size={15} />{formatDate(event?.start_at || reservation.event?.start_at)}</p><p><MapPin size={15} />{location || 'Venue details unavailable'}</p></div>
          <dl><div><dt>Ticket</dt><dd>{item.ticket_type?.name || 'Ticket type'}</dd></div><div><dt>Ticket reference</dt><dd>{ticket.reference}</dd></div><div><dt>Reservation</dt><dd>{reservation.reference}</dd></div></dl>
        </div>
        <div className="client-ticket-side"><span className={`ticket-status status-${valid ? 'valid' : 'cancelled'}`}>{ticketLabel}</span><span className="ticket-status">{ticket.access_state?.replace('_', ' ').toLowerCase() || 'not entered'}</span>{reservation.status === 'pending_payment' && <Link className="button button-primary button-small" to={`/client/checkout/${reservation.id}`}>Continue checkout</Link>}<Link className="button button-outline button-small" to={`/my-tickets/${reservation.id}/${ticket.id}`}>Open ticket <ArrowUpRight size={14} /></Link></div>
      </article>
    })}</div>}
    {!loading && !result.error && payload?.meta?.last_page > 1 && <nav className="ticketing-pagination" aria-label="Ticket pages"><button className="button button-outline" type="button" disabled={page <= 1} onClick={() => changePage(page - 1)}>Previous</button><span>Page {payload.meta.current_page} of {payload.meta.last_page}</span><button className="button button-outline" type="button" disabled={page >= payload.meta.last_page} onClick={() => changePage(page + 1)}>Next</button></nav>}
  </main>
}

export default MyTicketsPage
