import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { fetchSuperAdminReservations } from '../../services/ticketingService.js'

function AdminReservationsPage() {
  const [params, setParams] = useSearchParams()
  const page = Math.max(1, Number(params.get('page')) || 1)
  const [state, setState] = useState({ key: '', payload: null, error: '' })
  const [retry, setRetry] = useState(0)

  useEffect(() => {
    let active = true
    fetchSuperAdminReservations(page)
      .then((payload) => { if (active) setState({ key: `${page}:${retry}`, payload, error: '' }) })
      .catch((error) => { if (active) setState({ key: `${page}:${retry}`, payload: null, error: error.userMessage || 'Reservations could not be loaded.' }) })
    return () => { active = false }
  }, [page, retry])

  const loading = state.key !== `${page}:${retry}`
  const rows = loading ? [] : state.payload?.data || []
  const change = (nextPage) => setParams((current) => { if (nextPage > 1) current.set('page', String(nextPage)); else current.delete('page'); return current })

  return <section className="admin-monitor-page">
    <header className="ticketing-page-heading"><span className="eyebrow">SYSTEM ACTIVITY</span><h1>Reservations</h1><p>Read-only inspection. The API returns a client ID, but no client name or email.</p></header>
    {loading && <p className="admin-monitor-state" role="status">Loading reservations…</p>}
    {!loading && state.error && <div className="ticketing-error" role="alert">{state.error} <button className="button button-outline" type="button" onClick={() => setRetry((value) => value + 1)}>Retry</button></div>}
    {!loading && !state.error && rows.length === 0 && <p className="ticketing-empty">No reservations found.</p>}
    {!loading && !state.error && rows.length > 0 && <div className="ticketing-table-wrap"><table className="ticketing-table"><thead><tr><th>Reference</th><th>Client ID</th><th>Event</th><th>Total</th><th>Status</th><th>Payment</th><th>Created</th><th /></tr></thead><tbody>{rows.map((row) => <tr key={row.id}><td>{row.reference}</td><td>#{row.user_id}</td><td>{row.event?.title || `Event #${row.event_id}`}</td><td>{Number(row.total).toFixed(2)}</td><td><span className={`ticket-status status-${row.status}`}>{row.status}</span></td><td><span className={`ticket-status status-${row.payment_status}`}>{row.payment_status}</span></td><td>{row.created_at ? new Date(row.created_at).toLocaleString() : '—'}</td><td><Link to={`/admin/reservations/${row.id}`}>Details</Link></td></tr>)}</tbody></table></div>}
    {!loading && !state.error && state.payload?.meta?.last_page > 1 && <nav className="ticketing-pagination" aria-label="Reservation pages"><button className="button button-outline" type="button" disabled={page <= 1} onClick={() => change(page - 1)}>Previous</button><span>Page {state.payload.meta.current_page || page} of {state.payload.meta.last_page} · {state.payload.meta.total} reservations</span><button className="button button-outline" type="button" disabled={page >= state.payload.meta.last_page} onClick={() => change(page + 1)}>Next</button></nav>}
  </section>
}

export default AdminReservationsPage
