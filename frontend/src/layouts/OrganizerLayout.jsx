import { useState } from 'react'
import { CalendarDays, LayoutDashboard, LogOut, Menu, Plus, X } from 'lucide-react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import BrandMark from '../components/BrandMark.jsx'
import { useAuth } from '../hooks/useAuth.js'

function OrganizerLayout() {
  const [sidebarOpen, setSidebarOpen] = useState(false)
  const [logoutError, setLogoutError] = useState('')
  const { user, logout } = useAuth()
  const location = useLocation()
  const navigate = useNavigate()
  const closeSidebar = () => setSidebarOpen(false)

  const handleLogout = async () => {
    setLogoutError('')
    try {
      await logout()
      navigate('/login', { replace: true })
    } catch {
      setLogoutError('Your local session was cleared, but the server could not confirm sign out.')
      navigate('/login', { replace: true })
    }
  }

  return <div className="organizer-shell">
    {sidebarOpen && <button className="organizer-sidebar-scrim" type="button" aria-label="Close menu" onClick={closeSidebar} />}
    <aside className={`organizer-sidebar${sidebarOpen ? ' is-open' : ''}`}>
      <div className="organizer-brand-row"><BrandMark /><button type="button" className="organizer-mobile-close" aria-label="Close menu" onClick={closeSidebar}><X size={19} /></button></div>
      <div className="organizer-workspace"><span className="organizer-workspace-icon">EP</span><span><strong>Organizer space</strong><small>Event management</small></span></div>
      <nav className="organizer-nav" aria-label="Organizer navigation">
        <span className="organizer-nav-label">WORKSPACE</span>
        <NavLink end to="/organizer/dashboard" onClick={closeSidebar}><LayoutDashboard size={17} /> Overview</NavLink>
        <NavLink to="/organizer/events" onClick={closeSidebar}><CalendarDays size={17} /> My events</NavLink>
        <NavLink className="organizer-create-link" to="/organizer/events/new" onClick={closeSidebar}><Plus size={17} /> Create event</NavLink>
      </nav>
      <div className="organizer-sidebar-bottom"><span>EVENTPASS / ORGANIZERS</span><span>Make room for good things.</span></div>
    </aside>
    <div className="organizer-main-column">
      <header className="organizer-topbar">
        <button className="organizer-menu-button" type="button" aria-label={sidebarOpen ? 'Close navigation' : 'Open navigation'} aria-expanded={sidebarOpen} onClick={() => setSidebarOpen((open) => !open)}>{sidebarOpen ? <X size={20} /> : <Menu size={20} />}</button>
        <div className="organizer-breadcrumb"><span>Organizer</span><i>/</i><strong>{location.pathname.includes('/events/new') ? 'Create event' : location.pathname.includes('/events') ? 'My events' : 'Overview'}</strong></div>
        <div className="organizer-topbar-user"><span className="organizer-avatar">{user?.name?.trim()?.[0]?.toUpperCase() || 'O'}</span><span><strong>{user?.name || 'Organizer'}</strong><small>Organizer account</small></span><button type="button" onClick={handleLogout} aria-label="Log out"><LogOut size={16} /></button></div>
      </header>
      {logoutError && <p className="organizer-logout-error" role="status">{logoutError}</p>}
      <main className="organizer-content"><Outlet /></main>
      <footer className="organizer-footer">EventPass Organizer Workspace <span>Build gatherings worth remembering.</span></footer>
    </div>
  </div>
}

export default OrganizerLayout
