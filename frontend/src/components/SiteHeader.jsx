import { useState } from 'react'
import { ArrowUpRight, Menu, X } from 'lucide-react'
import { Link, NavLink } from 'react-router-dom'
import BrandMark from './BrandMark.jsx'
import { useAuth } from '../hooks/useAuth.js'
import { roleHome } from '../utils/roleHome.js'

function SiteHeader() {
  const [menuOpen, setMenuOpen] = useState(false)
  const [logoutError, setLogoutError] = useState('')
  const { user, logout } = useAuth()
  const closeMenu = () => setMenuOpen(false)

  const handleLogout = async () => {
    setLogoutError('')
    try { await logout() } catch { setLogoutError('Your session was cleared, but the server could not confirm sign out.') }
    closeMenu()
  }

  return (
    <header className="site-header">
      <div className="topline"><span className="status-dot" /> Your next great night starts here</div>
      <div className="nav-wrap">
        <BrandMark />
        <button className="menu-toggle" type="button" aria-label={menuOpen ? 'Close navigation' : 'Open navigation'} aria-expanded={menuOpen} onClick={() => setMenuOpen((open) => !open)}>
          {menuOpen ? <X size={22} /> : <Menu size={22} />}
        </button>
        <nav className={`main-nav${menuOpen ? ' is-open' : ''}`} aria-label="Main navigation">
          <NavLink to="/events" onClick={closeMenu}>Discover</NavLink>
          <a href="/#categories" onClick={closeMenu}>Categories</a>
          <a href="/#for-organizers" onClick={closeMenu}>For organizers</a>
        </nav>
        <div className={`nav-actions${menuOpen ? ' is-open' : ''}`}>
          {user ? <>
            {user.role === 'client' && <><Link className="nav-login" to="/my-tickets" onClick={closeMenu}>My tickets</Link><Link className="nav-login" to="/client/reservations" onClick={closeMenu}>My reservations</Link><Link className="nav-login" to="/client/payments" onClick={closeMenu}>Payment history</Link><Link className="nav-login" to="/organizer-application" onClick={closeMenu}>Become an organizer</Link></>}
            {user.role === 'organizer' && <Link className="nav-login" to="/organizer-application" onClick={closeMenu}>Application status</Link>}
            {user.role === 'staff' && <Link className="nav-login" to="/staff/dashboard" onClick={closeMenu}>Staff dashboard</Link>}
            <Link className="nav-login nav-user" to={roleHome[user.role] || '/'} onClick={closeMenu}>{user.name} <span>{user.role?.replace('_', ' ')}</span></Link>
            <button className="button button-small button-outline" type="button" onClick={handleLogout}>Log out</button>
          </> : <>
            <Link className="nav-login" to="/login" onClick={closeMenu}>Log in</Link>
            <Link className="button button-small button-primary" to="/register" onClick={closeMenu}>Get started <ArrowUpRight size={15} /></Link>
          </>}
        </div>
        {logoutError && <span className="nav-error" role="status">{logoutError}</span>}
      </div>
    </header>
  )
}

export default SiteHeader
