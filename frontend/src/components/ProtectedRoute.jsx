import { Navigate, useLocation } from 'react-router-dom'
import { useAuth } from '../hooks/useAuth.js'
import { roleHome } from '../utils/roleHome.js'

function ProtectedRoute({ roles, children }) {
  const { user, loading } = useAuth()
  const location = useLocation()

  if (loading) return <div className="auth-loading" role="status">Checking your session…</div>
  if (!user) return <Navigate to="/login" replace state={{ from: location }} />
  if (roles && !roles.includes(user.role)) return <Navigate to={roleHome[user.role] || '/'} replace />

  return children
}

export default ProtectedRoute
