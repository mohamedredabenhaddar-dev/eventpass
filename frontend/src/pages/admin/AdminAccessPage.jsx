import { useCallback, useEffect, useState } from 'react'
import { Activity, ChevronLeft, ChevronRight, LogIn, LogOut, RefreshCw, Users } from 'lucide-react'
import { fetchAllSuperAdminEvents } from '../../services/superAdminEventService.js'
import { fetchSuperAdminEventAccess, fetchSuperAdminEventStaff } from '../../services/superAdminMonitoringService.js'
import { monitoringDate, monitoringError } from './adminMonitoringUtils.js'

function AdminAccessPage() {
  const [eventsState, setEventsState] = useState({ key: -1, events: [], error: '' })
  const [eventsRetry, setEventsRetry] = useState(0)
  const [selectedEventId, setSelectedEventId] = useState('')
  const [accessPage, setAccessPage] = useState(1)
  const [staffPage, setStaffPage] = useState(1)
  const [refresh, setRefresh] = useState(0)
  const [accessState, setAccessState] = useState({ key: '', report: null, error: '' })
  const [staffState, setStaffState] = useState({ key: '', payload: null, error: '' })

  useEffect(() => {
    let active = true
    fetchAllSuperAdminEvents()
      .then((events) => {
        if (!active) return
        setEventsState({ key: eventsRetry, events, error: '' })
        setSelectedEventId((current) => current || (events[0] ? String(events[0].id) : ''))
      })
      .catch((error) => { if (active) setEventsState({ key: eventsRetry, events: [], error: monitoringError(error, 'The event selector could not be loaded.') }) })
    return () => { active = false }
  }, [eventsRetry])

  useEffect(() => {
    if (!selectedEventId) return undefined
    let active = true
    const key = `${selectedEventId}:${accessPage}:${refresh}`
    fetchSuperAdminEventAccess(selectedEventId, accessPage)
      .then((report) => { if (active) setAccessState({ key, report, error: '' }) })
      .catch((error) => { if (active) setAccessState({ key, report: null, error: monitoringError(error, 'The access report could not be loaded.') }) })
    return () => { active = false }
  }, [selectedEventId, accessPage, refresh])

  useEffect(() => {
    if (!selectedEventId) return undefined
    let active = true
    const key = `${selectedEventId}:${staffPage}:${refresh}`
    fetchSuperAdminEventStaff(selectedEventId, staffPage)
      .then((payload) => { if (active) setStaffState({ key, payload, error: '' }) })
      .catch((error) => { if (active) setStaffState({ key, payload: null, error: monitoringError(error, 'Assigned staff could not be loaded.') }) })
    return () => { active = false }
  }, [selectedEventId, staffPage, refresh])

  const eventLoading = eventsState.key !== eventsRetry
  const selectedEvent = eventsState.events.find((event) => String(event.id) === selectedEventId)
  const accessKey = `${selectedEventId}:${accessPage}:${refresh}`
  const staffKey = `${selectedEventId}:${staffPage}:${refresh}`
  const accessLoading = Boolean(selectedEventId) && accessState.key !== accessKey
  const staffLoading = Boolean(selectedEventId) && staffState.key !== staffKey
  const report = accessLoading ? null : accessState.report
  const stats = report?.statistics || {}
  const history = report?.history || {}
  const accessRows = history.data || []
  const accessMeta = history.meta
  const staffPayload = staffLoading ? null : staffState.payload
  const staffRows = staffPayload?.data || []
  const staffMeta = staffPayload?.meta

  const changeEvent = useCallback((event) => {
    setSelectedEventId(event.target.value)
    setAccessPage(1)
    setStaffPage(1)
  }, [])

  return <section className="admin-monitor-page admin-access-page">
    <header className="admin-monitor-heading"><div><span className="eyebrow">EVENT ENTRY</span><h1>Access monitoring</h1><p>Review attendance, recorded access history, and read-only staff assignments by event.</p></div><button className="button button-outline" type="button" disabled={!selectedEventId || accessLoading || staffLoading} onClick={() => setRefresh((value) => value + 1)}><RefreshCw size={15} /> Refresh report</button></header>
    <div className="admin-access-selector"><label htmlFor="admin-access-event">Select event</label>{eventLoading ? <span role="status">Loading events…</span> : eventsState.error ? <div className="admin-monitor-error" role="alert">{eventsState.error}<button className="button button-outline button-small" type="button" onClick={() => setEventsRetry((value) => value + 1)}>Retry</button></div> : eventsState.events.length ? <select id="admin-access-event" value={selectedEventId} onChange={changeEvent}>{eventsState.events.map((event) => <option key={event.id} value={event.id}>{event.title} · {event.status}</option>)}</select> : <p>No events are available to monitor.</p>}</div>
    {selectedEvent && <div className="admin-access-event-context"><span className={`event-status-badge status-${selectedEvent.status}`}>{selectedEvent.status}</span><strong>{selectedEvent.title}</strong><span>{selectedEvent.city || 'City unavailable'} · {selectedEvent.start_at ? monitoringDate(selectedEvent.start_at) : 'Date unavailable'}</span></div>}
    {selectedEventId && <>
      <section className="admin-access-section"><div className="admin-access-section-heading"><div><span className="eyebrow">LIVE TOTALS</span><h2>Attendance</h2></div></div>
        {accessLoading && <p className="admin-monitor-state" role="status">Loading access statistics…</p>}
        {!accessLoading && accessState.error && <div className="ticketing-error" role="alert">{accessState.error}<button className="button button-outline" type="button" onClick={() => setRefresh((value) => value + 1)}>Retry</button></div>}
        {!accessLoading && !accessState.error && <div className="admin-access-stat-grid"><article><span>Attendees currently inside</span><strong>{stats.attendees_inside ?? 0}</strong><Users size={18} /></article><article><span>Unique tickets entered</span><strong>{stats.unique_tickets_entered ?? 0}</strong><span className="admin-access-hash">#</span></article><article><span>Successful entries</span><strong>{stats.successful_entries ?? 0}</strong><LogIn size={18} /></article><article><span>Successful exits</span><strong>{stats.successful_exits ?? 0}</strong><LogOut size={18} /></article></div>}
      </section>
      <section className="admin-access-section"><div className="admin-access-section-heading"><div><span className="eyebrow">SCAN ACTIVITY</span><h2>Access history</h2></div><span>{accessMeta?.total ?? accessRows.length} records</span></div>
        {accessLoading && <p className="admin-monitor-state" role="status">Loading access history…</p>}
        {!accessLoading && accessState.error && <div className="ticketing-error" role="alert">{accessState.error}<button className="button button-outline" type="button" onClick={() => setRefresh((value) => value + 1)}>Retry</button></div>}
        {!accessLoading && !accessState.error && accessRows.length === 0 && <p className="ticketing-empty">No access history for this event.</p>}
        {!accessLoading && !accessState.error && accessRows.length > 0 && <div className="ticketing-table-wrap"><table className="ticketing-table admin-monitor-table"><thead><tr><th>Ticket reference</th><th>Action</th><th>Gate</th><th>Staff</th><th>Timestamp</th></tr></thead><tbody>{accessRows.map((row) => <tr key={row.id}><td>{row.ticket_reference || '—'}</td><td><span className={`admin-access-action ${row.action === 'ENTRY' ? 'is-entry' : 'is-exit'}`}>{row.action === 'ENTRY' ? <LogIn size={14} /> : <LogOut size={14} />}{row.action}</span></td><td>{row.gate || '—'}</td><td>{row.staff?.name || '—'}</td><td>{monitoringDate(row.scanned_at)}</td></tr>)}</tbody></table></div>}
        {!accessLoading && !accessState.error && accessMeta?.last_page > 1 && <nav className="ticketing-pagination" aria-label="Access history pages"><button className="button button-outline" type="button" disabled={accessPage <= 1} onClick={() => setAccessPage((page) => page - 1)}><ChevronLeft size={14} /> Previous</button><span>Page {accessMeta.current_page || accessPage} of {accessMeta.last_page}</span><button className="button button-outline" type="button" disabled={accessPage >= accessMeta.last_page} onClick={() => setAccessPage((page) => page + 1)}>Next <ChevronRight size={14} /></button></nav>}
      </section>
      <section className="admin-access-section"><div className="admin-access-section-heading"><div><span className="eyebrow">EVENT TEAM</span><h2>Assigned staff</h2></div><span>{staffMeta?.total ?? staffRows.length} assignments</span></div>
        {staffLoading && <p className="admin-monitor-state" role="status">Loading assigned staff…</p>}
        {!staffLoading && staffState.error && <div className="ticketing-error" role="alert">{staffState.error}<button className="button button-outline" type="button" onClick={() => setRefresh((value) => value + 1)}>Retry</button></div>}
        {!staffLoading && !staffState.error && staffRows.length === 0 && <p className="ticketing-empty">No staff are assigned to this event.</p>}
        {!staffLoading && !staffState.error && staffRows.length > 0 && <div className="ticketing-table-wrap"><table className="ticketing-table admin-monitor-table"><thead><tr><th>Staff member</th><th>Email</th><th>Assignment ID</th><th>Assigned</th></tr></thead><tbody>{staffRows.map((assignment) => <tr key={assignment.id}><td>{assignment.staff?.name || '—'}</td><td>{assignment.staff?.email || '—'}</td><td>#{assignment.id}</td><td>{monitoringDate(assignment.created_at)}</td></tr>)}</tbody></table></div>}
        {!staffLoading && !staffState.error && staffMeta?.last_page > 1 && <nav className="ticketing-pagination" aria-label="Staff assignment pages"><button className="button button-outline" type="button" disabled={staffPage <= 1} onClick={() => setStaffPage((page) => page - 1)}><ChevronLeft size={14} /> Previous</button><span>Page {staffMeta.current_page || staffPage} of {staffMeta.last_page}</span><button className="button button-outline" type="button" disabled={staffPage >= staffMeta.last_page} onClick={() => setStaffPage((page) => page + 1)}>Next <ChevronRight size={14} /></button></nav>}
      </section>
    </>}
    {!selectedEventId && !eventLoading && !eventsState.error && <div className="admin-monitor-empty"><Activity size={25} /><p>Select an event to inspect access and staff assignments.</p></div>}
  </section>
}

export default AdminAccessPage
