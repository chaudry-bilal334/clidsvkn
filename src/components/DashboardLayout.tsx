import { useState, useEffect } from 'react'
import { useAuth } from '../context/AuthContext'
import { Navigate } from 'react-router-dom'

const sidebarLinks = [
  { key: 'dashboard', label: 'Dashboard', icon: 'dash', exact: true },
  { key: 'patients', label: 'Patients', icon: 'patients', exact: false },
  { key: 'appointments', label: 'Appointments', icon: 'appointments', exact: false },
  { key: 'billing', label: 'Billing', icon: 'billing', exact: false },
  { key: 'pharmacy', label: 'Pharmacy & Inventory', icon: 'pharmacy', exact: false },
  { key: 'reports', label: 'Reports', icon: 'reports', exact: false },
  { key: 'audit', label: 'Audit Logs', icon: 'audit', exact: false },
]

interface DashboardLayoutProps {
  children: React.ReactNode
}

export const DashboardLayout = ({ children }: DashboardLayoutProps) => {
  const { user, token } = useAuth()
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false)
  const [unreadNotifications, setUnreadNotifications] = useState(0)

  useEffect(() => {
    const handleNotification = () => {
    }
    window.addEventListener('auth-event', handleNotification)
    return () => window.removeEventListener('auth-event', handleNotification)
  }, [token])

  const userName = user?.name || 'User'
  const userRole = user?.role || ''

  const roleBadgeClass = userRole === 'Admin'
    ? 'bg-clinical-indigo-100 text-clinical-indigo-600'
    : userRole === 'Doctor'
      ? 'bg-clinical-teal-100 text-clinical-teal-600'
      : 'bg-clinical-slate-200 text-clinical-slate-600'

  return (
    <div className="min-h-screen bg-clinical-slate-50">
      <div className="flex min-h-screen">
        {/* Sidebar */}
        <aside className="w-64 bg-clinical-slate-800 flex-shrink-0 border-r border-clinical-slate-700 flex flex-col">
          <div className="h-16 flex items-center justify-between px-6">
            <h2 className="text-lg font-medium text-clinical-slate-100">ClinicSuite</h2>
            <button
              onClick={() => setSidebarCollapsed(!sidebarCollapsed)}
              className="p-2 rounded hover:bg-clinical-slate-700 transition-colors"
              aria-label="Toggle sidebar"
            >
              <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 12h16M4 18h16" />
              </svg>
            </button>
          </div>

          <nav className="flex-1 flex-col p-2 space-y-1">
            {sidebarLinks.map((link) => (
              <button
                key={link.key}
                onClick={() => setSidebarCollapsed(false)}
                className={`w-full flex items-center justify-between rounded-md px-3 py-2 text-sm font-medium transition-colors ${sidebarCollapsed ? 'hidden' : ''} hover:bg-clinical-slate-700`}
              >
                <span className="flex items-center gap-2">
                  <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d={link.icon} />
                  </svg>
                  <span>{link.label}</span>
                </span>
                <svg className="w-4 h-4 rtl:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                </svg>
              </button>
            ))}
          </nav>

          <div className="mt-auto p-2 border-t border-clinical-slate-700">
            <div className="text-xs text-clinical-slate-400">
              Version 1.0.0
            </div>
          </div>
        </aside>

        {/* Main Content */}
        <div className="flex-1 flex flex-col">
          <header className="h-14 bg-white border-b border-clinical-slate-200 flex items-center justify-between px-6">
            <div className="flex items-center gap-3">
              <button
                onClick={() => setSidebarCollapsed(true)}
                className="p-1 rounded hover:bg-clinical-slate-100 transition-colors d-block d-md-none"
                aria-label="Open sidebar"
              >
                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 12h16M4 18h16" />
                </svg>
              </button>
              <div className="flex items-center gap-2">
                <span className="text-clinical-slate-700 font-medium">{userName}</span>
                <span className={roleBadgeClass}>
                  {userRole}
                </span>
              </div>
            </div>

            <div className="flex items-center gap-3">
              <span className="text-clinical-slate-500 text-sm">
                {unreadNotifications} unread
              </span>
              <button
                onClick={() => setUnreadNotifications(0)}
                className="p-1 rounded hover:bg-clinical-slate-100 transition-colors"
                aria-label="Mark notifications as read"
              >
                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6l8 4L16 6m0 0l8 4-8 4M8 12l8 4-8 4M8 20l8-4-8-4" />
                </svg>
              </button>
            </div>
          </header>

          <main className="flex-1 overflow-y-auto p-6 bg-clinical-slate-50">
            {children}
          </main>
        </div>
      </div>
    </div>
  )
}