import React, { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import api from '../../services/api'
import { bootstrapCsrf } from '../../services/api'
import { Field, Select, TextArea, TextInput } from '../../components/ui/FormSection'
import { useTranslation } from 'react-i18next'

interface PublicDoctor {
  id: number
  name: string
  schedules: Array<{ day_of_week: number; start_time: string; end_time: string }>
}

interface BookingForm {
  name: string
  phone: string
  email: string
  service_interest: string
  preferred_doctor_id: string
  preferred_date: string
  preferred_time: string
  message: string
}

const EMPTY: BookingForm = {
  name: '',
  phone: '',
  email: '',
  service_interest: '',
  preferred_doctor_id: '',
  preferred_date: '',
  preferred_time: '',
  message: '',
}

export const PublicBooking: React.FC = () => {
  const { t } = useTranslation()
  const [services, setServices] = useState<string[]>([])
  const [doctors, setDoctors] = useState<PublicDoctor[]>([])
  const [form, setForm] = useState<BookingForm>(EMPTY)
  const [submitting, setSubmitting] = useState(false)
  const [done, setDone] = useState<{ message: string; reference?: string } | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    Promise.allSettled([
      api.get('/public/services'),
      api.get('/public/doctors'),
    ]).then(([svcRes, docsRes]) => {
      if (svcRes.status === 'fulfilled') setServices(svcRes.value.data.data?.services ?? [])
      if (docsRes.status === 'fulfilled') setDoctors(docsRes.value.data.data ?? [])
    })
  }, [])

  const set = (patch: Partial<BookingForm>) => setForm((f) => ({ ...f, ...patch }))

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setSubmitting(true)
    setError(null)
    try {
      await bootstrapCsrf()
      const res = await api.post('/public/booking', {
        name: form.name.trim(),
        phone: form.phone.trim(),
        email: form.email.trim() || null,
        service_interest: form.service_interest || null,
        preferred_doctor_id: form.preferred_doctor_id ? Number(form.preferred_doctor_id) : null,
        preferred_date: form.preferred_date || null,
        preferred_time: form.preferred_time || null,
        message: form.message.trim() || null,
      })
      setDone({ message: res.data.message ?? t('public.success_received'), reference: res.data.data?.status })
      setForm(EMPTY)
    } catch {
      setError(t('public.error_complete'))
    } finally {
      setSubmitting(false)
    }
  }

  if (done) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-clinical-slate-50 px-6">
        <div className="w-full max-w-md rounded-2xl bg-white p-8 text-center shadow-sm">
          <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-clinical-teal-50 text-2xl text-clinical-teal-600">
            ✓
          </div>
          <h1 className="text-xl font-semibold text-clinical-slate-900">{t('public.request_received')}</h1>
          <p className="mt-2 text-sm text-clinical-slate-500">{done.message}</p>
          <Link to="/public" className="mt-6 inline-block rounded-lg bg-clinical-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-clinical-indigo-700">
            {t('public.back')}
          </Link>
        </div>
      </div>
    )
  }

  return (
    <div className="min-h-screen bg-clinical-slate-50">
      <header className="border-b border-clinical-slate-100 bg-white">
        <div className="mx-auto flex max-w-3xl items-center justify-between px-6 py-4">
          <Link to="/public" className="text-sm font-semibold text-clinical-slate-700 hover:text-clinical-indigo-600">
            {t('public.back')}
          </Link>
          <span className="text-sm font-semibold text-clinical-slate-900">{t('public.request_appointment')}</span>
        </div>
      </header>

      <main className="mx-auto w-full max-w-3xl px-6 py-10">
        {error && (
          <div className="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
            {error}
          </div>
        )}

        <form onSubmit={handleSubmit} className="grid grid-cols-1 gap-5 rounded-2xl bg-white p-6 shadow-sm sm:p-8 md:grid-cols-2" noValidate>
          <Field label={t('public.full_name')} required htmlFor="book-name">
            <TextInput id="book-name" value={form.name} onChange={(e) => set({ name: e.target.value })} placeholder="Your full name" required autoComplete="name" />
          </Field>
          <Field label={t('public.phone_whatsapp')} required htmlFor="book-phone">
            <TextInput id="book-phone" value={form.phone} onChange={(e) => set({ phone: e.target.value })} placeholder="+92 300 0000000" required autoComplete="tel" />
          </Field>
          <Field label={t('public.email')} htmlFor="book-email">
            <TextInput id="book-email" type="email" value={form.email} onChange={(e) => set({ email: e.target.value })} placeholder="name@example.com" autoComplete="email" />
          </Field>
          <Field label={t('public.service_of_interest')} htmlFor="book-service">
            <Select id="book-service" value={form.service_interest} onChange={(e) => set({ service_interest: e.target.value })}>
              <option value="">Select a service…</option>
              {services.map((s) => (
                <option key={s} value={s}>
                  {s}
                </option>
              ))}
            </Select>
          </Field>
          <Field label={t('public.preferred_doctor')} htmlFor="book-doctor">
            <Select id="book-doctor" value={form.preferred_doctor_id} onChange={(e) => set({ preferred_doctor_id: e.target.value })}>
              <option value="">No preference</option>
              {doctors.map((d) => (
                <option key={d.id} value={d.id}>
                  {d.name}
                </option>
              ))}
            </Select>
          </Field>
          <Field label={t('public.preferred_date')} htmlFor="book-date">
            <TextInput id="book-date" type="date" value={form.preferred_date} onChange={(e) => set({ preferred_date: e.target.value })} />
          </Field>
          <Field label={t('public.preferred_time')} htmlFor="book-time">
            <TextInput id="book-time" type="time" value={form.preferred_time} onChange={(e) => set({ preferred_time: e.target.value })} />
          </Field>
          <div className="md:col-span-2">
            <Field label="Message (optional)" htmlFor="book-message">
              <TextArea id="book-message" rows={3} value={form.message} onChange={(e) => set({ message: e.target.value })} placeholder="Anything we should know?" />
            </Field>
          </div>
          <div className="md:col-span-2">
            <button
              type="submit"
              disabled={submitting}
              className="w-full rounded-lg bg-clinical-indigo-600 px-5 py-3 text-sm font-semibold text-white hover:bg-clinical-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
            >
              {submitting ? 'Sending request…' : t('public.send_booking')}
            </button>
            <p className="mt-3 text-center text-xs text-clinical-slate-400">
              {t('public.confirm_message')}
            </p>
          </div>
        </form>
      </main>
    </div>
  )
}
