import { useEffect, useRef, useState } from 'react'
import { QRCodeSVG } from 'qrcode.react'
import { ArrowLeft, CalendarDays, MapPin, RefreshCw, Ticket } from 'lucide-react'
import { Link, useParams } from 'react-router-dom'
import ConfirmationDialog from '../../components/ConfirmationDialog.jsx'
import { fetchReservation, fetchTicketQrCredential } from '../../services/ticketingService.js'

function formatDate(value) {
  if (!value) return 'Date unavailable'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? 'Date unavailable' : date.toLocaleString()
}

function MyTicketDetailPage() {
  const { reservationId, ticketId } = useParams()
  const [result, setResult] = useState({ key: '', reservation: null, ticket: null, item: null, event: null, error: '' })
  const [credential, setCredential] = useState({ key: '', token: '', error: '' })
  const [retry, setRetry] = useState(0)
  const [confirmReissue, setConfirmReissue] = useState(false)
  const reservationRequestRef = useRef({ key: null, promise: null })
  const requestRef = useRef({ key: null, promise: null })

  useEffect(() => {
    let active = true
    const key = `${reservationId}:${ticketId}:${retry}`
    let promise
    if (reservationRequestRef.current.key === key && reservationRequestRef.current.promise) {
      promise = reservationRequestRef.current.promise
    } else {
      promise = fetchReservation(reservationId).then((reservation) => {
        const ticketItem = (reservation.items || []).find((item) => (item.tickets || []).some((ticket) => String(ticket.id) === String(ticketId)))
        const ticket = ticketItem?.tickets?.find((candidate) => String(candidate.id) === String(ticketId))
        if (!ticket || !ticketItem) throw new Error('This ticket was not found in your reservation.')
        return { reservation, ticket, item: ticketItem, event: reservation.event || null }
      })
      reservationRequestRef.current = { key, promise }
    }

    promise.then(({ reservation, ticket, item, event }) => {
      if (active) setResult({ key, reservation, ticket, item, event, error: '' })
    }).catch((error) => {
      if (active) setResult({ key, reservation: null, ticket: null, item: null, event: null, error: error.response?.status === 404 ? 'This ticket could not be found in your account.' : error.userMessage || error.message || 'Ticket details could not be loaded.' })
    }).finally(() => {
      if (reservationRequestRef.current.key === key) reservationRequestRef.current = { key: null, promise: null }
    })

    return () => { active = false }
  }, [reservationId, ticketId, retry])

  const resultKey = `${reservationId}:${ticketId}:${retry}`
  const loading = result.key !== resultKey
  const reservation = loading ? null : result.reservation
  const ticket = loading ? null : result.ticket
  const item = loading ? null : result.item
  const event = loading ? null : result.event
  const valid = ticket?.status === 'valid' && reservation?.status === 'confirmed'
  const credentialKey = `${resultKey}:credential:${retry}`

  useEffect(() => {
    if (!ticket || !valid) return undefined
    let active = true
    let promise
    if (requestRef.current.key === credentialKey && requestRef.current.promise) {
      promise = requestRef.current.promise
    } else {
      promise = fetchTicketQrCredential(ticket.id)
      requestRef.current = { key: credentialKey, promise }
    }
    promise.then((data) => {
      if (active) setCredential({ key: credentialKey, token: data.qr_token, error: '' })
    }).catch((error) => {
      if (active) setCredential({ key: credentialKey, token: '', error: error.response?.data?.message || error.userMessage || 'The QR credential could not be retrieved.' })
    }).finally(() => {
      if (requestRef.current.key === credentialKey) requestRef.current = { key: null, promise: null }
    })
    return () => { active = false }
  }, [ticket, valid, credentialKey])

  const credentialLoading = valid && credential.key !== credentialKey
  const token = credential.key === credentialKey ? credential.token : ''
  const credentialError = credential.key === credentialKey ? credential.error : ''
  const location = [event?.venue_name, event?.address, event?.city, event?.country].filter(Boolean).join(', ')
  const reissue = () => {
    setCredential({ key: '', token: '', error: '' })
    setConfirmReissue(false)
    setRetry((value) => value + 1)
  }

  if (loading) return <main className="ticketing-page"><p className="ticketing-state" role="status">Loading ticket…</p></main>
  if (!ticket || !reservation) return <main className="ticketing-page"><div className="ticketing-error" role="alert"><p>{result.error}</p><Link className="button button-outline" to="/my-tickets">Back to My tickets</Link></div></main>

  return <main className="ticketing-page ticket-detail-page">
    <Link className="ticketing-back" to="/my-tickets"><ArrowLeft size={15} /> My tickets</Link>
    <article className="digital-ticket">
      <header className="digital-ticket-header"><div className="digital-ticket-brand"><Ticket size={19} /><span>EVENTPASS / ADMISSION</span></div><span className={`ticket-status status-${valid ? 'valid' : 'cancelled'}`}>{valid ? 'Valid ticket' : 'Cancelled'}</span></header>
      <div className="digital-ticket-body"><section className="digital-ticket-event"><span className="eyebrow">{event?.category || 'YOUR EVENT'}</span><h1>{event?.title || `Event #${reservation.event_id}`}</h1><p><CalendarDays size={16} />{formatDate(event?.start_at || reservation.event?.start_at)}</p><p><MapPin size={16} />{location || 'Venue details are unavailable for this event.'}</p></section>
        <div className="digital-ticket-stub"><span className="eyebrow">TICKET TYPE</span><h2>{item.ticket_type?.name || 'Event ticket'}</h2><dl><div><dt>Ticket reference</dt><dd>{ticket.reference}</dd></div><div><dt>Reservation</dt><dd>{reservation.reference}</dd></div><div><dt>Validity</dt><dd>{valid ? 'Valid' : 'Cancelled'}</dd></div><div><dt>Access state</dt><dd>{ticket.access_state?.replace('_', ' ').toLowerCase() || 'not entered'}</dd></div></dl></div></div>
      {valid && <section className="ticket-qr-section" aria-labelledby="ticket-qr-title"><h2 id="ticket-qr-title">Your entry QR</h2><p>The same QR is used for ENTRY and EXIT. Show it to event staff at the gate.</p>
        {credentialLoading && <div className="ticket-qr-state" role="status">Preparing your secure QR…</div>}
        {credentialError && <div className="ticketing-error" role="alert"><p>{credentialError}</p><button className="button button-outline" type="button" onClick={() => { setCredential({ key: '', token: '', error: '' }); setRetry((value) => value + 1) }}>Try again</button></div>}
        {!credentialLoading && !credentialError && token && <><div className="qr-code-frame"><QRCodeSVG value={token} size={256} level="M" includeMargin title={`Admission QR for ${ticket.reference}`} /></div><strong className="qr-ticket-reference">{ticket.reference}</strong><button className="button button-outline" type="button" onClick={() => setConfirmReissue(true)}><RefreshCw size={15} /> Re-issue QR</button></>}
      </section>}
      {!valid && <p className="ticketing-note ticket-invalid-note">This ticket is cancelled or no longer valid. QR credentials are unavailable.</p>}
    </article>
    {confirmReissue && <ConfirmationDialog title="Re-issue this QR?" message="A new QR credential will be created. Any previously issued QR for this ticket will stop working immediately." confirmLabel="Re-issue QR" cancelLabel="Keep current QR" onCancel={() => setConfirmReissue(false)} onConfirm={reissue} />}
  </main>
}

export default MyTicketDetailPage
