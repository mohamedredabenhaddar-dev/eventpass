import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { fetchPayment, simulatePaymentFailure, simulatePaymentSuccess } from '../../services/ticketingService.js'

function PaymentDetailPage() {
  const { paymentId } = useParams()
  const [payment, setPayment] = useState(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  useEffect(() => {
    let active = true
    fetchPayment(paymentId).then((data) => { if (active) setPayment(data) })
      .catch((requestError) => { if (active) setError(requestError.response?.data?.message || requestError.userMessage || 'Payment details could not be loaded.') })
      .finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [paymentId])

  const simulate = async (success) => {
    setBusy(true); setError('')
    try { setPayment(success ? await simulatePaymentSuccess(paymentId) : await simulatePaymentFailure(paymentId)) }
    catch (requestError) { setError(requestError.response?.data?.message || requestError.userMessage || 'The simulation could not be completed.') }
    finally { setBusy(false) }
  }

  if (loading) return <main className="ticketing-page"><p className="ticketing-state" role="status">Loading demo payment…</p></main>
  if (!payment) return <main className="ticketing-page"><div className="ticketing-error" role="alert">{error}<p><Link to="/client/payments">Payment history</Link></p></div></main>
  const pending = payment.status === 'pending'
  const retryAllowed = payment.status === 'failed' && payment.retry_allowed === true
  const reservationStatus = payment.reservation_status || 'unknown'
  const formatStatus = (value) => value.replaceAll('_', ' ')
  return <main className="ticketing-page"><header className="ticketing-page-heading"><span className="eyebrow">DEMO TRANSACTION</span><h1>Payment simulation</h1><p><strong>DEMO PAYMENT — NO REAL MONEY WILL BE CHARGED.</strong></p></header>
    <section className="ticketing-panel"><dl className="payment-facts"><div><dt>Payment reference</dt><dd>{payment.payment_reference}</dd></div><div><dt>Reservation</dt><dd>{payment.reservation_reference}</dd></div><div><dt>Reservation status</dt><dd><span className={`ticket-status status-${reservationStatus}`}>{formatStatus(reservationStatus)}</span></dd></div><div><dt>Payment status</dt><dd><span className={`ticket-status status-${payment.status}`}>{formatStatus(payment.status)}</span></dd></div><div><dt>Amount</dt><dd>{payment.amount} {payment.currency}</dd></div><div><dt>Mode</dt><dd>DEMO</dd></div></dl>
      {error && <p className="ticketing-error" role="alert">{error}</p>}
      {pending && <div className="demo-payment-actions"><p>Choose a simulation outcome. Neither action contacts a payment provider.</p><button className="button button-primary" type="button" disabled={busy} onClick={() => simulate(true)}>{busy ? 'Processing…' : 'Simulate successful payment'}</button><button className="button button-outline" type="button" disabled={busy} onClick={() => simulate(false)}>Simulate failed payment</button></div>}
      {payment.status === 'succeeded' && <div><p>Payment simulation succeeded. Your reservation is confirmed and its tickets are active.</p><Link className="button button-primary" to="/my-tickets">Open my tickets</Link></div>}
      {retryAllowed && <div><p>The demo payment failed. Your reservation is still awaiting payment and has not expired.</p><Link className="button button-primary" to={`/client/checkout/${payment.reservation_id}`}>Retry checkout</Link></div>}
      {payment.status === 'failed' && !retryAllowed && <p>The demo payment failed. Checkout cannot be retried because this reservation is no longer eligible for payment.</p>}
      {payment.status === 'expired' && <p>This payment attempt expired and cannot be completed.</p>}
    </section><Link to="/client/payments">Payment history</Link>
  </main>
}

export default PaymentDetailPage
