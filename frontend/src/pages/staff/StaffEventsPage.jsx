import { useCallback, useEffect, useState } from 'react'
import { ChevronLeft, ChevronRight } from 'lucide-react'
import { fetchStaffAssignedEvents } from '../../services/ticketingService.js'
import { StaffEmpty, StaffError, StaffEventCard, StaffLoading, StaffPageHeading } from './StaffComponents.jsx'
import { staffErrorMessage } from './staffUtils.js'

function StaffEventsPage() {
  const [result, setResult] = useState(null)
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const load = useCallback(async (requestedPage = page) => {
    setLoading(true)
    setError('')
    try { setResult(await fetchStaffAssignedEvents(requestedPage)) } catch (requestError) { setError(staffErrorMessage(requestError)) } finally { setLoading(false) }
  }, [page])
  useEffect(() => {
    const timeout = setTimeout(() => load(page), 0)
    return () => clearTimeout(timeout)
  }, [load, page])
  const meta = result?.meta

  return <section className="staff-page">
    <StaffPageHeading title="Assigned events" description="Events assigned to your staff account, including their current status." />
    {loading ? <StaffLoading label="Loading assigned events…" /> : error ? <StaffError message={error} onRetry={() => load(page)} /> : result?.data?.length ? <>
      <div className="staff-event-grid">{result.data.map((item) => <StaffEventCard key={item.assignment?.id || item.event?.id} item={item} />)}</div>
      {meta?.last_page > 1 && <nav className="staff-pagination" aria-label="Assigned event pages"><button className="button button-small button-outline" type="button" disabled={page <= 1} onClick={() => setPage((current) => current - 1)}><ChevronLeft size={16} /> Previous</button><span>Page {meta.current_page || page} of {meta.last_page}</span><button className="button button-small button-outline" type="button" disabled={page >= meta.last_page} onClick={() => setPage((current) => current + 1)}>Next <ChevronRight size={16} /></button></nav>}
    </> : <StaffEmpty />}
  </section>
}

export default StaffEventsPage
