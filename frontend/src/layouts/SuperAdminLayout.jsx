import { useState } from 'react'
import { Activity, CalendarDays, ClipboardList, LayoutDashboard, LogOut, Menu, Ticket, UserRound, UserRoundCog, X } from 'lucide-react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import BrandMark from '../components/BrandMark.jsx'
import { useAuth } from '../hooks/useAuth.js'

function SuperAdminLayout() {
  const [open, setOpen] = useState(false)
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const pageTitle = location.pathname.includes('organizer-applications') ? 'Organizer Applications'
    : location.pathname.includes('/users') ? 'Users'
    : location.pathname.includes('/events') ? 'Events'
    : location.pathname.includes('/access') ? 'Access Monitoring'
    : location.pathname.includes('ticket-types') ? 'Ticket Types'
      : location.pathname.includes('reservations') ? 'Reservations' : 'Overview'
  const signOut = async () => { try { await logout() } catch { /* The auth context clears the local token even if the API is unreachable. */ } finally { navigate('/login', { replace: true }) } }
  return <div className="admin-shell">{open && <button className="admin-scrim" aria-label="Close menu" onClick={() => setOpen(false)} />}
    <aside className={`admin-sidebar${open ? ' is-open' : ''}`}><div className="admin-brand"><BrandMark /><button aria-label="Close menu" onClick={() => setOpen(false)}><X size={18} /></button></div><span className="admin-label">ADMINISTRATION</span><nav aria-label="Super Admin navigation">
      <NavLink end to="/admin/dashboard" onClick={() => setOpen(false)}><LayoutDashboard size={17} /> Overview</NavLink>
      <NavLink to="/admin/organizer-applications" onClick={() => setOpen(false)}><UserRoundCog size={17} /> Organizer applications</NavLink>
      <span className="admin-nav-section">MANAGEMENT</span>
      <NavLink to="/admin/users" onClick={() => setOpen(false)}><UserRound size={17} /> Users</NavLink>
      <NavLink to="/admin/events" onClick={() => setOpen(false)}><CalendarDays size={17} /> Events</NavLink>
      <NavLink to="/admin/reservations" onClick={() => setOpen(false)}><ClipboardList size={17} /> Reservations</NavLink>
      <NavLink to="/admin/ticket-types" onClick={() => setOpen(false)}><Ticket size={17} /> Ticket types</NavLink>
      <NavLink to="/admin/access" onClick={() => setOpen(false)}><Activity size={17} /> Access monitoring</NavLink>
    </nav><small className="admin-foot">SUPER ADMIN WORKSPACE</small></aside>
    <div className="admin-main"><header className="admin-topbar"><button className="admin-menu" aria-label="Open navigation" aria-expanded={open} onClick={() => setOpen(true)}><Menu size={20} /></button><span>Super Admin <i>/</i> {pageTitle}</span><div><strong>{user?.name || 'Administrator'}</strong><button className="button button-outline button-small" type="button" onClick={signOut}><LogOut size={15} /> Log out</button></div></header><main className="ticketing-page admin-content"><Outlet /></main></div>
  </div>
}
export default SuperAdminLayout
