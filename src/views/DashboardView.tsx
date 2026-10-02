import React, { useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import api from '../services/api'

interface StatCardData {
  label: string
  value: string | number
  hint?: string
  to?: string
}

const ADMIN_ROLE = 'Admin'

export const DashboardView: React.FC = () => {
  const { user } = useAuth()
  const navigate = useNavigate()
  const role = user?.role ?? ''
  const isAdmin = role === ADMIN_ROLE

  const [metrics, setMetrics] = useState<Record<string, unknown>>({})
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    let active = true

    Promise.allSettled([
      api.get('/appointments?per_page=100'),
      api.get('/patients?per_page=1'),
      api.get('/reports/financial'),
      api.get('/reports/visits'),
      api.get('/reports/low-stock'),
      api.get('/reports/lab-tests'),
    ]).then(([appointments, patients, financial, visits, lowStock, labTests]) => {
      if (!active) return

      const appointmentsData = (appointments.status === 'fulfilled' ? appointments.value.data.data : []) as Array<{
        scheduled_at?: string
        date?: string
        status?: string
      }>
      const today = new Date().toISOString().slice(0, 10)
      const todaysBookings = appointmentsData.filter((a) => (a.scheduled_at ?? a.date ?? '').slice(0, 10) === today).length

      const financialRows = (financial.status === 'fulfilled' ? financial.value.data.data : []) as Array<{
        label: string
        total: number
      }>
      const revenueToday = financialRows.find((r) => r.label === 'Revenue Today')?.total ?? 0
      const revenueMonth = financialRows.find((r) => r.label === 'Revenue This Month')?.total ?? 0
      const outstanding = financialRows.find((r) => r.label === 'Outstanding Balance')?.total ?? 0

      const visitsRows = (visits.status === 'fulfilled' ? visits.value.data.data : []) as Array<{
        date: string
        count: number
      }>
      const visitsToday = visitsRows.find((v) => v.date === today)?.count ?? 0

      const lowStockItems = (lowStock.status === 'fulfilled' ? lowStock.value.data.data?.items : null) as Array<Record<string, unknown>> | null

      const patientsMeta = (patients.status === 'fulfilled' ? patients.value.data.meta : null) as { total?: number } | null
      const patientCount = patientsMeta?.total ?? 0

      const labRows = (labTests.status === 'fulfilled' ? labTests.value.data.data : []) as Array<{ count: number }>
      const labCount = labRows.reduce((sum, row) => sum + (Number(row.count) || 0), 0)

      setMetrics({
        todaysBookings,
        revenueToday: Number(revenueToday),
        revenueMonth: Number(revenueMonth),
        outstanding: Number(outstanding),
        visitsToday,
        lowStockCount: lowStockItems?.length ?? null,
        patientCount,
        labCount,
      })
      setLoading(false)
    })

    return () => {
      active = false
    }
  }, [])

  const cards = useMemo(() => {
    const base: StatCardData[] = []
    const m = metrics as Record<string, number | null>

    if (isAdmin) {
      base.push(
        { label: 'Patients', value: m.patientCount ?? '—', to: '/patients' },
        { label: 'Bookings today', value: m.todaysBookings ?? '—', to: '/appointments' },
        { label: 'Revenue today', value: m.revenueToday != null ? `$${m.revenueToday.toFixed(2)}` : '—', to: '/billing' },
        { label: 'Revenue (month)', value: m.revenueMonth != null ? `$${m.revenueMonth.toFixed(2)}` : '—', to: '/reports' },
        { label: 'Outstanding', value: m.outstanding != null ? `$${m.outstanding.toFixed(2)}` : '—', to: '/billing' },
        { label: 'Low stock alerts', value: m.lowStockCount ?? '—', to: '/pharmacy' },
      )
    } else if (role === 'Doctor') {
      base.push(
        { label: 'Visits today', value: m.visitsToday ?? '—', to: '/appointments' },
        { label: 'Bookings today', value: m.todaysBookings ?? '—', to: '/appointments' },
        { label: 'Lab workload', value: m.labCount ?? '—', to: '/lab' },
        { label: 'Patients', value: m.patientCount ?? '—', to: '/patients' },
        { label: 'Low stock alerts', value: m.lowStockCount ?? '—', to: '/pharmacy' },
      )
    } else if (role === 'Receptionist') {
      base.push(
        { label: 'Bookings today', value: m.todaysBookings ?? '—', to: '/appointments' },
        { label: 'Patients', value: m.patientCount ?? '—', to: '/patients' },
        { label: 'Visits today', value: m.visitsToday ?? '—', to: '/queue' },
      )
    } else if (role === 'Accountant') {
      base.push(
        { label: 'Revenue today', value: m.revenueToday != null ? `$${m.revenueToday.toFixed(2)}` : '—', to: '/billing' },
        { label: 'Revenue (month)', value: m.revenueMonth != null ? `$${m.revenueMonth.toFixed(2)}` : '—', to: '/reports' },
        { label: 'Outstanding', value: m.outstanding != null ? `$${m.outstanding.toFixed(2)}` : '—', to: '/billing' },
      )
    } else if (role === 'Pharmacist') {
      base.push(
        { label: 'Low stock alerts', value: m.lowStockCount ?? '—', to: '/pharmacy' },
        { label: 'Patients', value: m.patientCount ?? '—', to: '/patients' },
        { label: 'Lab workload', value: m.labCount ?? '—', to: '/lab' },
      )
    } else {
      base.push({ label: 'Bookings today', value: m.todaysBookings ?? '—', to: '/appointments' })
    }

    return base.filter((card) => card.value !== null)
  }, [metrics, isAdmin, role])

  const dateLabel = new Date().toLocaleDateString(undefined, {
    weekday: 'long',
    month: 'long',
    day: 'numeric',
  })

  return (
    <div className="mx-auto max-w-6xl">
      <div className="mb-6 flex items-start justify-between">
        <div>
          <p className="text-sm text-clinical-slate-400">{dateLabel}</p>
          <h1 className="mt-1 text-2xl font-semibold text-clinical-slate-900">
            Good {new Date().getHours() < 12 ? 'morning' : new Date().getHours() < 18 ? 'afternoon' : 'evening'},{' '}
            {user?.name?.split(' ')[0]}
          </h1>
        </div>
        <span className="rounded-full bg-clinical-indigo-50 px-3 py-1 text-xs font-medium text-clinical-indigo-700">
          {role}
        </span>
      </div>

      {loading ? (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {Array.from({ length: 6 }).map((_, i) => (
            <div key={i} className="clinical-card h-28 animate-pulse bg-clinical-slate-100" />
          ))}
        </div>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {cards.map((card) => (
            <button
              key={card.label}
              onClick={() => card.to && navigate(card.to)}
              className="clinical-card flex flex-col items-start gap-2 p-5 text-left transition-shadow hover:shadow-md"
            >
              <p className="text-sm text-clinical-slate-500">{card.label}</p>
              <p className="text-2xl font-semibold text-clinical-slate-900">{card.value}</p>
              {card.hint && <p className="text-xs text-clinical-slate-400">{card.hint}</p>}
            </button>
          ))}
        </div>
      )}

      {isAdmin && (
        <div className="clinical-card mt-6 p-5">
          <h2 className="mb-3 text-sm font-semibold text-clinical-slate-900">Quick actions</h2>
          <div className="flex flex-wrap gap-2">
            <button onClick={() => navigate('/appointments')} className="rounded-lg border border-clinical-slate-200 px-3 py-1.5 text-sm font-medium text-clinical-slate-700 hover:border-clinical-indigo-300 hover:text-clinical-indigo-700">
              Schedule appointment
            </button>
            <button onClick={() => navigate('/patients/new')} className="rounded-lg border border-clinical-slate-200 px-3 py-1.5 text-sm font-medium text-clinical-slate-700 hover:border-clinical-indigo-300 hover:text-clinical-indigo-700">
              Register patient
            </button>
            <button onClick={() => navigate('/audit')} className="rounded-lg border border-clinical-slate-200 px-3 py-1.5 text-sm font-medium text-clinical-slate-700 hover:border-clinical-indigo-300 hover:text-clinical-indigo-700">
              Review audit log
            </button>
          </div>
        </div>
      )}
    </div>
  )
}