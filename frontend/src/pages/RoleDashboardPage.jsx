import { ArrowRight, Sparkles } from 'lucide-react'
import { Link } from 'react-router-dom'
import { useAuth } from '../hooks/useAuth.js'

function RoleDashboardPage() {
  const { user } = useAuth()
  const roleLabel = user.role.replaceAll('_', ' ')

  return <section className="placeholder-page page-container"><div className="placeholder-card">
    <span className="eyebrow"><Sparkles size={14} /> {roleLabel} space</span>
    <h1>Welcome, {user.name}.</h1>
    <p>Your {roleLabel} dashboard will take shape in a later phase. You’re signed in with your EventPass account.</p>
    <div className="role-dashboard-links">
      {user.role === 'client' && <Link className="text-link" to="/organizer-application">Apply to become an organizer <ArrowRight size={16} /></Link>}
      <Link className="text-link" to="/events">Explore public events <ArrowRight size={16} /></Link>
    </div>
  </div></section>
}

export default RoleDashboardPage
