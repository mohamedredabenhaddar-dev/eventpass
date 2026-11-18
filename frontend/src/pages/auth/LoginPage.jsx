import { useState } from 'react'
import { ArrowRight, Eye, EyeOff, Ticket } from 'lucide-react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../../hooks/useAuth.js'
import { roleHome } from '../../utils/roleHome.js'

function LoginPage() {
  const { login } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [form, setForm] = useState({ email: '', password: '' })
  const [errors, setErrors] = useState({})
  const [message, setMessage] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const update = (event) => setForm((current) => ({ ...current, [event.target.name]: event.target.value }))

  const submit = async (event) => {
    event.preventDefault(); setErrors({}); setMessage(''); setSubmitting(true)
    try {
      const user = await login(form)
      navigate(location.state?.from?.pathname || roleHome[user.role] || '/', { replace: true })
    } catch (error) {
      setErrors(error.response?.data?.errors || {})
      setMessage(error.response?.data?.message || error.userMessage)
    } finally { setSubmitting(false) }
  }

  return <section className="auth-page page-container">
    <div className="auth-art"><div className="auth-art-orbit" /><div className="auth-art-ticket"><Ticket size={29} /><span>THE NIGHT<br />IS YOURS.</span></div><small>EVENTPASS / FIND YOUR MOMENT</small></div>
    <div className="auth-panel"><span className="eyebrow">WELCOME BACK</span><h1>Your next plan<br /><em>is waiting.</em></h1><p className="auth-intro">Sign in and pick up where the good stuff begins.</p>
      {message && <div className="form-alert" role="alert">{message}</div>}
      <form className="auth-form" onSubmit={submit} noValidate>
        <label htmlFor="login-email">Email address</label><input id="login-email" name="email" type="email" autoComplete="email" value={form.email} onChange={update} aria-invalid={Boolean(errors.email)} />
        {errors.email?.map((error) => <span className="field-error" key={error}>{error}</span>)}
        <label htmlFor="login-password">Password</label>
        <div className="password-field"><input id="login-password" name="password" type={showPassword ? 'text' : 'password'} autoComplete="current-password" value={form.password} onChange={update} aria-invalid={Boolean(errors.password)} /><button type="button" aria-label={showPassword ? 'Hide password' : 'Show password'} onClick={() => setShowPassword((show) => !show)}>{showPassword ? <EyeOff size={17} /> : <Eye size={17} />}</button></div>
        {errors.password?.map((error) => <span className="field-error" key={error}>{error}</span>)}
        <button className="button button-primary auth-submit" type="submit" disabled={submitting}>{submitting ? <><span className="spinner-border spinner-border-sm" /> Signing in…</> : <>Sign in <ArrowRight size={16} /></>}</button>
      </form>
      <p className="auth-switch">New to EventPass? <Link to="/register">Create an account</Link></p>
    </div>
  </section>
}

export default LoginPage
