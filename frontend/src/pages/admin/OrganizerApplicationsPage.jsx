import { useEffect, useMemo, useState } from 'react'
import { ArrowRight, ChevronLeft, ChevronRight, Search } from 'lucide-react'
import { Link, useSearchParams } from 'react-router-dom'
import { fetchSuperAdminOrganizerRequests } from '../../services/organizerRequestService.js'
import { adminRequestError, formatAdminDate } from './organizerApplicationUtils.js'

const EMPTY_APPLICATIONS = []

function OrganizerApplicationsPage() {
  const [params, setParams] = useSearchParams()
  const page = Math.max(1, Number(params.get('page')) || 1)
  const [statusFilter, setStatusFilter] = useState('')
  const [state, setState] = useState({ key: '', payload: null, error: '' })
  const [retry, setRetry] = useState(0)

  useEffect(() => {
    let active = true
    fetchSuperAdminOrganizerRequests(page)
      .then((payload) => { if (active) setState({ key: `${page}:${retry}`, payload, error: '' }) })
      .catch((error) => { if (active) setState({ key: `${page}:${retry}`, payload: null, error: adminRequestError(error, 'Organizer applications could not be loaded.') }) })
    return () => { active = false }
  }, [page, retry])

  const key = `${page}:${retry}`
  const loading = state.key !== key
  const payload = loading ? null : state.payload
  const rows = payload?.data ?? EMPTY_APPLICATIONS
  const visibleRows = useMemo(() => rows.filter((application) => !statusFilter || application.status === statusFilter), [rows, statusFilter])
  const changePage = (nextPage) => setParams((current) => { if (nextPage > 1) current.set('page', String(nextPage)); else current.delete('page'); return current })

  return <section className="admin-applications-page">
    <header className="admin-applications-heading"><div><span className="eyebrow">ORGANIZER REVIEW</span><h1>Organizer applications</h1><p>Review organization details and decide which pending applicants become organizers.</p></div><label className="admin-application-filter"><Search size={16} /><select value={statusFilter} onChange={(event) => setStatusFilter(event.target.value)} aria-label="Filter applications by status"><option value="">All statuses on page</option><option value="pending">Pending</option><option value="approved">Approved</option><option value="rejected">Rejected</option></select></label></header>
    <p className="admin-filter-note">Status filtering applies only to the current page; the API does not support server-side filters.</p>
    {loading && <div className="admin-applications-state" role="status"><span className="admin-spinner" />Loading applications…</div>}
    {!loading && state.error && <div className="admin-applications-error" role="alert"><p>{state.error}</p><button className="button button-outline" type="button" onClick={() => setRetry((value) => value + 1)}>Retry</button></div>}
    {!loading && !state.error && visibleRows.length === 0 && <div className="admin-applications-empty"><h2>{rows.length ? 'No applications match this status.' : 'No organizer applications yet.'}</h2><p>{rows.length ? 'Choose another status filter to see other records on this page.' : 'New submissions will appear here.'}</p></div>}
    {!loading && !state.error && visibleRows.length > 0 && <div className="admin-application-table-wrap"><table className="admin-application-table"><thead><tr><th>Applicant</th><th>Organization</th><th>Submitted</th><th>Status</th><th /></tr></thead><tbody>{visibleRows.map((application) => <tr key={application.id}><td><strong>{application.applicant?.name || 'Applicant'}</strong><small>{application.applicant?.email || 'Email unavailable'}</small></td><td>{application.organization_name}</td><td>{formatAdminDate(application.created_at)}</td><td><span className={`admin-application-status is-${application.status}`}>{application.status}</span></td><td><Link className="admin-view-application" to={`/admin/organizer-applications/${application.id}`}>Review <ArrowRight size={14} /></Link></td></tr>)}</tbody></table>
      <div className="admin-application-mobile-list">{visibleRows.map((application) => <article key={application.id} className="admin-application-mobile-card"><div><span className={`admin-application-status is-${application.status}`}>{application.status}</span><small>{formatAdminDate(application.created_at)}</small></div><h2>{application.organization_name}</h2><strong>{application.applicant?.name || 'Applicant'}</strong><p>{application.applicant?.email || 'Email unavailable'}</p><Link className="button button-small button-outline" to={`/admin/organizer-applications/${application.id}`}>Review application <ArrowRight size={14} /></Link></article>)}</div>
    </div>}
    {!loading && !state.error && payload?.meta?.last_page > 1 && <nav className="admin-application-pagination" aria-label="Organizer application pages"><button className="button button-outline button-small" type="button" disabled={page <= 1} onClick={() => changePage(page - 1)}><ChevronLeft size={15} /> Previous</button><span>Page {payload.meta.current_page || page} of {payload.meta.last_page} · {payload.meta.total} applications</span><button className="button button-outline button-small" type="button" disabled={page >= payload.meta.last_page} onClick={() => changePage(page + 1)}>Next <ChevronRight size={15} /></button></nav>}
  </section>
}

export default OrganizerApplicationsPage
