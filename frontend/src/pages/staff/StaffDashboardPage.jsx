import { useCallback, useEffect, useState } from 'react'
import { ArrowRight, CalendarCheck2, ScanLine } from 'lucide-react'
import { Link } from 'react-router-dom'
import { fetchAllStaffAssignedEvents } from '../../services/ticketingService.js'
import { StaffEmpty, StaffError, StaffEventCard, StaffLoading, StaffPageHeading } from './StaffComponents.jsx'

function StaffDashboardPage() {
  const [events, setEvents] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try { setEvents(await fetchAllStaffAssignedEvents()) } catch (requestError) { setError(requestError.userMessage || 'Could not load your assigned events.') } finally { setLoading(false) }
  }, [])
  useEffect(() => {
    const timeout = setTimeout(load, 0)
    return () => clearTimeout(timeout)
  }, [load])

  return <section className="staff-page">
    <StaffPageHeading title="Staff overview" description="Your assigned events and access tools, all in one place." actions={<Link className="button button-primary" to="/staff/events">View assigned events <ArrowRight size={16} /></Link>} />
    {loading ? <StaffLoading label="Loading assigned events…" /> : error ? <StaffError message={error} onRetry={load} /> : <>
      <div className="staff-stat-grid"><article className="staff-stat-card"><span className="staff-stat-icon"><CalendarCheck2 size={19} /></span><span>Assigned events</span><strong>{events.length}</strong></article><article className="staff-stat-card"><span className="staff-stat-icon"><ScanLine size={19} /></span><span>Suspended or cancelled</span><strong>{events.filter((item) => ['suspended', 'cancelled'].includes(String(item.event?.status).toLowerCase())).length}</strong></article></div>
      <div className="staff-section-heading"><div><span className="staff-eyebrow">YOUR SCHEDULE</span><h2>Assigned events</h2></div><Link to="/staff/events">All events <ArrowRight size={15} /></Link></div>
      {events.length ? <div className="staff-event-grid">{events.slice(0, 3).map((item) => <StaffEventCard key={item.assignment?.id || item.event?.id} item={item} />)}</div> : <StaffEmpty />}
    </>}
  </section>
}

export default StaffDashboardPage
