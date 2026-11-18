import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import ReservationSummary from '../../components/ReservationSummary.jsx'
import { createPaymentAttempt, fetchReservation } from '../../services/ticketingService.js'

function CheckoutPage() {
  const { reservationId } = useParams()
  const navigate = useNavigate()
  const [reservation, setReservation] = useState(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  useEffect(() => {
    let active = true
    fetchReservation(reservationId).then((data) => { if (active) setReservation(data) })
      .catch((requestError) => { if (active) setError(requestError.response?.data?.message || requestError.userMessage || 'Checkout could not be loaded.') })
      .finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [reservationId])

  const continueDemo = async () => {
    setBusy(true); setError('')
    try {
      const payment = await createPaymentAttempt(reservationId)
      navigate(`/client/payments/${payment.id}`, { state: { startDemo: true } })
    } catch (requestError) {
      setError(requestError.response?.data?.message || requestError.userMessage || 'A demo payment attempt could not be started.')
    } finally { setBusy(false) }
  }

  if (loading) return <main className="ticketing-page"><p className="ticketing-state" role="status">Loading checkout…</p></main>
  if (!reservation) return <main className="ticketing-page"><div className="ticketing-error" role="alert">{error}<p><Link to="/client/reservations">My reservations</Link></p></div></main>
  if (reservation.status !== 'pending_payment') return <main className="ticketing-page"><h1>Checkout unavailable</h1><p>This reservation is {reservation.status}.</p><Link className="button button-outline" to={`/client/reservations/${reservation.id}`}>View reservation</Link></main>
  return <main className="ticketing-page"><header className="ticketing-page-heading"><span className="eyebrow">SECURE YOUR PLACE</span><h1>Checkout</h1><p>Your ticket inventory is temporarily held while this reservation is awaiting payment.</p></header><ReservationSummary reservation={reservation} />
    <section className="ticketing-panel"><h2>Payment simulation</h2><p><strong>DEMO PAYMENT — NO REAL MONEY WILL BE CHARGED.</strong></p><p>No card information is collected. The backend will record a simulated payment attempt for the total shown above, using its configured currency.</p>{error && <p className="ticketing-error" role="alert">{error}</p>}<button className="button button-primary" type="button" disabled={busy} onClick={continueDemo}>{busy ? 'Starting…' : 'Continue to demo payment'}</button></section>
  </main>
}

export default CheckoutPage
