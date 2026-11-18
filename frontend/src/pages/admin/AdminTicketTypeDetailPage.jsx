import { useEffect, useState } from 'react'
import { ArrowLeft } from 'lucide-react'
import { Link, useParams } from 'react-router-dom'
import { fetchSuperAdminTicketType } from '../../services/ticketingService.js'
import { formatEventAdminDate } from './adminEventUtils.js'

function AdminTicketTypeDetailPage() {
  const { id } = useParams()
  const [state, setState] = useState({ key: '', ticketType: null, error: '' })
  const [retry, setRetry] = useState(0)

  useEffect(() => {
    let active = true
    fetchSuperAdminTicketType(id)
      .then((ticketType) => { if (active) setState({ key: `${id}:${retry}`, ticketType, error: '' }) })
      .catch((error) => { if (active) setState({ key: `${id}:${retry}`, ticketType: null, error: error.response?.data?.message || error.userMessage || 'Ticket type details could not be loaded.' }) })
    return () => { active = false }
  }, [id, retry])

  const loading = state.key !== `${id}:${retry}`
  const ticketType = loading ? null : state.ticketType

  return <section className="admin-monitor-page">
    <Link className="admin-monitor-back" to="/admin/ticket-types"><ArrowLeft size={15} /> All ticket types</Link>
    {loading && <p className="admin-monitor-state" role="status">Loading ticket type…</p>}
    {!loading && state.error && <div className="ticketing-error" role="alert">{state.error} <button className="button button-outline" type="button" onClick={() => setRetry((value) => value + 1)}>Retry</button></div>}
    {ticketType && <>
      <header className="admin-monitor-heading"><div><span className="eyebrow">TICKET TYPE #{ticketType.id}</span><h1>{ticketType.name}</h1><p>{ticketType.event?.title || `Event #${ticketType.event_id}`}</p></div><span className={`admin-readonly-status ${ticketType.is_active ? 'is-active' : 'is-inactive'}`}>{ticketType.is_active ? 'Active' : 'Inactive'}</span></header>
      <article className="admin-monitor-detail-card"><span className="eyebrow">INVENTORY & SALES</span>{ticketType.description && <p className="admin-monitor-description">{ticketType.description}</p>}<dl className="admin-monitor-facts"><div><dt>Price</dt><dd>{Number(ticketType.price).toFixed(2)}</dd></div><div><dt>Quantity</dt><dd>{ticketType.quantity}</dd></div><div><dt>Available quantity</dt><dd>{ticketType.available_quantity}</dd></div><div><dt>Sales start</dt><dd>{formatEventAdminDate(ticketType.sales_start_at)}</dd></div><div><dt>Sales end</dt><dd>{formatEventAdminDate(ticketType.sales_end_at)}</dd></div><div><dt>Created</dt><dd>{formatEventAdminDate(ticketType.created_at)}</dd></div></dl></article>
      <p className="admin-monitor-readonly-note">Read-only inspection. Ticket type changes are not available in Super Admin APIs.</p>
    </>}
  </section>
}

export default AdminTicketTypeDetailPage
