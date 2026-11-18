import { useEffect, useState } from 'react'
import { ArrowRight, Building2, CheckCircle2, Clock3, RefreshCw, Send, XCircle } from 'lucide-react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../hooks/useAuth.js'
import { fetchMyOrganizerRequests, submitOrganizerRequest } from '../../services/organizerRequestService.js'

const EMPTY_FORM = {
  organization_name: '',
  description: '',
  contact_email: '',
  contact_phone: '',
  website: '',
}

function OrganizerApplicationPage() {
  const { user, refreshUser } = useAuth()
  const [application, setApplication] = useState(null)
  const [form, setForm] = useState(EMPTY_FORM)
  const [fieldErrors, setFieldErrors] = useState({})
  const [loadError, setLoadError] = useState('')
  const [submitError, setSubmitError] = useState('')
  const [notice, setNotice] = useState('')
  const [loading, setLoading] = useState(user?.role === 'client')
  const [submitting, setSubmitting] = useState(false)
  const [attempt, setAttempt] = useState(0)

  useEffect(() => {
    if (user?.role !== 'client') return undefined
    let active = true

    fetchMyOrganizerRequests()
      .then((response) => {
        if (active) setApplication(response.data?.[0] ?? null)
      })
      .catch(async (error) => {
        if (error.response?.status === 403) {
          try {
            const refreshedUser = await refreshUser()
            if (refreshedUser.role === 'organizer') return
          } catch {
            // The shared API interceptor and AuthContext handle expired sessions.
          }
        }
        if (active) setLoadError(error.userMessage || 'Your application status could not be loaded.')
      })
      .finally(() => {
        if (active) setLoading(false)
      })

    return () => { active = false }
  }, [attempt, refreshUser, user?.role])

  const updateField = (event) => {
    const { name, value } = event.target
    setForm((current) => ({ ...current, [name]: value }))
    setFieldErrors((current) => ({ ...current, [name]: undefined }))
    setSubmitError('')
  }

  const submit = async (event) => {
    event.preventDefault()
    setFieldErrors({})
    setSubmitError('')
    setNotice('')
    setSubmitting(true)

    try {
      const created = await submitOrganizerRequest({
        ...form,
        website: form.website.trim() || null,
      })
      setApplication(created)
      setForm(EMPTY_FORM)
      setNotice('Your application was submitted and is awaiting review.')
    } catch (error) {
      if (error.response?.status === 409) {
        setNotice('A pending application already exists. Your latest status has been refreshed.')
        setLoading(true)
        setAttempt((value) => value + 1)
      } else {
        setFieldErrors(error.response?.data?.errors || {})
        setSubmitError(error.response?.data?.message || error.userMessage || 'Your application could not be submitted.')
      }
    } finally {
      setSubmitting(false)
    }
  }

  if (user?.role === 'organizer' || application?.status === 'approved') {
    return <main className="organizer-application-page page-container">
      <section className="organizer-application-status is-approved" aria-live="polite">
        <CheckCircle2 size={28} />
        <span className="eyebrow">ORGANIZER ACCESS GRANTED</span>
        <h1>You’re ready to organize.</h1>
        <p>Your account has organizer access. You can now manage your events and ticket types.</p>
        <Link className="button button-primary" to="/organizer/dashboard">Open organizer dashboard <ArrowRight size={16} /></Link>
      </section>
    </main>
  }

  return <main className="organizer-application-page page-container">
    <header className="organizer-application-heading">
      <span className="eyebrow"><Building2 size={14} /> EVENTPASS ORGANIZERS</span>
      <h1>Bring your events<br /><em>to EventPass.</em></h1>
      <p>Tell us about your organization. A Super Admin will review your application.</p>
    </header>

    {loading && <div className="organizer-application-state" role="status"><span className="spinner-border spinner-border-sm" /> Loading your application status…</div>}
    {!loading && loadError && <div className="organizer-application-error" role="alert"><p>{loadError}</p><button className="button button-outline button-small" type="button" onClick={() => { setLoadError(''); setLoading(true); setAttempt((value) => value + 1) }}>Try again <RefreshCw size={14} /></button></div>}
    {!loading && !loadError && notice && <div className="organizer-application-notice" role="status">{notice}</div>}

    {!loading && !loadError && application?.status === 'pending' && <section className="organizer-application-status is-pending" aria-live="polite">
      <Clock3 size={25} />
      <span className="organizer-application-badge">Pending review</span>
      <h2>Your application is in review.</h2>
      <p>We received your application for <strong>{application.organization_name}</strong>. You can return here to check for an update.</p>
      <button className="button button-outline button-small" type="button" onClick={() => { setLoading(true); setAttempt((value) => value + 1) }}>Refresh status <RefreshCw size={14} /></button>
    </section>}

    {!loading && !loadError && application?.status === 'rejected' && <section className="organizer-application-status is-rejected" aria-live="polite">
      <XCircle size={25} />
      <span className="organizer-application-badge">Application not approved</span>
      <h2>Your application was rejected.</h2>
      <p>{application.rejection_reason || 'No additional reason was provided.'}</p>
      <small>You may submit a new application with updated information.</small>
    </section>}

    {!loading && !loadError && application?.status !== 'pending' && application?.status !== 'approved' && <section className="organizer-application-form-panel">
      <div className="organizer-application-form-heading">
        <div><span className="eyebrow">{application?.status === 'rejected' ? 'RESUBMIT APPLICATION' : 'YOUR APPLICATION'}</span><h2>Organization details</h2></div>
        {application?.status === 'rejected' && <button className="button button-outline button-small" type="button" onClick={() => { setLoading(true); setAttempt((value) => value + 1) }}>Refresh status <RefreshCw size={14} /></button>}
      </div>
      {submitError && <div className="organizer-application-error" role="alert">{submitError}</div>}
      <form className="organizer-application-form" onSubmit={submit} noValidate>
        <label htmlFor="organizer-organization-name">Organization name <span>Required</span></label>
        <input id="organizer-organization-name" name="organization_name" type="text" maxLength="255" autoComplete="organization" value={form.organization_name} onChange={updateField} aria-invalid={Boolean(fieldErrors.organization_name)} required />
        {fieldErrors.organization_name?.map((message) => <span className="organizer-application-field-error" key={message}>{message}</span>)}

        <label htmlFor="organizer-description">Organization description <span>Required</span></label>
        <textarea id="organizer-description" name="description" rows="5" maxLength="10000" value={form.description} onChange={updateField} aria-invalid={Boolean(fieldErrors.description)} required />
        {fieldErrors.description?.map((message) => <span className="organizer-application-field-error" key={message}>{message}</span>)}

        <div className="organizer-application-field-grid">
          <div>
            <label htmlFor="organizer-contact-email">Contact email <span>Required</span></label>
            <input id="organizer-contact-email" name="contact_email" type="email" maxLength="255" autoComplete="email" value={form.contact_email} onChange={updateField} aria-invalid={Boolean(fieldErrors.contact_email)} required />
            {fieldErrors.contact_email?.map((message) => <span className="organizer-application-field-error" key={message}>{message}</span>)}
          </div>
          <div>
            <label htmlFor="organizer-contact-phone">Contact phone <span>Required</span></label>
            <input id="organizer-contact-phone" name="contact_phone" type="tel" maxLength="50" autoComplete="tel" value={form.contact_phone} onChange={updateField} aria-invalid={Boolean(fieldErrors.contact_phone)} required />
            {fieldErrors.contact_phone?.map((message) => <span className="organizer-application-field-error" key={message}>{message}</span>)}
          </div>
        </div>

        <label htmlFor="organizer-website">Website <span>Optional</span></label>
        <input id="organizer-website" name="website" type="url" maxLength="2048" autoComplete="url" placeholder="https://example.com" value={form.website} onChange={updateField} aria-invalid={Boolean(fieldErrors.website)} />
        {fieldErrors.website?.map((message) => <span className="organizer-application-field-error" key={message}>{message}</span>)}

        <button className="button button-primary organizer-application-submit" type="submit" disabled={submitting}>
          {submitting ? <><span className="spinner-border spinner-border-sm" /> Submitting…</> : <>Submit application <Send size={15} /> </>}
        </button>
      </form>
    </section>}
  </main>
}

export default OrganizerApplicationPage
