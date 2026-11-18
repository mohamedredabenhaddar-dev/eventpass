import { useEffect, useState } from 'react'
import { ArrowLeft, Save } from 'lucide-react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { createOrganizerEvent, fetchOrganizerEvent, updateOrganizerEvent } from '../../services/organizerEventService.js'
import EventStatusBadge from '../../components/EventStatusBadge.jsx'

const blankEvent = {
  title: '', description: '', category: '', venue_name: '', address: '', city: '', country: '',
  start_at: '', end_at: '', capacity: '',
}

const fields = [
  { name: 'title', label: 'Event title', maxLength: 255 },
  { name: 'category', label: 'Category', maxLength: 100 },
  { name: 'venue_name', label: 'Venue name', maxLength: 255 },
  { name: 'address', label: 'Street address', maxLength: 512 },
  { name: 'city', label: 'City', maxLength: 150 },
  { name: 'country', label: 'Country', maxLength: 150 },
]

function toLocalDateTime(value) {
  if (!value) return ''
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''
  const local = new Date(date.getTime() - date.getTimezoneOffset() * 60000)
  return local.toISOString().slice(0, 16)
}

function OrganizerEventFormPage() {
  const { id } = useParams()
  const editing = Boolean(id)
  const navigate = useNavigate()
  const [values, setValues] = useState(blankEvent)
  const [event, setEvent] = useState(null)
  const [loading, setLoading] = useState(editing)
  const [submitting, setSubmitting] = useState(false)
  const [errors, setErrors] = useState({})
  const [formError, setFormError] = useState('')

  useEffect(() => {
    if (!editing) return
    let active = true
    fetchOrganizerEvent(id)
      .then((result) => {
        if (!active) return
        setEvent(result)
        setValues({
          title: result.title || '', description: result.description || '', category: result.category || '',
          venue_name: result.venue_name || '', address: result.address || '', city: result.city || '',
          country: result.country || '', start_at: toLocalDateTime(result.start_at),
          end_at: toLocalDateTime(result.end_at), capacity: result.capacity ?? '',
        })
      })
      .catch((requestError) => {
        if (active) setFormError(requestError.response?.data?.message || requestError.userMessage || 'Event details could not be loaded.')
      })
      .finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [editing, id])

  const updateField = (name, value) => {
    setValues((current) => ({ ...current, [name]: value }))
    setErrors((current) => ({ ...current, [name]: undefined }))
  }

  const submit = async (submission) => {
    submission.preventDefault()
    setSubmitting(true)
    setErrors({})
    setFormError('')
    const payload = {
      ...values,
      start_at: new Date(values.start_at).toISOString(),
      end_at: new Date(values.end_at).toISOString(),
      capacity: Number(values.capacity),
    }
    try {
      const result = editing
        ? await updateOrganizerEvent(id, payload)
        : await createOrganizerEvent(payload)
      navigate(`/organizer/events/${result.id}`, { replace: true })
    } catch (requestError) {
      setErrors(requestError.response?.data?.errors || {})
      setFormError(requestError.response?.data?.message || requestError.userMessage || 'The event could not be saved.')
    } finally {
      setSubmitting(false)
    }
  }

  if (loading) return <div className="organizer-state" role="status">Loading event…</div>
  if (editing && formError && !event) return <div className="organizer-state organizer-error" role="alert"><p>{formError}</p><Link className="button button-outline" to="/organizer/events">Back to my events</Link></div>
  if (event && ['suspended', 'cancelled'].includes(event.status)) return <div className="organizer-state"><p>This event is {event.status} and cannot be edited by organizers.</p><Link className="button button-outline" to={`/organizer/events/${event.id}`}>View event</Link></div>

  return <section className="organizer-page">
    <Link className="organizer-back-link" to={editing ? `/organizer/events/${id}` : '/organizer/events'}><ArrowLeft size={15} /> {editing ? 'Back to event' : 'My events'}</Link>
    <div className="organizer-page-heading"><div><span className="eyebrow">EVENT MANAGEMENT</span><h1>{editing ? 'Edit your event' : 'Create an event'}</h1><p>{editing ? 'Keep the details accurate for your audience.' : 'Start with the details. New events are saved as drafts.'}</p></div>{event && <EventStatusBadge status={event.status} />}</div>
    {formError && <div className="organizer-form-alert" role="alert">{formError}</div>}
    <form className="organizer-form" onSubmit={submit}>
      <div className="organizer-form-grid">
        {fields.map((field) => <div className="organizer-field" key={field.name}>
          <label htmlFor={field.name}>{field.label} <span>*</span></label>
          <input id={field.name} name={field.name} value={values[field.name]} onChange={(change) => updateField(field.name, change.target.value)} maxLength={field.maxLength} required aria-invalid={Boolean(errors[field.name])} />
          {errors[field.name]?.map((message) => <p className="organizer-field-error" key={message}>{message}</p>)}
        </div>)}
        <div className="organizer-field organizer-field-wide"><label htmlFor="description">Description <span>*</span></label><textarea id="description" name="description" value={values.description} onChange={(change) => updateField('description', change.target.value)} maxLength={50000} rows={7} required aria-invalid={Boolean(errors.description)} />{errors.description?.map((message) => <p className="organizer-field-error" key={message}>{message}</p>)}</div>
        <div className="organizer-field"><label htmlFor="start_at">Starts at <span>*</span></label><input id="start_at" type="datetime-local" value={values.start_at} onChange={(change) => updateField('start_at', change.target.value)} required aria-invalid={Boolean(errors.start_at)} />{errors.start_at?.map((message) => <p className="organizer-field-error" key={message}>{message}</p>)}</div>
        <div className="organizer-field"><label htmlFor="end_at">Ends at <span>*</span></label><input id="end_at" type="datetime-local" min={values.start_at || undefined} value={values.end_at} onChange={(change) => updateField('end_at', change.target.value)} required aria-invalid={Boolean(errors.end_at)} />{errors.end_at?.map((message) => <p className="organizer-field-error" key={message}>{message}</p>)}</div>
        <div className="organizer-field"><label htmlFor="capacity">Capacity <span>*</span></label><input id="capacity" type="number" min="1" step="1" value={values.capacity} onChange={(change) => updateField('capacity', change.target.value)} required aria-invalid={Boolean(errors.capacity)} />{errors.capacity?.map((message) => <p className="organizer-field-error" key={message}>{message}</p>)}</div>
      </div>
      <div className="organizer-form-footer"><p>Events start as drafts and can be published after saving.</p><button className="button button-primary" type="submit" disabled={submitting}><Save size={16} />{submitting ? 'Saving…' : editing ? 'Save changes' : 'Save draft'}</button></div>
    </form>
  </section>
}

export default OrganizerEventFormPage
