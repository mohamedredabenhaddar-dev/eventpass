import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { fetchSuperAdminTicketTypes } from '../../services/ticketingService.js'

function AdminTicketTypesPage() {
  const [params, setParams] = useSearchParams()
  const page = Math.max(1, Number(params.get('page')) || 1)
  const [state, setState] = useState({ key: '', payload: null, error: '' })
  const [retry, setRetry] = useState(0)

  useEffect(() => {
    let active = true
    fetchSuperAdminTicketTypes(page)
      .then((payload) => { if (active) setState({ key: `${page}:${retry}`, payload, error: '' }) })
      .catch((error) => { if (active) setState({ key: `${page}:${retry}`, payload: null, error: error.userMessage || 'Ticket types could not be loaded.' }) })
    return () => { active = false }
  }, [page, retry])

  const loading = state.key !== `${page}:${retry}`
  const rows = loading ? [] : state.payload?.data || []
  const change = (nextPage) => setParams((current) => { if (nextPage > 1) current.set('page', String(nextPage)); else current.delete('page'); return current })

  return <section className="admin-monitor-page">
    <header className="ticketing-page-heading"><span className="eyebrow">SYSTEM INVENTORY</span><h1>Ticket types</h1><p>Read-only monitoring of ticket prices, availability, and sales windows across events.</p></header>
    {loading && <p className="admin-monitor-state" role="status">Loading ticket types…</p>}
    {!loading && state.error && <div className="ticketing-error" role="alert">{state.error} <button className="button button-outline" type="button" onClick={() => setRetry((value) => value + 1)}>Retry</button></div>}
    {!loading && !state.error && rows.length === 0 && <p className="ticketing-empty">No ticket types found.</p>}
    {!loading && !state.error && rows.length > 0 && <div className="ticketing-table-wrap"><table className="ticketing-table"><thead><tr><th>Ticket</th><th>Event</th><th>Price</th><th>Inventory</th><th>Sales window</th><th>Status</th><th /></tr></thead><tbody>{rows.map((row) => <tr key={row.id}><td><Link to={`/admin/ticket-types/${row.id}`}>{row.name}</Link><small>{row.description || '—'}</small></td><td>{row.event?.title || `Event #${row.event_id}`}</td><td>{Number(row.price).toFixed(2)}</td><td>{row.available_quantity} / {row.quantity} available</td><td>{row.sales_start_at ? new Date(row.sales_start_at).toLocaleString() : 'Any time'} – {row.sales_end_at ? new Date(row.sales_end_at).toLocaleString() : 'No end'}</td><td><span className={`admin-readonly-status ${row.is_active ? 'is-active' : 'is-inactive'}`}>{row.is_active ? 'Active' : 'Inactive'}</span></td><td><Link to={`/admin/ticket-types/${row.id}`}>Details</Link></td></tr>)}</tbody></table></div>}
    {!loading && !state.error && state.payload?.meta?.last_page > 1 && <nav className="ticketing-pagination" aria-label="Ticket type pages"><button className="button button-outline" type="button" disabled={page <= 1} onClick={() => change(page - 1)}>Previous</button><span>Page {state.payload.meta.current_page || page} of {state.payload.meta.last_page} · {state.payload.meta.total} ticket types</span><button className="button button-outline" type="button" disabled={page >= state.payload.meta.last_page} onClick={() => change(page + 1)}>Next</button></nav>}
  </section>
}

export default AdminTicketTypesPage
