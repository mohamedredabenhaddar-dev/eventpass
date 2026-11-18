import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import ReservationSummary from '../../components/ReservationSummary.jsx'
import { fetchOrganizerReservation } from '../../services/ticketingService.js'

function OrganizerReservationDetailPage() {
  const { eventId, reservationId } = useParams(); const [result, setResult] = useState({ key: '', data: null, error: '' }); const [retry, setRetry] = useState(0)
  useEffect(() => { let active = true; fetchOrganizerReservation(eventId, reservationId).then((data) => { if (active) setResult({ key: `${eventId}:${reservationId}:${retry}`, data, error: '' }) }).catch((err) => { if (active) setResult({ key: `${eventId}:${reservationId}:${retry}`, data: null, error: err.userMessage || 'Reservation could not be loaded.' }) }); return () => { active = false } }, [eventId, reservationId, retry])
  const key = `${eventId}:${reservationId}:${retry}`; const loading = result.key !== key; const data = loading ? null : result.data; const error = loading ? '' : result.error
  return <section className="organizer-page"><Link className="organizer-back-link" to={`/organizer/events/${eventId}/reservations`}>← Event reservations</Link>{error && <div className="ticketing-error" role="alert">{error} <button className="button button-outline" onClick={() => setRetry((n) => n + 1)}>Retry</button></div>}{loading && <p role="status">Loading reservation…</p>}{data && <ReservationSummary reservation={data} />}</section>
}
export default OrganizerReservationDetailPage
