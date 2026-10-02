import React, { useEffect, useState } from 'react'
import { useAuth } from '../context/AuthContext'
import { useTranslation } from 'react-i18next'
import api from '../services/api'
import { StatusBadge } from '../components/ui/StatusBadge'
import { EmptyState, LoadingState } from '../components/ui/States'

interface PortalAppointment {
  id: number
  scheduled_at?: string
  doctor_name?: string
  status?: string
  reason?: string
}

interface PortalPrescriptionItem {
  medicine_name?: string
  dosage?: string
}

interface PortalPrescription {
  id: number
  prescription_number?: string
  doctor_name?: string
  issued_at?: string
  status?: string
  notes?: string
  items?: PortalPrescriptionItem[]
}

interface PortalInvoice {
  id: number
  invoice_number?: string
  issued_at?: string
  total?: number
  balance_due?: number
  status?: string
}

export const PortalView: React.FC = () => {
  const { user, logout } = useAuth()
  const { t } = useTranslation()
  const [appointments, setAppointments] = useState<PortalAppointment[]>([])
  const [prescriptions, setPrescriptions] = useState<PortalPrescription[]>([])
  const [invoices, setInvoices] = useState<PortalInvoice[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    Promise.allSettled([
      api.get('/portal/appointments'),
      api.get('/portal/prescriptions'),
      api.get('/portal/invoices'),
    ]).then(([appts, scripts, bills]) => {
      if (appts.status === 'fulfilled') setAppointments(appts.value.data.data ?? [])
      if (scripts.status === 'fulfilled') setPrescriptions(scripts.value.data.data ?? [])
      if (bills.status === 'fulfilled') setInvoices(bills.value.data.data ?? [])
      setLoading(false)
    })
  }, [])

  const upcoming = appointments.filter((a) => a.status === 'confirmed' || a.status === 'scheduled')

  return (
    <div className="min-h-screen bg-clinical-slate-50">
      <header className="border-b border-clinical-slate-200 bg-white">
        <div className="mx-auto flex max-w-5xl items-center justify-between px-6 py-4">
          <div className="flex items-center gap-2">
            <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-clinical-indigo-600 text-sm font-bold text-white">CS</span>
            <span className="font-semibold text-clinical-slate-900">{t('portal.title')}</span>
          </div>
          <div className="flex items-center gap-3">
            <span className="text-sm text-clinical-slate-600">{user?.name}</span>
            <button onClick={() => logout()} className="text-sm text-clinical-slate-500 hover:text-clinical-slate-800">
              {t('common.sign_out')}
            </button>
          </div>
        </div>
      </header>

      <main className="mx-auto max-w-5xl px-6 py-8">
        <h1 className="mb-6 text-2xl font-semibold text-clinical-slate-900">{t('portal.subtitle')}</h1>

        {loading ? (
          <LoadingState label={t('portal.loading_records')} />
        ) : (
          <div className="grid gap-6 md:grid-cols-2">
            <section className="clinical-card p-5">
              <h2 className="mb-4 text-sm font-semibold text-clinical-slate-900">{t('portal.upcoming_appointments')}</h2>
              {appointments.length === 0 ? (
                <p className="text-sm text-clinical-slate-400">{t('portal.no_appointments')}</p>
              ) : (
                <ul className="space-y-3">
                  {appointments.map((a) => (
                    <li key={a.id} className="flex items-center justify-between rounded-lg border border-clinical-slate-100 px-3 py-2.5">
                      <div>
                        <p className="text-sm font-medium text-clinical-slate-800">{a.doctor_name ?? t('portal.doctor')}</p>
                        <p className="text-xs text-clinical-slate-400">
                          {a.scheduled_at ? new Date(a.scheduled_at).toLocaleString() : '—'}
                        </p>
                        {a.reason && <p className="mt-0.5 text-xs text-clinical-slate-500">{a.reason}</p>}
                      </div>
                      {a.status && <StatusBadge status={a.status} />}
                    </li>
                  ))}
                </ul>
              )}
              {upcoming.length === 0 && appointments.length > 0 && (
                <p className="mt-3 text-xs text-clinical-slate-400">{t('portal.schedule_new')}</p>
              )}
            </section>

            <section className="clinical-card p-5">
              <h2 className="mb-4 text-sm font-semibold text-clinical-slate-900">{t('portal.prescriptions')}</h2>
              {prescriptions.length === 0 ? (
                <EmptyState title={t('portal.no_prescriptions')} />
              ) : (
                <ul className="space-y-3">
                  {prescriptions.map((p) => (
                    <li key={p.id} className="rounded-lg border border-clinical-slate-100 px-3 py-2.5">
                      <div className="flex items-center justify-between">
                        <p className="text-sm font-medium text-clinical-slate-800">
                          #{p.prescription_number ?? p.id}
                          {p.doctor_name ? ` · ${p.doctor_name}` : ''}
                        </p>
                        {p.status && <StatusBadge status={p.status} />}
                      </div>
                      {p.items && p.items.length > 0 && (
                        <ul className="mt-2 space-y-1">
                          {p.items.map((it, i) => (
                            <li key={i} className="text-xs text-clinical-slate-600">
                              {it.medicine_name}
                              {it.dosage ? ` — ${it.dosage}` : ''}
                            </li>
                          ))}
                        </ul>
                      )}
                      <p className="mt-1 text-xs text-clinical-slate-400">
                        {p.issued_at ? `Issued ${new Date(p.issued_at).toLocaleDateString()}` : ''}
                      </p>
                    </li>
                  ))}
                </ul>
              )}
            </section>

            <section className="clinical-card p-5 md:col-span-2">
              <h2 className="mb-4 text-sm font-semibold text-clinical-slate-900">{t('portal.invoices')}</h2>
              {invoices.length === 0 ? (
                <EmptyState title={t('portal.no_invoices')} />
              ) : (
                <ul className="divide-y divide-clinical-slate-100">
                  {invoices.map((i) => {
                    const due = (i.balance_due ?? 0) > 0
                    return (
                      <li key={i.id} className="flex items-center justify-between py-2.5">
                        <div>
                          <p className="text-sm font-medium text-clinical-slate-800">
                            {i.invoice_number ?? `Invoice #${i.id}`}
                          </p>
                          <p className="text-xs text-clinical-slate-400">
                            {i.issued_at ? new Date(i.issued_at).toLocaleDateString() : ''}
                            {due ? ` · Balance due $${Number(i.balance_due).toFixed(2)}` : ' · Paid in full'}
                          </p>
                        </div>
                        <div className="flex items-center gap-3">
                          {i.status && <StatusBadge status={i.status} />}
                          <span className="text-sm font-medium text-clinical-slate-700">
                            {i.total !== undefined ? `$${Number(i.total).toFixed(2)}` : ''}
                          </span>
                        </div>
                      </li>
                    )
                  })}
                </ul>
              )}
            </section>
          </div>
        )}
      </main>
    </div>
  )
}