import { BrowserRouter as Router, Route, Routes, useLocation } from 'react-router-dom'
import { AuthProvider, useAuth } from './context/AuthContext'
import { ToastProvider } from './components/ui/Toast'
import { I18nextProvider, useTranslation } from 'react-i18next'
import { useEffect, useState } from 'react'
import en from './locales/en.json'
import ur from './locales/ur.json'
import { AppShell } from './components/AppShell'
import { LoginView } from './views/LoginView'
import { DashboardView } from './views/DashboardView'
import { PatientsView } from './views/PatientsView'
import { PatientDetailView } from './views/PatientDetailView'
import { AppointmentsView } from './views/AppointmentsView'
import { QueueView } from './views/QueueView'
import { EncountersView } from './views/EncountersView'
import { PrescriptionsView } from './views/PrescriptionsView'
import { BillingView } from './views/BillingView'
import { InventoryView } from './views/InventoryView'
import { ReportsView } from './views/ReportsView'
import { NotificationsView } from './views/NotificationsView'
import { AuditLogsView } from './views/AuditLogsView'
import { LeadsView } from './views/LeadsView'
import { SettingsView } from './views/SettingsView'
import { PortalView } from './views/PortalView'
import { PublicSite } from './views/public/PublicSite'
import { PublicBooking } from './views/public/PublicBooking'

const LoadingScreen: React.FC = () => (
  <div className="flex min-h-screen items-center justify-center bg-clinical-slate-50">
    <div className="flex items-center gap-2 text-clinical-slate-400">
      <span className="h-3 w-3 animate-pulse rounded-full bg-clinical-indigo-500" />
      Loading Clinic Suite…
    </div>
  </div>
)

const StaffRoute: React.FC<{ children: React.ReactNode }> = ({ children }) => (
  <AppShell>{children}</AppShell>
)

const LocaleSwitcher: React.FC = () => {
  const { i18n } = useTranslation()
  const [locale, setLocale] = useState(() => {
    const persisted = typeof window !== 'undefined' ? localStorage.getItem('i18nextLocale') : null
    return persisted || i18n.language || 'en'
  })

  useEffect(() => {
    const root = document.documentElement
    root.setAttribute('dir', locale === 'ur' ? 'rtl' : 'ltr')
    localStorage.setItem('i18nextLocale', locale)
  }, [locale])

  return (
    <div className="hidden">
      <button
        onClick={() => {
          const next = locale === 'en' ? 'ur' : 'en'
          setLocale(next)
        }}
        aria-label="Switch language"
        className="sr-only"
      >
        {locale === 'en' ? 'Switch to Urdu' : 'Switch to English'}
      </button>
    </div>
  )
}

const AppRoutes: React.FC = () => {
  const { status, user } = useAuth()
  const location = useLocation()

  if (status === 'loading') return <LoadingScreen />

  if (status !== 'authenticated') {
    if (location.pathname.startsWith('/public')) {
      return (
        <Routes>
          <Route path="/public" element={<PublicSite />} />
          <Route path="/public/booking" element={<PublicBooking />} />
          <Route path="/public/*" element={<PublicSite />} />
        </Routes>
      )
    }
    return <LoginView />
  }

  if (user?.role === 'Patient') {
    return (
      <Routes>
        <Route path="*" element={<PortalView />} />
      </Routes>
    )
  }

  return (
    <Routes>
      <Route index element={<StaffRoute><DashboardView /></StaffRoute>} />
      <Route path="patients" element={<StaffRoute><PatientsView onPatientSelected={() => {}} onPatientSaved={() => {}} /></StaffRoute>} />
      <Route path="patients/:id" element={<StaffRoute><PatientDetailView /></StaffRoute>} />
      <Route path="appointments" element={<StaffRoute><AppointmentsView onAppointmentUpdated={() => {}} /></StaffRoute>} />
      <Route path="queue" element={<StaffRoute><QueueView /></StaffRoute>} />
      <Route path="encounters" element={<StaffRoute><EncountersView /></StaffRoute>} />
      <Route path="lab" element={<StaffRoute><PrescriptionsView /></StaffRoute>} />
      <Route path="pharmacy" element={<StaffRoute><InventoryView /></StaffRoute>} />
      <Route path="billing" element={<StaffRoute><BillingView onInvoiceGenerated={() => {}} /></StaffRoute>} />
      <Route path="crm" element={<StaffRoute><LeadsView /></StaffRoute>} />
      <Route path="reports" element={<StaffRoute><ReportsView /></StaffRoute>} />
      <Route path="notifications" element={<StaffRoute><NotificationsView /></StaffRoute>} />
      <Route path="audit" element={<StaffRoute><AuditLogsView /></StaffRoute>} />
      <Route path="settings" element={<StaffRoute><SettingsView /></StaffRoute>} />
      <Route path="*" element={<StaffRoute><DashboardView /></StaffRoute>} />
    </Routes>
  )
}

const App: React.FC = () => (
  <Router>
    <I18nextProvider
      lng="en"
      fallbackLng={['en', 'ur']}
      resources={{ en, ur }}
      interpolation={{ escapeValue: false }}
    >
      <AuthProvider>
        <ToastProvider>
          <AppRoutes />
          <LocaleSwitcher />
        </ToastProvider>
      </AuthProvider>
    </I18nextProvider>
  </Router>
)

export default App