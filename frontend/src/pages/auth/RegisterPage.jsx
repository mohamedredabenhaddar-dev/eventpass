import { useState } from 'react'
import { ArrowRight, Eye, EyeOff, Ticket } from 'lucide-react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../../hooks/useAuth.js'
import { roleHome } from '../../utils/roleHome.js'

const passwordRequirements = [
  { key: 'length', label: 'At least 8 characters', test: (value) => value.length >= 8 },
  { key: 'letter', label: 'At least one letter', test: (value) => /\p{L}/u.test(value) },
  { key: 'number', label: 'At least one number', test: (value) => /\p{N}/u.test(value) },
  { key: 'symbol', label: 'At least one special character', test: (value) => /[\p{P}\p{S}]/u.test(value) },
]

function RegisterPage() {
  const { register } = useAuth()
  const navigate = useNavigate()
  const [form, setForm] = useState({ name: '', email: '', password: '', password_confirmation: '' })
  const [errors, setErrors] = useState({})
  const [message, setMessage] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [attempted, setAttempted] = useState(false)
  const requirementsMet = passwordRequirements.every(({ test }) => test(form.password))
  const confirmationMatches = form.password_confirmation === form.password

  const update = (event) => setForm((current) => ({ ...current, [event.target.name]: event.target.value }))

  const submit = async (event) => {
    event.preventDefault()
    setErrors({})
    setMessage('')
    setAttempted(true)
    if (!requirementsMet || !confirmationMatches) {
      setErrors({
        ...(!requirementsMet ? { password: ['Please meet all password requirements.'] } : {}),
        ...(!confirmationMatches ? { password_confirmation: ['Passwords do not match.'] } : {}),
      })
      return
    }
    setSubmitting(true)
    try {
      const user = await register(form)
      navigate(roleHome[user.role] || '/', { replace: true })
    } catch (error) {
      setErrors(error.response?.data?.errors || {})
      setMessage(error.response?.data?.message || error.userMessage)
    } finally {
      setSubmitting(false)
    }
  }

  return <section className="auth-page page-container">
    <div className="auth-art"><div className="auth-art-orbit" /><div className="auth-art-ticket"><Ticket size={29} /><span>COME ON<br />IN.</span></div><small>EVENTPASS / THERE’S ROOM FOR YOU</small></div>
    <div className="auth-panel">
      <span className="eyebrow">COME ON IN</span><h1>Make a little room<br /><em>for more.</em></h1>
      <p className="auth-intro">Create your account and find something worth going out for.</p>
      {message && <div className="form-alert" role="alert">{message}</div>}
      <form className="auth-form" onSubmit={submit} noValidate>
        <label htmlFor="register-name">Full name</label>
        <input id="register-name" name="name" type="text" autoComplete="name" value={form.name} onChange={update} aria-invalid={Boolean(errors.name)} />
        {errors.name?.map((error) => <span className="field-error" key={error}>{error}</span>)}
        <label htmlFor="register-email">Email address</label>
        <input id="register-email" name="email" type="email" autoComplete="email" value={form.email} onChange={update} aria-invalid={Boolean(errors.email)} />
        {errors.email?.map((error) => <span className="field-error" key={error}>{error}</span>)}
        <label htmlFor="register-password">Password</label>
        <div className="password-field"><input id="register-password" name="password" type={showPassword ? 'text' : 'password'} autoComplete="new-password" value={form.password} onChange={update} aria-invalid={Boolean(errors.password)} /><button type="button" aria-label={showPassword ? 'Hide password' : 'Show password'} onClick={() => setShowPassword((show) => !show)}>{showPassword ? <EyeOff size={17} /> : <Eye size={17} />}</button></div>
        <ul className="password-requirements" aria-label="Password requirements">
          {passwordRequirements.map(({ key, label, test }) => {
            const met = test(form.password)
            return <li className={met ? 'is-met' : ''} key={key}><span aria-hidden="true">{met ? '✓' : '○'}</span>{label}</li>
          })}
        </ul>
        {errors.password?.map((error) => <span className="field-error" key={error}>{error}</span>)}
        <label htmlFor="register-password-confirmation">Confirm password</label>
        <input id="register-password-confirmation" name="password_confirmation" type={showPassword ? 'text' : 'password'} autoComplete="new-password" value={form.password_confirmation} onChange={update} aria-invalid={Boolean(errors.password_confirmation) || (attempted && !confirmationMatches)} />
        {attempted && form.password_confirmation && !confirmationMatches && !errors.password_confirmation && <span className="field-error">Passwords do not match.</span>}
        {errors.password_confirmation?.map((error) => <span className="field-error" key={error}>{error}</span>)}
        <button className="button button-primary auth-submit" type="submit" disabled={submitting}>{submitting ? <><span className="spinner-border spinner-border-sm" /> Creating account…</> : <>Create account <ArrowRight size={16} /></>}</button>
      </form>
      <p className="auth-switch">Already have an account? <Link to="/login">Sign in</Link></p>
    </div>
  </section>
}

export default RegisterPage
