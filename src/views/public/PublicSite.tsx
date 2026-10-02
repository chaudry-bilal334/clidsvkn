import React, { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import api from '../../services/api'
import { LoadingState } from '../../components/ui/States'
import { useTranslation } from 'react-i18next'

const DAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']

interface ClinicInfo {
  name?: string
  city?: string
  address?: string
  phone?: string
  services?: string[]
}

interface PublicDoctor {
  id: number
  name: string
  schedules: Array<{ day_of_week: number; start_time: string; end_time: string }>
}

const formatTime = (t: string): string => {
  const [h, m] = t.split(':').map(Number)
  const d = new Date()
  d.setHours(h ?? 0, m ?? 0)
  return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
}

export const PublicSite: React.FC = () => {
  const { t } = useTranslation()
  const [info, setInfo] = useState<ClinicInfo | null>(null)
  const [doctors, setDoctors] = useState<PublicDoctor[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    Promise.allSettled([
      api.get('/public/clinic/info'),
      api.get('/public/doctors'),
    ]).then(([infoRes, docsRes]) => {
      if (infoRes.status === 'fulfilled') setInfo(infoRes.value.data.data ?? null)
      if (docsRes.status === 'fulfilled') setDoctors(docsRes.value.data.data ?? [])
      setLoading(false)
    })
  }, [])

  if (loading) return <LoadingState label="Loading clinic information…" />

  const services = info?.services ?? []

  return (
    <div className="min-h-screen bg-white text-clinical-slate-900">
      <header className="border-b border-clinical-slate-100">
        <div className="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
          <Link to="/public" className="flex items-center gap-2">
            <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-clinical-indigo-600 text-sm font-bold text-white">
              {info?.name?.[0] ?? 'CS'}
            </span>
            <span className="text-lg font-semibold">{info?.name ?? t('public.hero_name_fallback')}</span>
          </Link>
          <nav className="flex items-center gap-6 text-sm font-medium text-clinical-slate-600">
            <a href="#services" className="hover:text-clinical-indigo-600">{t('public.nav.services')}</a>
            <a href="#doctors" className="hover:text-clinical-indigo-600">{t('public.nav.doctors')}</a>
            <Link to="/public/booking" className="rounded-lg bg-clinical-indigo-600 px-4 py-2 font-semibold text-white hover:bg-clinical-indigo-700">
              {t('public.nav.booking')}
            </Link>
          </nav>
        </div>
      </header>

      <section className="bg-gradient-to-br from-clinical-indigo-50 via-white to-clinical-teal-50">
        <div className="mx-auto max-w-6xl px-6 py-20 text-center">
          <h1 className="mx-auto max-w-2xl text-4xl font-bold leading-tight text-clinical-slate-900">
            {info?.name ?? t('public.hero_name_fallback')}
          </h1>
          <p className="mx-auto mt-4 max-w-xl text-lg text-clinical-slate-500">
            {info?.city ?? ''}{info?.city ? ' · ' : ''}{t('public.hero_walkins')}
          </p>
          <div className="mt-8 flex flex-wrap items-center justify-center gap-4">
            {info?.phone && (
              <a href={`tel:${info.phone}`} className="rounded-lg border border-clinical-slate-200 px-5 py-2.5 text-sm font-semibold text-clinical-slate-700 hover:border-clinical-indigo-300">
                Call {info.phone}
              </a>
            )}
            <Link to="/public/booking" className="rounded-lg bg-clinical-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-clinical-indigo-700">
              Request a visit
            </Link>
            <Link to="/" className="px-2 text-sm text-clinical-slate-500 hover:text-clinical-indigo-600">
              {t('public.nav.sign_in')} 
            </Link>
          </div>
        </div>
      </section>

      <section id="services" className="mx-auto max-w-6xl px-6 py-16">
        <h2 className="mb-8 text-center text-2xl font-semibold">Services</h2>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {services.map((service) => (
            <div key={service} className="rounded-xl border border-clinical-slate-100 bg-white p-5 shadow-sm">
              <p className="text-sm font-medium text-clinical-slate-800">{service}</p>
            </div>
          ))}
        </div>
      </section>

      <section id="doctors" className="bg-clinical-slate-50">
        <div className="mx-auto max-w-6xl px-6 py-16">
          <h2 className="mb-8 text-center text-2xl font-semibold">Our doctors</h2>
          {doctors.length === 0 ? (
            <p className="text-center text-sm text-clinical-slate-400">Doctor schedules will appear here.</p>
          ) : (
            <div className="grid gap-4 md:grid-cols-2">
              {doctors.map((doctor) => (
                <div key={doctor.id} className="rounded-xl border border-clinical-slate-100 bg-white p-5 shadow-sm">
                  <p className="text-base font-semibold text-clinical-slate-900">{doctor.name}</p>
                  {doctor.schedules.length === 0 ? (
                    <p className="mt-1 text-sm text-clinical-slate-400">No published schedule yet.</p>
                  ) : (
                    <ul className="mt-2 space-y-1">
                      {doctor.schedules.map((s, i) => (
                        <li key={i} className="flex justify-between text-sm text-clinical-slate-600">
                          <span>{DAY_NAMES[s.day_of_week] ?? `Day ${s.day_of_week}`}</span>
                          <span className="text-clinical-slate-500">
                            {formatTime(s.start_time)} – {formatTime(s.end_time)}
                          </span>
                        </li>
                      ))}
                    </ul>
                  )}
                </div>
              ))}
            </div>
          )}
        </div>
      </section>

      <footer className="border-t border-clinical-slate-100 py-8">
        <div className="mx-auto max-w-6xl px-6 text-center text-sm text-clinical-slate-400">
          {info?.address && <p>{info.address}</p>}
          {info?.phone && <p className="mt-1">{info.phone}</p>}
          <p className="mt-3 text-xs">© {new Date().getFullYear()} {info?.name ?? 'Clinic Suite'}</p>
        </div>
      </footer>
    </div>
  )
}