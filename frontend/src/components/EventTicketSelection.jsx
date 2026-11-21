import { Minus, Plus, Ticket } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../hooks/useAuth.js'
import { createClientReservation, fetchPublicTicketTypes } from '../services/ticketingService.js'

const EMPTY_TICKET_TYPES = []

function cents(value) {
  const [whole = '0', fraction = ''] = String(value ?? '0').split('.')
  return (Number.parseInt(whole, 10) || 0) * 100 + Number.parseInt(fraction.padEnd(2, '0').slice(0, 2), 10)
}

function money(value) {
  const amount = Number(value)
  return Number.isFinite(amount) ? amount.toFixed(2) : '0.00'
}

function EventTicketSelection({ event }) {
  const { user } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [result, setResult] = useState({ key: null, types: [], error: '' })
  const [attempt, setAttempt] = useState(0)
  const [quantities, setQuantities] = useState({})
  const [submitting, setSubmitting] = useState(false)
  const [submitError, setSubmitError] = useState('')
  const [validationErrors, setValidationErrors] = useState([])

  useEffect(() => {
    let active = true
    fetchPublicTicketTypes(event.id)
      .then((types) => { if (active) setResult({ key: `${event.id}:${attempt}`, types, error: '' }) })
      .catch((requestError) => { if (active) setResult({ key: `${event.id}:${attempt}`, types: [], error: requestError.userMessage || 'Ticket options could not be loaded.' }) })
    return () => { active = false }
  }, [event.id, attempt])

  const requestKey = `${event.id}:${attempt}`
  const loading = result.key !== requestKey
  const error = loading ? '' : result.error
  const ticketTypes = loading ? EMPTY_TICKET_TYPES : result.types
  const selected = useMemo(() => ticketTypes
    .map((ticketType) => ({ ticketType, quantity: quantities[ticketType.id] || 0 }))
    .filter((item) => item.quantity > 0), [ticketTypes, quantities])

  const totalCents = selected.reduce((total, item) => total + cents(item.ticketType.price) * item.quantity, 0)
  const estimatedTotal = `${Math.floor(totalCents / 100)}.${String(totalCents % 100).padStart(2, '0')}`

  const setQuantity = (ticketType, value) => {
    const parsed = Number.parseInt(value, 10)
    const quantity = Number.isFinite(parsed) ? Math.min(ticketType.available_quantity, Math.max(0, parsed)) : 0
    setQuantities((current) => ({ ...current, [ticketType.id]: quantity }))
    setSubmitError('')
    setValidationErrors([])
  }

  const reserve = async () => {
    if (!user) {
      navigate('/login', { state: { from: { pathname: location.pathname } } })
      return
    }
    if (user.role !== 'client') {
      setSubmitError('Reservations are available to client accounts.')
      return
    }
    if (selected.length === 0) {
      setSubmitError('Choose at least one ticket to continue.')
      return
    }

    setSubmitting(true)
    setSubmitError('')
    setValidationErrors([])
    try {
      const reservation = await createClientReservation(event.id, selected.map(({ ticketType, quantity }) => ({
        ticket_type_id: ticketType.id,
        quantity,
      })))
      navigate(`/client/checkout/${reservation.id}`, { state: { created: true } })
    } catch (requestError) {
      const errors = requestError.response?.data?.errors
      if (errors) setValidationErrors(Object.values(errors).flat())
      setSubmitError(requestError.response?.data?.message || requestError.userMessage || 'Your reservation could not be completed.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <section className="ticket-selection-panel" aria-labelledby="ticket-selection-title">
      <div className="ticket-selection-heading">
        <div><span className="eyebrow">SAVE YOUR PLACE</span><h2 id="ticket-selection-title">Choose your tickets</h2></div><Ticket size={20} />
      </div>
      {loading && <div className="ticketing-state" role="status">Loading available tickets...</div>}
      {error && <div className="ticketing-error" role="alert">
        <p>{error}</p>
        <button className="button button-outline button-small" type="button" onClick={() => setAttempt((value) => value + 1)}>Try again</button>
      </div>}
      {!loading && !error && ticketTypes.length === 0 && <div className="ticketing-empty">There are no tickets available right now.</div>}
      {!loading && !error && ticketTypes.length > 0 && (
        <div className="ticket-type-options">
          {ticketTypes.map((ticketType) => {
            const quantity = quantities[ticketType.id] || 0
            const available = ticketType.available_quantity
            const max = Math.min(1000, available)
            return (
              <article className="ticket-type-option" key={ticketType.id}>
                <div className="ticket-type-copy">
                  <h3>{ticketType.name}</h3>{ticketType.description && <p>{ticketType.description}</p>}
                  <small>{available} available</small>
                </div>
                <div className="ticket-type-purchase">
                  <strong>{money(ticketType.price)}</strong>
                  <div className="quantity-stepper" aria-label={`Quantity for ${ticketType.name}`}>
                    <button type="button" aria-label={`Remove one ${ticketType.name}`} onClick={() => setQuantity(ticketType, quantity - 1)} disabled={quantity <= 0}><Minus size={14} /></button>
                    <input type="number" min="0" max={max} step="1" value={quantity} aria-label={`${ticketType.name} quantity`} onChange={(change) => setQuantity(ticketType, change.target.value)} />
                    <button type="button" aria-label={`Add one ${ticketType.name}`} onClick={() => setQuantity(ticketType, quantity + 1)} disabled={quantity >= max}><Plus size={14} /></button>
                  </div>
                </div>
              </article>
            )
          })}
        </div>
      )}
      <div className="ticket-order-summary">
        <span>Estimated total <small>Calculated from current ticket prices</small></span>
        <strong>{estimatedTotal}</strong>
      </div>
      {submitError && <div className="ticketing-error" role="alert">
        <p>{submitError}</p>
        {validationErrors.length > 0 && <ul>{validationErrors.map((message, index) => <li key={`${message}-${index}`}>{message}</li>)}</ul>}
      </div>}
      {user?.role && user.role !== 'client' && <p className="ticketing-note">Sign in with a client account to reserve tickets.</p>}
      <button className="button button-primary ticket-reserve-button" type="button" disabled={submitting || loading || selected.length === 0} onClick={reserve}>
        {submitting ? 'Reserving...' : !user ? 'Sign in to reserve' : user.role === 'client' ? 'Reserve tickets' : 'Client account required'}
      </button>
      <p className="ticketing-demo-note">A temporary ticket hold will be created. Checkout is DEMO only; no money is charged.</p>
    </section>
  )
}

export default EventTicketSelection