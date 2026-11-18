import { useEffect, useState } from 'react'
import { ArrowLeft, Check, X } from 'lucide-react'
import { Link, useParams } from 'react-router-dom'
import ConfirmationDialog from '../../components/ConfirmationDialog.jsx'
import { approveSuperAdminOrganizerRequest, fetchSuperAdminOrganizerRequest, rejectSuperAdminOrganizerRequest } from '../../services/organizerRequestService.js'
import { adminRequestError, formatAdminDate } from './organizerApplicationUtils.js'

function OrganizerApplicationDetailPage() {
  const { id } = useParams()
  const [state, setState] = useState({ key: '', application: null, error: '' })
  const [retry, setRetry] = useState(0)
  const [reviewBusy, setReviewBusy] = useState(false)
  const [reviewError, setReviewError] = useState('')
  const [notice, setNotice] = useState('')
  const [confirmApprove, setConfirmApprove] = useState(false)
  const [rejectOpen, setRejectOpen] = useState(false)
  const [rejectionReason, setRejectionReason] = useState('')

  useEffect(() => {
    let active = true
    fetchSuperAdminOrganizerRequest(id)
      .then((application) => { if (active) setState({ key: `${id}:${retry}`, application, error: '' }) })
      .catch((error) => { if (active) setState({ key: `${id}:${retry}`, application: null, error: adminRequestError(error, 'Application details could not be loaded.') }) })
    return () => { active = false }
  }, [id, retry])

  const key = `${id}:${retry}`
  const loading = state.key !== key
  const application = loading ? null : state.application

  const handleApprove = async () => {
    if (!application || reviewBusy) return
    setReviewBusy(true)
    setReviewError('')
    setNotice('')
    try {
      await approveSuperAdminOrganizerRequest(id)
      setConfirmApprove(false)
      setNotice('Application approved. The applicant has been promoted to organizer.')
      setRetry((value) => value + 1)
    } catch (error) {
      setReviewError(adminRequestError(error, 'The application could not be approved.'))
      setConfirmApprove(false)
    } finally { setReviewBusy(false) }
  }

  const handleReject = async (event) => {
    event.preventDefault()
    const reason = rejectionReason.trim()
    if (!reason) {
      setReviewError('A rejection reason is required.')
      return
    }
    if (reason.length > 2000) {
      setReviewError('The rejection reason must be 2,000 characters or fewer.')
      return
    }
    setReviewBusy(true)
    setReviewError('')
    setNotice('')
    try {
      await rejectSuperAdminOrganizerRequest(id, reason)
      setRejectOpen(false)
      setRejectionReason('')
      setNotice('Application rejected. The applicant remains a client.')
      setRetry((value) => value + 1)
    } catch (error) {
      setReviewError(adminRequestError(error, 'The application could not be rejected.'))
    } finally { setReviewBusy(false) }
  }

  return <section className="admin-applications-page admin-application-detail-page">
    <Link className="admin-back-link" to="/admin/organizer-applications"><ArrowLeft size={15} /> Organizer applications</Link>
    {loading && <div className="admin-applications-state" role="status"><span className="admin-spinner" />Loading application…</div>}
    {!loading && state.error && <div className="admin-applications-error" role="alert"><p>{state.error}</p><button className="button button-outline" type="button" onClick={() => setRetry((value) => value + 1)}>Retry</button></div>}
    {!loading && !state.error && application && <>
      <header className="admin-applications-heading"><div><span className="eyebrow">APPLICATION #{application.id}</span><h1>{application.organization_name}</h1><p>Submitted {formatAdminDate(application.created_at)}</p></div><span className={`admin-application-status is-${application.status}`}>{application.status}</span></header>
      {notice && <p className="admin-review-notice" role="status">{notice}</p>}
      {reviewError && <p className="admin-review-error" role="alert">{reviewError}</p>}
      <div className="admin-application-detail-grid">
        <article className="admin-application-detail-card"><span className="eyebrow">APPLICANT</span><h2>{application.applicant?.name || 'Applicant'}</h2><a href={`mailto:${application.applicant?.email}`}>{application.applicant?.email}</a><dl><div><dt>Organization</dt><dd>{application.organization_name}</dd></div><div><dt>Application date</dt><dd>{formatAdminDate(application.created_at)}</dd></div><div><dt>Contact email</dt><dd>{application.contact_email}</dd></div><div><dt>Contact phone</dt><dd>{application.contact_phone}</dd></div>{application.website && <div><dt>Website</dt><dd>{application.website}</dd></div>}</dl></article>
        <article className="admin-application-detail-card admin-application-message"><span className="eyebrow">APPLICATION DETAILS</span><h2>Organization description</h2><p>{application.description || 'No description was provided.'}</p></article>
      </div>
      <article className="admin-application-detail-card admin-review-card"><div><span className="eyebrow">REVIEW</span><h2>Review status</h2></div><span className={`admin-application-status is-${application.status}`}>{application.status}</span>
        {application.reviewed_at ? <dl><div><dt>Reviewed at</dt><dd>{formatAdminDate(application.reviewed_at)}</dd></div>{application.reviewer?.name && <div><dt>Reviewed by</dt><dd>{application.reviewer.name}</dd></div>}{application.rejection_reason && <div className="admin-rejection-reason"><dt>Rejection reason</dt><dd>{application.rejection_reason}</dd></div>}</dl> : <p>This application has not been reviewed yet.</p>}
      </article>
      {application.status === 'pending' && <div className="admin-review-actions"><p>Approval promotes this applicant from client to organizer. Rejection keeps their account as a client.</p><div><button className="button button-outline admin-reject-button" type="button" onClick={() => { setReviewError(''); setRejectOpen(true) }}><X size={15} /> Reject</button><button className="button button-primary" type="button" onClick={() => { setReviewError(''); setConfirmApprove(true) }}><Check size={15} /> Approve application</button></div></div>}
      {application.status !== 'pending' && <div className="admin-reviewed-lock"><strong>This application has already been reviewed.</strong><span>Review actions are no longer available.</span></div>}
    </>}
    {confirmApprove && <ConfirmationDialog title="Approve organizer application?" message={`Approving ${application?.organization_name || 'this application'} will change ${application?.applicant?.name || 'the applicant'}’s role from client to organizer.`} confirmLabel="Approve and promote" busy={reviewBusy} onCancel={() => setConfirmApprove(false)} onConfirm={handleApprove} />}
    {rejectOpen && <div className="confirmation-backdrop admin-reject-backdrop"><section className="confirmation-dialog admin-reject-dialog" role="dialog" aria-modal="true" aria-labelledby="reject-application-title"><button className="admin-dialog-close" type="button" aria-label="Close rejection form" disabled={reviewBusy} onClick={() => setRejectOpen(false)}><X size={18} /></button><span className="eyebrow">REJECT APPLICATION</span><h2 id="reject-application-title">Add a rejection reason</h2><p>The applicant will remain a client. This reason is stored with the review record.</p><form onSubmit={handleReject}><label>Rejection reason<textarea value={rejectionReason} onChange={(event) => setRejectionReason(event.target.value)} maxLength={2000} rows={5} required /></label><small>{rejectionReason.length}/2,000 characters</small>{reviewError && <p className="admin-review-error" role="alert">{reviewError}</p>}<div className="confirmation-actions"><button className="button button-outline" type="button" disabled={reviewBusy} onClick={() => setRejectOpen(false)}>Cancel</button><button className="button button-primary admin-reject-button" type="submit" disabled={reviewBusy || !rejectionReason.trim()}>{reviewBusy ? 'Rejecting…' : 'Confirm rejection'}</button></div></form></section></div>}
  </section>
}

export default OrganizerApplicationDetailPage
