import { useCallback, useEffect, useState } from 'react'
import { ArrowLeft, ClipboardList, ScanLine } from 'lucide-react'
import { Link, useParams } from 'react-router-dom'
import { fetchAllStaffAssignedEvents } from '../../services/ticketingService.js'
import { StaffError, StaffLoading, StaffPageHeading } from './StaffComponents.jsx'
import { eventStatusClass, formatEventDate, staffErrorMessage } from './staffUtils.js'

function StaffEventDetailPage() {
  const { eventId } = useParams()
  const [item, setItem] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const assigned = await fetchAllStaffAssignedEvents()
      setItem(assigned.find((entry) => String(entry.event?.id) === String(eventId)) || null)
    } catch (requestError) { setError(staffErrorMessage(requestError)) } finally { setLoading(false) }
  }, [eventId])
  useEffect(() => {
    const timeout = setTimeout(load, 0)
    return () => clearTimeout(timeout)
  }, [load])

  if (loading) return <StaffLoading label="Loading event workspace…" />
  if (error) return <section className="staff-page"><StaffError message={error} onRetry={load} /></section>
  if (!item?.event) return <section className="staff-page"><StaffError message="This event is not in your assigned events." /><Link className="button button-outline" to="/staff/events"><ArrowLeft size={16} /> Back to assigned events</Link></section>

  const event = item.event
  const location = [event.venue_name, event.address, event.city, event.country].filter(Boolean).join(', ')
  return <section className="staff-page">
    <Link className="staff-back-link" to="/staff/events"><ArrowLeft size={16} /> Assigned events</Link>
    <StaffPageHeading eyebrow="EVENT WORKSPACE" title={event.title} description="Use the scanner to record entry and exit, or review this event’s access activity." />
    <article className="staff-event-summary"><div className="staff-event-card-top"><span className={eventStatusClass(event.status)}>{event.status}</span><span>Assigned {formatEventDate(item.assignment?.assigned_at, { timeStyle: undefined })}</span></div><dl><div><dt>Starts</dt><dd>{formatEventDate(event.start_at)}</dd></div><div><dt>Ends</dt><dd>{formatEventDate(event.end_at)}</dd></div><div><dt>Venue</dt><dd>{location || 'Location not provided'}</dd></div></dl></article>
    <div className="staff-tool-grid"><Link className="staff-tool-card" to={`/staff/events/${event.id}/scanner`}><span><ScanLine size={21} /></span><div><h2>Open QR scanner</h2><p>Scan a backend-issued ticket credential for entry or exit.</p></div><ArrowLeft className="staff-tool-arrow" size={18} /></Link><Link className="staff-tool-card" to={`/staff/events/${event.id}/access`}><span><ClipboardList size={21} /></span><div><h2>Access report</h2><p>Review attendance totals and paginated scan history.</p></div><ArrowLeft className="staff-tool-arrow" size={18} /></Link></div>
  </section>
}

export default StaffEventDetailPage
