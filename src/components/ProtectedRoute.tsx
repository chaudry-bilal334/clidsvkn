import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'

interface ProtectedRouteProps {
  allowedRoles?: string[]
  allowedPermissions?: string[]
}

export const ProtectedRoute = ({ allowedRoles, allowedPermissions }: ProtectedRouteProps) => {
  const { user, isAuthenticated } = useAuth()
  const location = useLocation()

  if (!isAuthenticated) {
    return <Navigate to="/login" replace state={{ from: location.pathname }} />
  }

  if (allowedRoles && !allowedRoles.includes(user?.role || '')) {
    return <Navigate to="/dashboard" replace />
  }

  if (allowedPermissions && !allowedPermissions.some((perm) => user?.permissions.includes(perm))) {
    return <Navigate to="/dashboard" replace />
  }

  return <Outlet />
}