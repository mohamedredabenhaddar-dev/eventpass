import { useState } from 'react'
import { CalendarDays, ClipboardList, LayoutDashboard, LogOut, Menu, X } from 'lucide-react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import BrandMark from '../components/BrandMark.jsx'
import { useAuth } from '../hooks/useAuth.js'

function StaffLayout() {
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
    } catch {
      setLogoutError('Your local session was cleared, but the server could not confirm sign out.')
    }
    navigate('/login', { replace: true })
  }

  const pageName = location.pathname.includes('/scanner') ? 'QR scanner'
    : location.pathname.includes('/access') ? 'Access report'
      : location.pathname.includes('/events/') ? 'Event details'
        : location.pathname.endsWith('/events') ? 'Assigned events' : 'Overview'

  return <div className="staff-shell">
    {sidebarOpen && <button className="staff-sidebar-scrim" type="button" aria-label="Close menu" onClick={closeSidebar} />}
    <aside className={`staff-sidebar${sidebarOpen ? ' is-open' : ''}`}>
      <div className="staff-brand-row"><BrandMark /><button className="staff-mobile-close" type="button" aria-label="Close menu" onClick={closeSidebar}><X size={19} /></button></div>
      <div className="staff-workspace"><span className="staff-workspace-icon">EP</span><span><strong>Staff workspace</strong><small>Event access</small></span></div>
      <nav className="staff-nav" aria-label="Staff navigation">
        <span className="staff-nav-label">WORKSPACE</span>
        <NavLink end to="/staff/dashboard" onClick={closeSidebar}><LayoutDashboard size={17} /> Overview</NavLink>
        <NavLink to="/staff/events" onClick={closeSidebar}><CalendarDays size={17} /> Assigned events</NavLink>
        <span className="staff-nav-label staff-nav-label-spaced">TOOLS</span>
        <NavLink to="/staff/events" onClick={closeSidebar}><ClipboardList size={17} /> Access & scanning</NavLink>
      </nav>
      <div className="staff-sidebar-bottom"><span>EVENTPASS / STAFF</span><span>Every arrival matters.</span></div>
    </aside>
    <div className="staff-main-column">
      <header className="staff-topbar">
        <button className="staff-menu-button" type="button" aria-label={sidebarOpen ? 'Close navigation' : 'Open navigation'} aria-expanded={sidebarOpen} onClick={() => setSidebarOpen((open) => !open)}>{sidebarOpen ? <X size={20} /> : <Menu size={20} />}</button>
        <div className="staff-breadcrumb"><span>Staff</span><i>/</i><strong>{pageName}</strong></div>
        <div className="staff-topbar-user"><span className="staff-avatar">{user?.name?.trim()?.[0]?.toUpperCase() || 'S'}</span><span><strong>{user?.name || 'Staff member'}</strong><small>Staff account</small></span><button type="button" onClick={handleLogout} aria-label="Log out"><LogOut size={16} /></button></div>
      </header>
      {logoutError && <p className="staff-logout-error" role="status">{logoutError}</p>}
      <main className="staff-content"><Outlet /></main>
      <footer className="staff-footer">EventPass Staff Workspace <span>Welcome every guest with confidence.</span></footer>
    </div>
  </div>
}

export default StaffLayout
