import { useCallback, useEffect, useState } from 'react'
import { ArrowLeft, ChevronLeft, ChevronRight, LogIn, LogOut } from 'lucide-react'
import { Link, useParams } from 'react-router-dom'
import { fetchAllStaffAssignedEvents, fetchStaffAccessReport } from '../../services/ticketingService.js'
import { StaffEmpty, StaffError, StaffLoading, StaffPageHeading } from './StaffComponents.jsx'
import { formatEventDate, staffErrorMessage } from './staffUtils.js'

function StaffAccessPage() {
  const { eventId } = useParams()
  const [event, setEvent] = useState(null)
  const [report, setReport] = useState(null)
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const load = useCallback(async (requestedPage = page) => {
    setLoading(true)
    setError('')
    try {
      const [events, accessReport] = await Promise.all([
        fetchAllStaffAssignedEvents(),
        fetchStaffAccessReport(eventId, requestedPage),
      ])
      setEvent(events.find((item) => String(item.event?.id) === String(eventId))?.event || null)
      setReport(accessReport.data)
    } catch (requestError) {
      setError(staffErrorMessage(requestError))
    } finally { setLoading(false) }
  }, [eventId, page])

  useEffect(() => {
    const timeout = setTimeout(() => load(page), 0)
    return () => clearTimeout(timeout)
  }, [load, page])

  if (loading) return <StaffLoading label="Loading access report…" />
  if (error) return <section className="staff-page"><StaffError message={error} onRetry={() => load(page)} /><Link className="staff-back-link" to={`/staff/events/${eventId}`}><ArrowLeft size={16} /> Event workspace</Link></section>
  const stats = report?.statistics || {}
  const history = report?.history || {}
  const entries = history.data || []
  const meta = history.meta

  return <section className="staff-page">
    <Link className="staff-back-link" to={`/staff/events/${eventId}`}><ArrowLeft size={16} /> Event workspace</Link>
    <StaffPageHeading eyebrow="EVENT ACCESS" title={event?.title || 'Access report'} description="Current attendance and the recorded ticket access history." actions={<Link className="button button-primary" to={`/staff/events/${eventId}/scanner`}>Open scanner</Link>} />
    <div className="staff-stat-grid staff-stat-grid-four">
      <article className="staff-stat-card"><span className="staff-stat-icon"><LogIn size={19} /></span><span>Currently inside</span><strong>{stats.attendees_inside ?? 0}</strong></article>
      <article className="staff-stat-card"><span className="staff-stat-icon"><span className="staff-stat-text-icon">#</span></span><span>Unique tickets entered</span><strong>{stats.unique_tickets_entered ?? 0}</strong></article>
      <article className="staff-stat-card"><span className="staff-stat-icon staff-entry-icon"><LogIn size={19} /></span><span>Successful entries</span><strong>{stats.successful_entries ?? 0}</strong></article>
      <article className="staff-stat-card"><span className="staff-stat-icon staff-exit-icon"><LogOut size={19} /></span><span>Successful exits</span><strong>{stats.successful_exits ?? 0}</strong></article>
    </div>
    <div className="staff-section-heading"><div><span className="staff-eyebrow">RECENT ACTIVITY</span><h2>Access history</h2></div><span className="staff-history-count">{meta?.total ?? entries.length} records</span></div>
    {entries.length ? <>
      <div className="staff-history-list">{entries.map((record) => <article className="staff-history-row" key={record.id}><span className={`staff-history-action ${record.action === 'ENTRY' ? 'is-entry' : 'is-exit'}`}>{record.action === 'ENTRY' ? <LogIn size={17} /> : <LogOut size={17} />}<strong>{record.action}</strong></span><div className="staff-history-ticket"><strong>{record.ticket_reference}</strong><span>{record.gate || 'Gate not specified'}</span></div><div className="staff-history-staff"><span>{record.staff?.name || 'Staff'}</span><time dateTime={record.scanned_at}>{formatEventDate(record.scanned_at)}</time></div></article>)}</div>
      {meta?.last_page > 1 && <nav className="staff-pagination" aria-label="Access history pages"><button className="button button-small button-outline" type="button" disabled={page <= 1} onClick={() => setPage((current) => current - 1)}><ChevronLeft size={16} /> Previous</button><span>Page {meta.current_page || page} of {meta.last_page}</span><button className="button button-small button-outline" type="button" disabled={page >= meta.last_page} onClick={() => setPage((current) => current + 1)}>Next <ChevronRight size={16} /></button></nav>}
    </> : <StaffEmpty>No access scans have been recorded for this event.</StaffEmpty>}
  </section>
}

export default StaffAccessPage
