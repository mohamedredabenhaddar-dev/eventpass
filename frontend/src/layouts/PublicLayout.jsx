import { Outlet } from 'react-router-dom'
import SiteFooter from '../components/SiteFooter.jsx'
import SiteHeader from '../components/SiteHeader.jsx'
import { useAuth } from '../hooks/useAuth.js'

function PublicLayout() {
  const { loading } = useAuth()
  return <div className="site-shell"><SiteHeader /><main className={loading ? 'auth-initializing' : ''}><Outlet /></main><SiteFooter /></div>
}

export default PublicLayout
