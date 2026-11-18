import { Link } from 'react-router-dom'

function ReservationSummary({ reservation }) {
  const event = reservation.event
  const format = (value) => Number(value || 0).toFixed(2)
  return <div className="reservation-summary">
    <div className="reservation-summary-head"><div><span className="eyebrow">RESERVATION</span><h1>{reservation.reference}</h1></div><div className="reservation-badges"><span className={`ticket-status status-${reservation.status}`}>{reservation.status}</span><span className={`ticket-status status-${reservation.payment_status}`}>{reservation.payment_status}</span></div></div>
    <section className="ticketing-panel reservation-event"><span className="eyebrow">EVENT</span><h2>{event?.title || `Event #${reservation.event_id}`}</h2>{event?.start_at && <p>{new Date(event.start_at).toLocaleString()}</p>}{event?.slug && <Link to={`/events/${event.slug}`}>View event</Link>}</section>
    <section className="ticketing-panel"><h2>Tickets</h2><div className="reservation-items">{(reservation.items || []).map((item) => <article className="reservation-item" key={item.id}><div><h3>{item.ticket_type?.name || `Ticket type #${item.ticket_type_id}`}</h3><p>{item.quantity} × {format(item.unit_price)} <span>Subtotal {format(item.subtotal)}</span></p>{item.tickets?.length > 0 && <ul className="individual-tickets">{item.tickets.map((ticket) => <li key={ticket.id}><span>{ticket.reference}</span><small>{ticket.status}</small></li>)}</ul>}</div></article>)}</div><div className="reservation-total"><span>Total</span><strong>{format(reservation.total)}</strong></div></section>
    {reservation.payment_status === 'unpaid' && <p className="ticketing-note">This demo reservation is confirmed. No payment has been processed.</p>}
  </div>
}

export default ReservationSummary
