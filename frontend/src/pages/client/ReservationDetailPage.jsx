import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import ConfirmationDialog from '../../components/ConfirmationDialog.jsx'
import ReservationSummary from '../../components/ReservationSummary.jsx'
import { cancelReservation, fetchReservation } from '../../services/ticketingService.js'

function ReservationDetailPage() {
  const { id } = useParams()
  const [result, setResult] = useState({ key: '', reservation: null, error: '' })
  const [confirm, setConfirm] = useState(false)
  const [busy, setBusy] = useState(false)
  const [retry, setRetry] = useState(0)
  useEffect(() => { let active = true; fetchReservation(id).then((data) => { if (active) setResult({ key: `${id}:${retry}`, reservation: data, error: '' }) }).catch((err) => { if (active) setResult({ key: `${id}:${retry}`, reservation: null, error: err.response?.data?.message || err.userMessage || 'Reservation could not be loaded.' }) }); return () => { active = false } }, [id, retry])
  const loading = result.key !== `${id}:${retry}`
  const reservation = loading ? null : result.reservation
  const error = loading ? '' : result.error
  const canCancel = ['confirmed', 'pending_payment'].includes(reservation?.status) && reservation.payment_status !== 'paid' && reservation.event?.start_at && new Date(reservation.event.start_at) > new Date()
  const cancel = async () => { setBusy(true); try { await cancelReservation(id); setRetry((n) => n + 1); setConfirm(false) } catch (err) { setResult((current) => ({ ...current, error: err.response?.data?.message || err.userMessage || 'Reservation could not be cancelled.' })) } finally { setBusy(false) } }
  if (loading) return <main className="ticketing-page"><p className="ticketing-state" role="status">Loading reservation…</p></main>
  if (!reservation) return <main className="ticketing-page"><div className="ticketing-error" role="alert"><p>{error}</p><button className="button button-outline" onClick={() => setRetry((n) => n + 1)}>Try again</button><Link to="/client/reservations">My reservations</Link></div></main>
  return <main className="ticketing-page"><Link className="ticketing-back" to="/client/reservations">← My reservations</Link>{error && <p className="ticketing-error" role="alert">{error}</p>}<ReservationSummary reservation={reservation} />{canCancel && <button className="button button-danger" type="button" onClick={() => setConfirm(true)}>Cancel reservation</button>}{confirm && <ConfirmationDialog title="Cancel this reservation?" message="The reservation and its tickets will be cancelled, and inventory will be restored." confirmLabel="Cancel reservation" busy={busy} onCancel={() => setConfirm(false)} onConfirm={cancel} />}</main>
}
export default ReservationDetailPage
