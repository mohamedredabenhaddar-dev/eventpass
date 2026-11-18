import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { fetchMyPayments } from '../../services/ticketingService.js'

function PaymentHistoryPage() {
  const [params, setParams] = useSearchParams()
  const page = Math.max(1, Number(params.get('page')) || 1)
  const [state, setState] = useState({ key: '', data: null, error: '' })
  const [retry, setRetry] = useState(0)
  useEffect(() => { let active = true; fetchMyPayments(page).then((data) => { if (active) setState({ key: `${page}:${retry}`, data, error: '' }) }).catch((error) => { if (active) setState({ key: `${page}:${retry}`, data: null, error: error.userMessage || 'Payment history could not be loaded.' }) }); return () => { active = false } }, [page, retry])
  const loading = state.key !== `${page}:${retry}`
  const payload = loading ? null : state.data
  const rows = payload?.data || []
  const changePage = (next) => setParams((current) => { if (next > 1) current.set('page', String(next)); else current.delete('page'); return current })
  return <main className="ticketing-page"><header className="ticketing-page-heading"><span className="eyebrow">CLIENT ACCOUNT</span><h1>Payment history</h1><p>Only your demo payment attempts are shown. No real financial transactions are made.</p></header>
    {loading && <p className="ticketing-state" role="status">Loading payment history…</p>}
    {!loading && state.error && <div className="ticketing-error" role="alert"><p>{state.error}</p><button className="button button-outline" type="button" onClick={() => setRetry((value) => value + 1)}>Try again</button></div>}
    {!loading && !state.error && rows.length === 0 && <div className="ticketing-empty"><h2>No payment attempts yet</h2><p>Checkout attempts will appear here.</p><Link className="button button-primary" to="/events">Discover events</Link></div>}
    {!loading && !state.error && rows.length > 0 && <div className="reservation-list">{rows.map((payment) => <article className="ticketing-panel reservation-card" key={payment.id}><div><span className="eyebrow">{payment.payment_reference}</span><h2>Reservation {payment.reservation_reference}</h2><p>{(payment.paid_at || payment.created_at) ? new Date(payment.paid_at || payment.created_at).toLocaleString() : 'Date unavailable'}</p></div><div className="reservation-card-meta"><span className={`ticket-status status-${payment.status}`}>{payment.status}</span><strong>{payment.amount} {payment.currency}</strong><Link className="button button-outline button-small" to={`/client/payments/${payment.id}`}>View payment</Link></div></article>)}</div>}
    {!loading && payload?.meta?.last_page > 1 && <nav className="ticketing-pagination" aria-label="Payment history pages"><button className="button button-outline" disabled={page <= 1} onClick={() => changePage(page - 1)}>Previous</button><span>Page {payload.meta.current_page} of {payload.meta.last_page}</span><button className="button button-outline" disabled={page >= payload.meta.last_page} onClick={() => changePage(page + 1)}>Next</button></nav>}
  </main>
}

export default PaymentHistoryPage
