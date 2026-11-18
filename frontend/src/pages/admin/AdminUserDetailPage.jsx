import { useEffect, useRef, useState } from 'react'
import { ArrowLeft, CalendarDays, ShieldAlert, UserRound } from 'lucide-react'
import { Link, useParams } from 'react-router-dom'
import ConfirmationDialog from '../../components/ConfirmationDialog.jsx'
import { fetchSuperAdminUser, reactivateSuperAdminUser, suspendSuperAdminUser } from '../../services/superAdminUserService.js'
import { adminUserError, adminUserValidationError, formatAdminUserDate, formatAdminUserLabel } from './adminUserUtils.js'

function AdminUserDetailPage() {
  const { id } = useParams()
  const [state, setState] = useState({ key: '', user: null, error: '' })
  const [retry, setRetry] = useState(0)
  const [dialog, setDialog] = useState('')
  const [reason, setReason] = useState('')
  const [reasonError, setReasonError] = useState('')
  const [actionError, setActionError] = useState('')
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState(false)
  const busyRef = useRef(false)
  const key = `${id}:${retry}`

  useEffect(() => {
    let active = true
    fetchSuperAdminUser(id)
      .then((record) => { if (active) setState({ key, user: record, error: '' }) })
      .catch((error) => { if (active) setState({ key, user: null, error: adminUserError(error, 'User details could not be loaded.') }) })
    return () => { active = false }
  }, [id, retry, key])

  const loading = state.key !== key
  const record = loading ? null : state.user

  const closeDialog = () => {
    if (busyRef.current) return
    setDialog('')
    setReason('')
    setReasonError('')
  }

  const submitSuspension = async (event) => {
    event.preventDefault()
    const cleanReason = reason.trim()
    if (!cleanReason) {
      setReasonError('Enter a reason before confirming suspension.')
      return
    }
    if (cleanReason.length > 2000) {
      setReasonError('The reason must be 2,000 characters or fewer.')
      return
    }
    await performAction('suspend', cleanReason)
  }

  const performAction = async (action, suspensionReason = '') => {
    if (busyRef.current) return
    busyRef.current = true
    setBusy(true)
    setActionError('')
    setReasonError('')
    setNotice('')
    try {
      if (action === 'suspend') {
        await suspendSuperAdminUser(id, suspensionReason)
        setNotice('Account suspended. Existing Sanctum tokens have been revoked.')
      } else {
        await reactivateSuperAdminUser(id)
        setNotice('Account reactivated. The user must log in again to receive a new token.')
      }
      setDialog('')
      setReason('')
      setRetry((value) => value + 1)
    } catch (error) {
      const status = error.response?.status
      const fieldError = action === 'suspend' ? adminUserValidationError(error, 'suspension_reason') : ''
      if (fieldError) {
        setReasonError(fieldError)
      } else {
        setActionError(adminUserError(error, action === 'suspend' ? 'The account could not be suspended.' : 'The account could not be reactivated.'))
        if (status === 404 || status === 409) {
          setDialog('')
          setRetry((value) => value + 1)
        }
      }
    } finally {
      busyRef.current = false
      setBusy(false)
    }
  }

  return <section className="admin-user-detail-page">
    <Link className="admin-back-link" to="/admin/users"><ArrowLeft size={15} /> User directory</Link>
    {loading && <div className="admin-users-state" role="status"><span className="admin-users-spinner" />Loading user details…</div>}
    {!loading && state.error && <div className="admin-users-error" role="alert"><p>{state.error}</p><button className="button button-outline" type="button" onClick={() => setRetry((value) => value + 1)}>Retry</button></div>}
    {!loading && !state.error && record && <>
      <header className="admin-user-detail-heading"><div><span className="eyebrow">USER ACCOUNT · #{record.id}</span><h1>{record.name}</h1><p>{record.email}</p></div><span className={`admin-user-status is-${record.account_status}`}>{formatAdminUserLabel(record.account_status)}</span></header>
      {notice && <p className="admin-user-notice" role="status">{notice}</p>}
      {actionError && <p className="admin-user-action-error" role="alert">{actionError}</p>}

      <article className="admin-user-detail-card">
        <div className="admin-user-facts">
          <div><span><UserRound size={16} /> Role</span><strong>{formatAdminUserLabel(record.role)}</strong></div>
          <div><span><CalendarDays size={16} /> Registration date</span><strong>{formatAdminUserDate(record.created_at)}</strong></div>
          <div><span>Account status</span><strong>{formatAdminUserLabel(record.account_status)}</strong></div>
          <div><span>User ID</span><strong>{record.id}</strong></div>
        </div>
      </article>

      {record.account_status === 'suspended' && <article className="admin-user-suspension-card"><ShieldAlert size={18} /><div><strong>Suspension information</strong><span>Suspended {formatAdminUserDate(record.suspended_at)}{record.suspended_by ? ` · By Super Admin #${record.suspended_by}` : ''}</span>{record.suspension_reason && <p>{record.suspension_reason}</p>}</div></article>}

      <section className="admin-user-actions"><div><span className="eyebrow">ACCOUNT STATUS ACTIONS</span><p>Laravel verifies permissions and protects the current and last active Super Admin accounts.</p></div><div className="admin-user-action-buttons">
        {record.account_status === 'active'
          ? <button className="button button-outline admin-user-suspend-button" type="button" disabled={busy} onClick={() => { setActionError(''); setReason(''); setReasonError(''); setDialog('suspend') }}>Suspend account</button>
          : <button className="button button-primary" type="button" disabled={busy} onClick={() => { setActionError(''); setDialog('reactivate') }}>Reactivate account</button>}
      </div></section>
    </>}

    {dialog === 'suspend' && <div className="confirmation-backdrop admin-user-dialog-backdrop"><section className="confirmation-dialog admin-user-dialog" role="dialog" aria-modal="true" aria-labelledby="admin-user-suspend-title">
      <h2 id="admin-user-suspend-title">Suspend {record?.name}?</h2><p>Suspension revokes this account’s existing tokens. Laravel will reject attempts to suspend your own account or the last active Super Admin.</p>
      <form onSubmit={submitSuspension}><label htmlFor="admin-user-suspension-reason">Reason</label><textarea id="admin-user-suspension-reason" value={reason} onChange={(event) => { setReason(event.target.value); setReasonError('') }} maxLength={2000} rows={4} required aria-invalid={Boolean(reasonError)} aria-describedby={reasonError ? 'admin-user-reason-error' : undefined} />
        <small>{reason.length}/2,000 characters</small>{reasonError && <p className="admin-user-action-error" id="admin-user-reason-error" role="alert">{reasonError}</p>}
        <div className="confirmation-actions"><button className="button button-outline" type="button" disabled={busy} onClick={closeDialog}>Keep account active</button><button className="button button-primary admin-user-suspend-button" type="submit" disabled={busy || !reason.trim()}>{busy ? 'Suspending…' : 'Confirm suspension'}</button></div>
      </form>
    </section></div>}
    {dialog === 'reactivate' && <ConfirmationDialog title="Reactivate this account?" message="The account will be active again. Previously revoked tokens remain revoked, so the user must log in again." confirmLabel={busy ? 'Reactivating…' : 'Reactivate account'} busy={busy} onCancel={closeDialog} onConfirm={() => performAction('reactivate')} />}
  </section>
}

export default AdminUserDetailPage
