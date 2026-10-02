import React, { useEffect, useRef, useState } from 'react'
import { NavLink, useNavigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { useTranslation } from 'react-i18next'
import api from '../services/api'

interface Branch {
  id: number
  name: string
  code: string
  is_active: boolean
}

interface PatientMatch {
  id: number
  mrn: string
  name: string
}

interface NavItem {
  to: string
  label: string
  end?: boolean
  visible: (permissions: string[], role: string) => boolean
}

const NAV_ITEMS: NavItem[] = [
  {
    to: '/',
    label: 'Dashboard',
    end: true,
    visible: () => true,
  },
  {
    to: '/patients',
    label: 'Patients',
    visible: (p) => p.includes('patients.view'),
  },
  {
    to: '/appointments',
    label: 'Appointments',
    visible: (p) => p.includes('appointments.view'),
  },
  {
    to: '/queue',
    label: 'Queue',
    visible: (p) => p.includes('appointments.view'),
  },
  {
    to: '/encounters',
    label: 'Clinical / EMR',
    visible: (p) => p.includes('encounters.view'),
  },
  {
    to: '/lab',
    label: 'Lab',
    visible: (p) => p.includes('lab.verify') || p.includes('prescriptions.view'),
  },
  {
    to: '/pharmacy',
    label: 'Pharmacy',
    visible: (p) => p.includes('pharmacy.view'),
  },
  {
    to: '/billing',
    label: 'Billing',
    visible: (p) => p.includes('billing.view'),
  },
  {
    to: '/crm',
    label: 'CRM',
    visible: (p) => p.includes('crm.view'),
  },
  {
    to: '/reports',
    label: 'Reports',
    visible: (p) => p.includes('reports.view'),
  },
  {
    to: '/notifications',
    label: 'Notifications',
    visible: (p) => p.includes('notifications.view'),
  },
  {
    to: '/audit',
    label: 'Audit',
    visible: (p, role) => role === 'Admin' || p.includes('audit.view'),
  },
  {
    to: '/settings',
    label: 'Settings',
    visible: (p, role) => role === 'Admin' || p.includes('*'),
  },
]

const iconPath = {
  dashboard: 'M3 12l9-9 9 9M5 10v10h5v-6h4v6h5V10',
  patients: 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4z',
  search: 'M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z',
  bell: 'M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 00-4-5.66V4a2 2 0 10-4 0v1.34A6 6 0 006 11v3.2c0 .53-.21 1.04-.6 1.4L4 17h5m6 0v1a3 3 0 11-6 0v-1',
  user: 'M5.12 17.84a8 8 0 0113.76 0M12 11a4 4 0 100-8 4 4 0 000 8z',
  logout: 'M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1',
}

export const AppShell: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const { t } = useTranslation()
  const [branches, setBranches] = useState<Branch[]>([])
  const [activeBranch, setActiveBranch] = useState<Branch | null>(null)
  const [search, setSearch] = useState('')
  const [results, setResults] = useState<PatientMatch[]>([])
  const [searching, setSearching] = useState(false)
  const searchBoxRef = useRef<HTMLDivElement>(null)

  const perms = user?.permissions ?? []
  const role = user?.role ?? ''

  useEffect(() => {
    api
      .get('/branches')
      .then((res) => {
        const list: Branch[] = res.data.data ?? res.data ?? []
        setBranches(list)
        setActiveBranch(list.find((b) => b.id === user?.branch_id) ?? list[0] ?? null)
      })
      .catch(() => {
        /* branch context is optional in single-clinic deployments */
      })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  useEffect(() => {
    const close = (e: MouseEvent) => {
      if (searchBoxRef.current && !searchBoxRef.current.contains(e.target as Node)) {
        setResults([])
      }
    }
    document.addEventListener('mousedown', close)
    return () => document.removeEventListener('mousedown', close)
  }, [])

  useEffect(() => {
    if (search.trim().length < 2) {
      setResults([])
      return
    }
    setSearching(true)
    const timer = window.setTimeout(() => {
      api
        .get('/patients', { params: { search, per_page: 6 } })
        .then((res) => setResults((res.data.data ?? []).map((p: Partial<PatientMatch> & { mrn?: string }) => ({ id: p.id!, mrn: p.mrn ?? '', name: p.name! }))))
        .catch(() => setResults([]))
        .finally(() => setSearching(false))
    }, 250)
    return () => window.clearTimeout(timer)
  }, [search])

  const visibleNav = NAV_ITEMS.filter((item) => item.visible(perms, role))

  return (
    <div className="flex min-h-screen bg-clinical-slate-50">
      <aside className="sticky top-0 flex h-screen w-64 flex-shrink-0 flex-col border-r border-clinical-slate-200 bg-white">
        <div className="flex h-16 items-center gap-2 border-b border-clinical-slate-200 px-5">
          <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-clinical-indigo-600 text-sm font-bold text-white">
            CS
          </span>
          <div className="min-w-0">
            <p className="truncate text-sm font-semibold text-clinical-slate-900">{t('app.name')}</p>
            <p className="truncate text-xs text-clinical-slate-400">{activeBranch?.name ?? t('patients.search_placeholder').split('…')[0]}</p>
          </div>
        </div>

        <nav className="flex-1 overflow-y-auto p-3 space-y-0.5" aria-label="Main navigation">
          {visibleNav.map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              end={item.end}
              className={({ isActive }) =>
                `flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors ${
                  isActive ? 'bg-clinical-indigo-50 text-clinical-indigo-700' : 'text-clinical-slate-600 hover:bg-clinical-slate-100'
                }`
              }
            >
              {item.label}
            </NavLink>
          ))}
        </nav>

        <div className="border-t border-clinical-slate-200 p-3">
          <div className="flex items-center gap-3 rounded-md px-2 py-2">
            <div className="flex h-9 w-9 flex-none items-center justify-center rounded-full bg-clinical-slate-100 text-sm font-semibold text-clinical-slate-700">
              {(user?.name ?? 'U').slice(0, 1).toUpperCase()}
            </div>
            <div className="min-w-0 flex-1">
              <p className="truncate text-sm font-medium text-clinical-slate-800">{user?.name}</p>
              <p className="truncate text-xs text-clinical-slate-400">{role}</p>
            </div>
            <button
              onClick={() => logout()}
              aria-label="Sign out"
              title="Sign out"
              className="rounded-md p-1.5 text-clinical-slate-400 hover:bg-clinical-slate-100 hover:text-clinical-slate-700"
            >
              <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d={iconPath.logout} />
              </svg>
            </button>
          </div>
        </div>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-20 flex h-16 items-center gap-4 border-b border-clinical-slate-200 bg-white px-6">
          <div ref={searchBoxRef} className="relative w-full max-w-md">
            <div className="relative">
              <span className="pointer-events-none absolute inset-y-0 left-3 flex items-center text-clinical-slate-400">
                <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d={iconPath.search} />
                </svg>
              </span>
              <input
                type="search"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Global patient search (MRN, name)…"
                aria-label="Global patient search"
                className="w-full rounded-lg border border-clinical-slate-300 bg-white py-2 pl-9 pr-3 text-sm text-clinical-slate-900 shadow-sm placeholder:text-clinical-slate-400 focus:border-clinical-indigo-500 focus:outline-none focus:ring-2 focus:ring-clinical-indigo-500"
              />
              {searching && <span className="absolute inset-y-0 right-3 flex items-center text-clinical-slate-400">…</span>}
            </div>
            {results.length > 0 && (
              <div className="absolute z-30 mt-1 w-full overflow-hidden rounded-lg border border-clinical-slate-200 bg-white shadow-lg">
                {results.map((p) => (
                  <button
                    key={p.id}
                    onClick={() => {
                      navigate(`/patients/${p.id}`)
                      setSearch('')
                      setResults([])
                    }}
                    className="flex w-full items-center justify-between px-4 py-2.5 text-left text-sm hover:bg-clinical-slate-50"
                  >
                    <span className="font-medium text-clinical-slate-800">{p.name}</span>
                    <span className="text-xs text-clinical-slate-400">{p.mrn}</span>
                  </button>
                ))}
              </div>
            )}
          </div>

          <div className="ml-auto flex items-center gap-3">
            {branches.length > 1 && (
              <select
                value={activeBranch?.id ?? ''}
                onChange={(e) => setActiveBranch(branches.find((b) => b.id === Number(e.target.value)) ?? null)}
                aria-label="Clinic context"
                className="rounded-lg border border-clinical-slate-300 bg-white px-3 py-1.5 text-sm text-clinical-slate-700"
              >
                {branches.map((b) => (
                  <option key={b.id} value={b.id}>
                    {b.name}
                  </option>
                ))}
              </select>
            )}
            <button
              onClick={() => navigate('/notifications')}
              aria-label="Notifications"
              className="relative rounded-md p-2 text-clinical-slate-500 hover:bg-clinical-slate-100 hover:text-clinical-slate-700"
            >
              <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d={iconPath.bell} />
              </svg>
            </button>
          </div>
        </header>

        <main className="flex-1 p-6">{children}</main>
      </div>
    </div>
  )
}