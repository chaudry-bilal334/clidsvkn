import React, { useEffect, useState } from 'react'
import { useAuth } from '../context/AuthContext'
import { useTranslation } from 'react-i18next'
import api from '../services/api'
import { StatusBadge } from '../components/ui/StatusBadge'
import { Field, Select, TextArea, TextInput } from '../components/ui/FormSection'
import { Modal } from '../components/ui/Modal'
import { useToast } from '../components/ui/Toast'
import { EmptyState, ErrorState, LoadingState } from '../components/ui/States'

const LEAD_STATUSES = ['new', 'contacted', 'qualified', 'converted', 'closed_lost']
const LEAD_SOURCES = ['website', 'referral', 'walk_in', 'call', 'event', 'other']
const COMM_CHANNELS = ['call', 'email', 'whatsapp', 'sms', 'visit', 'note', 'website']

interface LeadDto {
  id: number
  name: string
  email?: string
  phone?: string
  service_interest?: string
  preferred_doctor_id?: number | null
  preferred_doctor_name?: string | null
  preferred_date?: string | null
  preferred_time?: string | null
  source?: string
  status: string
  notes?: string
  assigned_to?: number | null
  assigned_to_name?: string | null
  created_at?: string
  communications?: CommunicationDto[]
}

interface CommunicationDto {
  id: number
  channel: string
  summary: string
  notes?: string | null
  user_name?: string
  occurred_at?: string
}

interface LeadOptions {
  doctors: Array<{ id: number; name: string }>
  staff: Array<{ id: number; name: string }>
}

interface LeadFormState {
  name: string
  email: string
  phone: string
  service_interest: string
  source: string
  preferred_doctor_id: string
  preferred_date: string
  preferred_time: string
  assigned_to: string
  notes: string
}

const EMPTY_FORM: LeadFormState = {
  name: '',
  email: '',
  phone: '',
  service_interest: '',
  source: 'walk_in',
  preferred_doctor_id: '',
  preferred_date: '',
  preferred_time: '',
  assigned_to: '',
  notes: '',
}

