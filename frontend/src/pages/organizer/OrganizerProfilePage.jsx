import { useEffect, useState } from 'react'
import { CircleCheck, CircleAlert, Save } from 'lucide-react'
import api from '../../services/api.js'

function getErrorMessages(errors, field) {
  if (!errors || errors[field] === undefined) return []
  return Array.isArray(errors[field]) ? errors[field] : [errors[field]]
}

function OrganizerProfilePage() {
  const [profile, setProfile] = useState({
    organization_name: '',
    contact_email: '',
    contact_phone: '',
    description: '',
  })
  const [form, setForm] = useState({
    organization_name: '',
    contact_email: '',
    contact_phone: '',
    description: '',
  })
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const [fieldErrors, setFieldErrors] = useState({})

  useEffect(() => {
    let active = true
    api.get('/organizer/profile')
      .then(({ data }) => {
        const p = data.profile || {}
        const base = {
          organization_name: p.organization_name || data.user?.name || '',
          contact_email: p.contact_email || '',
          contact_phone: p.contact_phone || '',
          description: p.description || '',
        }
        if (active) {
          setProfile(base)
          setForm(base)
          setLoading(false)
        }
      })
      .catch(() => { if (active) { setError('We could not load your profile. Please try again.'); setLoading(false) } })
    return () => { active = false }
  }, [])

  const updateField = (field, value) => {
    setForm((previous) => ({ ...previous, [field]: value }))
    setFieldErrors((previous) => ({ ...previous, [field]: [] }))
  }

  const handleSubmit = async (event) => {
    event.preventDefault()
    setSaving(true)
    setError('')
    setSuccess('')
    setFieldErrors({})
    try {
      const response = await api.put('/organizer/profile', form)
      setProfile(response.data.profile)
      setForm({
        organization_name: response.data.profile?.organization_name || '',
        contact_email: response.data.profile?.contact_email || '',
        contact_phone: response.data.profile?.contact_phone || '',
        description: response.data.profile?.description || '',
      })
      setSuccess('Profile saved successfully.')
    } catch (err) {
      if (err?.response?.status === 422 && err?.response?.data?.errors) {
        setFieldErrors(err.response.data.errors)
      } else {
        setError(err?.response?.data?.message || 'We could not save your profile. Please try again.')
      }
    } finally {
      setSaving(false)
    }
  }

  return <section className="organizer-page">
    <div className="organizer-welcome"><div><span className="eyebrow">ORGANIZER PROFILE</span><h1>Organizer profile</h1><p>Manage how your organization appears in the EventPass catalog.</p></div></div>
    {error && <div className="organizer-state organizer-error" role="alert"><p>{error}</p><button className="button button-outline" type="button" onClick={() => window.location.reload()}>Try again</button></div>}
    {!error && <form className="organizer-profile-form" onSubmit={handleSubmit} noValidate>
      {loading ? <div className="organizer-state" role="status">Loading your profile…</div> :
      <>
      {success && <div className="organizer-state organizer-success"><p>{success}</p></div>}
      <div className="organizer-field">
        <label className="organizer-field-label" htmlFor="organization_name">Organization name</label>
        <input
          id="organization_name"
          name="organization_name"
          className={`organizer-field-input${fieldErrors.organization_name?.length ? ' organizer-field-error' : ''}`}
          type="text"
          value={form.organization_name}
          placeholder="Your organization name"
          maxLength={255}
          onChange={(event) => updateField('organization_name', event.target.value)}
        />
        {getErrorMessages(fieldErrors, 'organization_name').map((message, index) => <p key={index} className="organizer-field-error-text" role="alert">{message}</p>)}
      </div>
      <div className="organizer-field">
        <label className="organizer-field-label" htmlFor="contact_email">Contact email</label>
        <input
          id="contact_email"
          name="contact_email"
          className={`organizer-field-input${fieldErrors.contact_email?.length ? ' organizer-field-error' : ''}`}
          type="email"
          value={form.contact_email}
          placeholder="hello@yourorg.com"
          maxLength={255}
          onChange={(event) => updateField('contact_email', event.target.value)}
        />
        {getErrorMessages(fieldErrors, 'contact_email').map((message, index) => <p key={index} className="organizer-field-error-text" role="alert">{message}</p>)}
      </div>
      <div className="organizer-field">
        <label className="organizer-field-label" htmlFor="contact_phone">Contact phone</label>
        <input
          id="contact_phone"
          name="contact_phone"
          className={`organizer-field-input${fieldErrors.contact_phone?.length ? ' organizer-field-error' : ''}`}
          type="tel"
          value={form.contact_phone}
          placeholder="+33 6 12 34 56 78"
          maxLength={50}
          onChange={(event) => updateField('contact_phone', event.target.value)}
        />
        {getErrorMessages(fieldErrors, 'contact_phone').map((message, index) => <p key={index} className="organizer-field-error-text" role="alert">{message}</p>)}
      </div>
      <div className="organizer-field">
        <label className="organizer-field-label" htmlFor="description">Description</label>
        <textarea
          id="description"
          name="description"
          className={`organizer-field-textarea${fieldErrors.description?.length ? ' organizer-field-error' : ''}`}
          rows={4}
          maxLength={2000}
          placeholder="Tell the world what your organization does..."
          value={form.description}
          onChange={(event) => updateField('description', event.target.value)}
        />
        {getErrorMessages(fieldErrors, 'description').map((message, index) => <p key={index} className="organizer-field-error-text" role="alert">{message}</p>)}
      </div>
      <div className="organizer-profile-actions">
        <button className="button button-primary" type="submit" disabled={saving}><Save size={16} />{saving ? 'Saving…' : 'Save profile'}</button>
        {profile.organization_name && <span className="organizer-profile-saved"><CircleCheck size={14} /> Currently: <strong>{profile.organization_name}</strong></span>}
        {!profile.organization_name && <span className="organizer-profile-empty"><CircleAlert size={14} /> No organization name saved yet</span>}
      </div>
      </>}
    </form>}
  </section>
}

export default OrganizerProfilePage
