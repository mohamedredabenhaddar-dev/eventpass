import { useEffect, useState } from 'react'
import { ArrowLeft } from 'lucide-react'
import { Link, useParams } from 'react-router-dom'
import ReservationSummary from '../../components/ReservationSummary.jsx'
import { fetchSuperAdminReservation } from '../../services/ticketingService.js'

function AdminReservationDetailPage() {
  const { id } = useParams()
  const [result, setResult] = useState({ key: '', reservation: null, error: '' })
  const [retry, setRetry] = useState(0)
  useEffect(() => {
    let active = true
    fetchSuperAdminReservation(id)
      .then((reservation) => { if (active) setResult({ key: `${id}:${retry}`, reservation, error: '' }) })
      .catch((error) => { if (active) setResult({ key: `${id}:${retry}`, reservation: null, error: error.response?.data?.message || error.userMessage || 'Reservation could not be loaded.' }) })
    return () => { active = false }
  }, [id, retry])

  const loading = result.key !== `${id}:${retry}`
  const reservation = loading ? null : result.reservation
  const error = loading ? '' : result.error
  return <section className="admin-monitor-page"><Link className="admin-monitor-back" to="/admin/reservations"><ArrowLeft size={15} /> All reservations</Link>{error && <div className="ticketing-error" role="alert">{error} <button className="button button-outline" type="button" onClick={() => setRetry((value) => value + 1)}>Retry</button></div>}{loading && <p className="admin-monitor-state" role="status">Loading reservation…</p>}{reservation && <><div className="admin-reservation-meta"><span>Client ID<strong>#{reservation.user_id}</strong></span><span>Created<strong>{reservation.created_at ? new Date(reservation.created_at).toLocaleString() : '—'}</strong></span><span>Event status<strong>{reservation.event?.status || '—'}</strong></span></div><ReservationSummary reservation={reservation} /></>}</section>
}

export default AdminReservationDetailPage