export const LeadsView: React.FC = () => {
  const { user } = useAuth()
  const { t } = useTranslation()
  const { toast } = useToast()
  const canManage = (user?.permissions ?? []).includes('crm.manage') || (user?.role ?? '') === 'Admin'

  const [leads, setLeads] = useState<LeadDto[]>([])
  const [options, setOptions] = useState<LeadOptions>({ doctors: [], staff: [] })
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('all')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const [showNew, setShowNew] = useState(false)
  const [form, setForm] = useState<LeadFormState>(EMPTY_FORM)
  const [saving, setSaving] = useState(false)

  const [selected, setSelected] = useState<LeadDto | null>(null)
  const [commForm, setCommForm] = useState({ channel: 'call', summary: '', notes: '' })
  const [transitioning, setTransitioning] = useState(false)

  const loadLeads = () => {
    setLoading(true)
    api
      .get('/leads', { params: { search: search || undefined, status: status === 'all' ? undefined : status } })
      .then((res) => setLeads(res.data.data ?? []))
      .catch(() => setError(t('leads.load_error')))
      .finally(() => setLoading(false))
  }

  useEffect(() => {
    api.get('/leads/options').then((res) => setOptions(res.data.data ?? { doctors: [], staff: [] })).catch(() => {})
  }, [])

  useEffect(() => {
    const timer = window.setTimeout(loadLeads, search ? 250 : 0)
    return () => window.clearTimeout(timer)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search, status])

  useEffect(() => {
    if (!selected) return
    api.get(`/leads/${selected.id}`).then((res) => setSelected(res.data.data ?? null)).catch(() => {})
  }, [selected?.id]) // eslint-disable-line react-hooks/exhaustive-deps

  const openLead = (lead: LeadDto) => setSelected(lead)

  const handleCreate = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    try {
      const payload = {
        ...form,
        name: form.name.trim(),
        email: form.email.trim() || null,
        phone: form.phone.trim() || null,
        service_interest: form.service_interest.trim() || null,
        preferred_doctor_id: form.preferred_doctor_id ? Number(form.preferred_doctor_id) : null,
        preferred_date: form.preferred_date || null,
        preferred_time: form.preferred_time || null,
        assigned_to: form.assigned_to ? Number(form.assigned_to) : null,
        notes: form.notes.trim() || null,
      }
      await api.post('/leads', payload)
      setShowNew(false)
      setForm(EMPTY_FORM)
      toast(t('leads.lead_added'), 'success')
      loadLeads()
    } catch (err: unknown) {
      const message = (err as { response?: { data?: { message?: string } } })?.response?.data?.message
      toast(message ?? t('leads.save_failed'), 'error')
    } finally {
      setSaving(false)
    }
  }

  const handleTransition = async (lead: LeadDto, nextStatus: string) => {
    setTransitioning(true)
    try {
      await api.patch(`/leads/${lead.id}/status`, { status: nextStatus })
      toast(t('leads.status_changed', { status: nextStatus.replace(/_/g, ' ') }), 'success')
      loadLeads()
      if (selected?.id === lead.id) {
        const res = await api.get(`/leads/${lead.id}`)
        setSelected(res.data.data ?? null)
      }
    } catch (err: unknown) {
      const message = (err as { response?: { data?: { message?: string } } })?.response?.data?.message
      toast(message ?? t('leads.status_change_failed'), 'error')
    } finally {
      setTransitioning(false)
    }
  }

  const handleAddCommunication = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!selected) return
    try {
      await api.post(`/leads/${selected.id}/communications`, {
        channel: commForm.channel,
        summary: commForm.summary.trim(),
        notes: commForm.notes.trim() || null,
      })
      setCommForm({ channel: 'call', summary: '', notes: '' })
      const res = await api.get(`/leads/${selected.id}`)
      setSelected(res.data.data ?? selected)
      toast(t('leads.communication_logged'), 'success')
    } catch {
      toast(t('leads.communication_failed'), 'error')
    }
  }

  if (error) return <ErrorState title={t('leads.unavailable')} message={error} onRetry={loadLeads} />

  return (
    <div className="mx-auto max-w-6xl">
      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-2xl font-semibold text-clinical-slate-900">{t('leads.title')}</h1>
        {canManage && (
          <button
            onClick={() => setShowNew(true)}
            className="rounded-lg bg-clinical-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-clinical-indigo-700"
          >
            {t('leads.new_lead')}
          </button>
        )}
      </div>

      <div className="mb-4 flex flex-wrap items-center gap-3">
        <input
          type="search"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder={t('leads.search_placeholder')}
          aria-label={t('leads.search_aria')}
          className="w-full max-w-sm rounded-lg border border-clinical-slate-300 bg-white px-3.5 py-2 text-sm shadow-sm placeholder:text-clinical-slate-400 focus:border-clinical-indigo-500 focus:outline-none focus:ring-2 focus:ring-clinical-indigo-100"
        />
        <select
          value={status}
          onChange={(e) => setStatus(e.target.value)}
          aria-label={t('leads.filter_status_aria')}
          className="rounded-lg border border-clinical-slate-300 bg-white px-3 py-2 text-sm text-clinical-slate-700 shadow-sm focus:outline-none"
        >
          <option value="all">{t('leads.all_statuses')}</option>
          {LEAD_STATUSES.map((s) => (
            <option key={s} value={s}>
              {t(`leads.status.${s}`) || s.replace(/_/g, ' ')}
            </option>
          ))}
        </select>
      </div>

      {loading ? (
        <LoadingState label={t('leads.loading')} />
      ) : leads.length === 0 ? (
        <EmptyState
          title={t('leads.no_leads')}
          description={search ? t('leads.adjust_search') : t('leads.create_first')}
        />
      ) : (
        <div className="clinical-card overflow-x-auto">
          <table className="w-full min-w-max divide-y divide-clinical-slate-100">
            <thead className="bg-clinical-slate-50">
              <tr>
                {['name', 'status', 'source', 'service', 'assigned_to', 'created'].map((h) => (
                  <th key={h} className="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-clinical-slate-500">
                    {t(`leads.table.${h}`)}
                  </th>
                ))}
                {canManage && (
                  <th className="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-clinical-slate-500">
                    {t('leads.table.move')}
                  </th>
                )}
              </tr>
            </thead>
            <tbody>
              {leads.map((lead) => (
                <tr key={lead.id} onClick={() => openLead(lead)} className="cursor-pointer hover:bg-clinical-slate-50">
                  <td className="px-5 py-3">
                    <p className="text-sm font-medium text-clinical-slate-800">{lead.name}</p>
                    <p className="text-xs text-clinical-slate-400">{lead.email ?? lead.phone}</p>
                  </td>
                  <td className="px-5 py-3">
                    <StatusBadge status={lead.status} />
                  </td>
                  <td className="px-5 py-3 text-sm capitalize text-clinical-slate-600">{lead.source ?? '—'}</td>
                  <td className="px-5 py-3 text-sm text-clinical-slate-600">{lead.service_interest ?? '—'}</td>
                  <td className="px-5 py-3 text-sm text-clinical-slate-600">{lead.assigned_to_name ?? t('leads.unassigned')}</td>
                  <td className="px-5 py-3 text-sm text-clinical-slate-500">
                    {lead.created_at ? new Date(lead.created_at).toLocaleDateString() : '—'}
                  </td>
                  {canManage && (
                    <td className="px-5 py-3" onClick={(e) => e.stopPropagation()}>
                      <select
                        value={lead.status}
                        onChange={(e) => handleTransition(lead, e.target.value)}
                        disabled={transitioning}
                        aria-label={t('leads.move_lead_aria', { name: lead.name })}
                        className="rounded-lg border border-clinical-slate-200 bg-white px-2 py-1 text-xs text-clinical-slate-700 focus:outline-none"
                      >
                        {LEAD_STATUSES.map((s) => (
                          <option key={s} value={s}>
                            {t(`leads.status.${s}`) || s.replace(/_/g, ' ')}
                          </option>
                        ))}
                      </select>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <Modal open={showNew} title={t('leads.new_lead_form')} onClose={() => setShowNew(false)} size="lg">
        <form onSubmit={handleCreate} className="grid grid-cols-1 gap-4 md:grid-cols-2">
          <Field label={t('leads.full_name')} required>
            <TextInput value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder={t('leads.name_placeholder')} required />
          </Field>
          <Field label={t('leads.source')}>
            <Select value={form.source} onChange={(e) => setForm({ ...form, source: e.target.value })}>
              {LEAD_SOURCES.map((s) => (
                <option key={s} value={s}>
                  {t(`leads.source.${s}`) || s.replace(/_/g, ' ')}
                </option>
              ))}
            </Select>
          </Field>
          <Field label={t('leads.email')}>
            <TextInput type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} placeholder="name@example.com" />
          </Field>
          <Field label={t('leads.phone')}>
            <TextInput value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} placeholder={t('leads.phone_placeholder')} />
          </Field>
          <Field label={t('leads.service_interest')}>
            <TextInput value={form.service_interest} onChange={(e) => setForm({ ...form, service_interest: e.target.value })} placeholder={t('leads.service_placeholder')} />
          </Field>
          <Field label={t('leads.preferred_doctor')}>
            <Select value={form.preferred_doctor_id} onChange={(e) => setForm({ ...form, preferred_doctor_id: e.target.value })}>
              <option value="">{t('leads.none')}</option>
              {options.doctors.map((d) => (
                <option key={d.id} value={d.id}>
                  {d.name}
                </option>
              ))}
            </Select>
          </Field>
          <Field label={t('leads.preferred_date')}>
            <TextInput type="date" value={form.preferred_date} onChange={(e) => setForm({ ...form, preferred_date: e.target.value })} />
          </Field>
          <Field label={t('leads.preferred_time')}>
            <TextInput type="time" value={form.preferred_time} onChange={(e) => setForm({ ...form, preferred_time: e.target.value })} />
          </Field>
          <Field label={t('leads.assign_to')}>
            <Select value={form.assigned_to} onChange={(e) => setForm({ ...form, assigned_to: e.target.value })}>
              <option value="">{t('leads.unassigned')}</option>
              {options.staff.map((s) => (
                <option key={s.id} value={s.id}>
                  {s.name}
                </option>
              ))}
            </Select>
          </Field>
          <Field label={t('leads.notes')}>
            <TextArea rows={2} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
          </Field>
          <div className="col-span-full flex justify-end gap-2 pt-2">
            <button type="button" onClick={() => setShowNew(false)} className="rounded-lg border border-clinical-slate-200 px-4 py-2 text-sm font-medium text-clinical-slate-600 hover:bg-clinical-slate-50">
              {t('common.cancel')}
            </button>
            <button type="submit" disabled={saving} className="rounded-lg bg-clinical-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-clinical-indigo-700 disabled:opacity-60">
              {saving ? t('common.saving') : t('leads.create_lead')}
            </button>
          </div>
        </form>
      </Modal>

      {selected && (
        <Modal open onClose={() => setSelected(null)} title={selected.name} size="lg">
          <div className="mb-5 grid gap-3 rounded-xl border border-clinical-slate-100 bg-clinical-slate-50 p-4 text-sm sm:grid-cols-2">
            <div>
              <p className="text-xs text-clinical-slate-400">{t('leads.contact')}</p>
              <p className="text-clinical-slate-700">{selected.email ?? '—'} · {selected.phone ?? '—'}</p>
            </div>
            <div>
              <p className="text-xs text-clinical-slate-400">{t('leads.service_doctor')}</p>
              <p className="text-clinical-slate-700">
                {selected.service_interest ?? '—'}
                {selected.preferred_doctor_name ? ` · ${selected.preferred_doctor_name}` : ''}
              </p>
            </div>
            <div>
              <p className="text-xs text-clinical-slate-400">{t('leads.preferred_appointment')}</p>
              <p className="text-clinical-slate-700">
                {selected.preferred_date ? new Date(selected.preferred_date).toLocaleDateString() : '—'}
                {selected.preferred_time ? ` at ${selected.preferred_time}` : ''}
              </p>
            </div>
            <div>
              <p className="text-xs text-clinical-slate-400">{t('leads.status_owner')}</p>
              <p className="flex items-center gap-2 text-clinical-slate-700">
                <StatusBadge status={selected.status} />
                {selected.assigned_to_name ?? t('leads.unassigned')}
              </p>
            </div>
          </div>

          {canManage && (
            <div className="mb-5">
              <p className="mb-2 text-xs font-medium uppercase tracking-wider text-clinical-slate-400">{t('leads.move_stage')}</p>
              <div className="flex flex-wrap gap-2">
                {LEAD_STATUSES.map((s) => (
                  <button
                    key={s}
                    onClick={() => handleTransition(selected, s)}
                    disabled={transitioning || s === selected.status}
                    className={`rounded-lg border px-3 py-1.5 text-xs font-medium ${
                      s === selected.status
                        ? 'border-clinical-indigo-300 bg-clinical-indigo-50 text-clinical-indigo-700'
                        : 'border-clinical-slate-200 text-clinical-slate-600 hover:border-clinical-indigo-300 hover:text-clinical-indigo-700'
                    } disabled:cursor-not-allowed disabled:opacity-50`}
                  >
                    {t(`leads.status.${s}`) || s.replace(/_/g, ' ')}
                  </button>
                ))}
              </div>
            </div>
          )}

          <div>
            <p className="mb-3 text-sm font-semibold text-clinical-slate-900">{t('leads.communications')}</p>
            <div className="mb-4 max-h-56 space-y-2 overflow-y-auto">
              {(selected.communications ?? []).length === 0 ? (
                <p className="text-sm text-clinical-slate-400">{t('leads.no_communications')}</p>
              ) : (
                selected.communications!.map((c) => (
                  <div key={c.id} className="rounded-lg border border-clinical-slate-100 px-3 py-2">
                    <p className="text-xs capitalize text-clinical-slate-400">{c.channel} · {c.user_name ?? 'system'} · {new Date(c.occurred_at ?? '').toLocaleString()}</p>
                    <p className="text-sm text-clinical-slate-700">{c.summary}</p>
                  </div>
                ))
              )}
            </div>

            {canManage && (
              <form onSubmit={handleAddCommunication} className="flex flex-col gap-2 sm:flex-row">
                <Select
                  value={commForm.channel}
                  onChange={(e) => setCommForm({ ...commForm, channel: e.target.value })}
                  className="sm:w-36"
                  aria-label={t('leads.channel_aria')}
                >
                  {COMM_CHANNELS.map((ch) => (
                    <option key={ch} value={ch}>
                      {t(`leads.channel.${ch}`) || ch.replace(/_/g, ' ')}
                    </option>
                  ))}
                </Select>
                <TextInput
                  value={commForm.summary}
                  onChange={(e) => setCommForm({ ...commForm, summary: e.target.value })}
                  placeholder={t('leads.summary_placeholder')}
                  required
                />
                <button type="submit" className="flex-none rounded-lg bg-clinical-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-clinical-indigo-700">
                  {t('leads.log_communication')}
                </button>
              </form>
            )}
          </div>
        </Modal>
      )}
    </div>
  )
}