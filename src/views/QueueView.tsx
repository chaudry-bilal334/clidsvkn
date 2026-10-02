import React, { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import api from '../services/api'
import { DataTable } from '../components/ui/DataTable'
import { StatusBadge } from '../components/ui/StatusBadge'
import { EmptyState, ErrorState, LoadingState } from '../components/ui/States'
import { Modal } from '../components/ui/Modal'
import { PageHeader, PrimaryButton } from '../components/ui/PageHeader'
import { FormSection, Field, Select, TextInput } from '../components/ui/FormSection'
import { useToast } from '../components/ui/Toast'

type QueueStatus = 'arrived' | 'waiting' | 'in_consultation' | 'completed' | 'cancelled'

interface QueueEntry {
  id: number
  appointment_id: number
  patient_id: number
  patient_name: string
  mrn?: string
  doctor_name?: string
  status: QueueStatus
  checked_in_at: string
}

const STATUS_TRANSITIONS: Record<QueueStatus, QueueStatus[]> = {
  arrived: ['waiting', 'cancelled'],
  waiting: ['in_consultation', 'cancelled'],
  in_consultation: ['completed', 'cancelled'],
  completed: [],
  cancelled: [],
}

const STATUS_LABELS: Record<QueueStatus, string> = {
  arrived: 'Arrived',
  waiting: 'Waiting',
  in_consultation: 'In Consultation',
  completed: 'Completed',
  cancelled: 'Cancelled',
}

const waitDuration = (checkedInAt: string): string => {
  const diff = Date.now() - new Date(checkedInAt).getTime()
  const mins = Math.floor(diff / 60000)
  const hours = Math.floor(mins / 60)
  if (hours > 0) return `${hours}h ${mins % 60}m`
  return `${mins}m`
}

export const QueueView: React.FC = () => {
  const { t } = useTranslation()
  const { toast } = useToast()

  const [entries, setEntries] = useState<QueueEntry[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [statusFilter, setStatusFilter] = useState<string>('')
  const [doctorFilter, setDoctorFilter] = useState<string>('')

  const [checkInOpen, setCheckInOpen] = useState(false)
  const [checkInLoading, setCheckInLoading] = useState(false)
  const [checkInError, setCheckInError] = useState<string | null>(null)
  const [appointmentId, setAppointmentId] = useState('')
  const [branchId, setBranchId] = useState('')

  const [transitionOpen, setTransitionOpen] = useState(false)
  const [transitionLoading, setTransitionLoading] = useState(false)
  const [transitionError, setTransitionError] = useState<string | null>(null)
  const [selectedEntry, setSelectedEntry] = useState<QueueEntry | null>(null)
  const [newStatus, setNewStatus] = useState<QueueStatus>('waiting')

  const load = () => {
    setLoading(true)
    setError(null)
    const params = new URLSearchParams()
    if (statusFilter) params.set('status', statusFilter)
    if (doctorFilter) params.set('doctor_id', doctorFilter)

    api
      .get(`/queue?${params.toString()}`)
      .then((res) => setEntries(res.data.data ?? []))
      .catch(() => setError(t('queue.load_error')))
      .finally(() => setLoading(false))
  }

  useEffect(() => {
    load()
  }, [statusFilter, doctorFilter])

  const handleCheckIn = async () => {
    if (!appointmentId) {
      setCheckInError(t('queue.appointment_required'))
      return
    }
    setCheckInLoading(true)
    setCheckInError(null)
    try {
      await api.post('/queue', {
        appointment_id: Number(appointmentId),
        branch_id: branchId ? Number(branchId) : undefined,
      })
      toast(t('queue.checkin_success'), 'success')
      setCheckInOpen(false)
      setAppointmentId('')
      setBranchId('')
      load()
    } catch (e: unknown) {
      const err = e as { response?: { data?: { message?: string } } }
      setCheckInError(err.response?.data?.message ?? t('queue.checkin_failed'))
    } finally {
      setCheckInLoading(false)
    }
  }

  const handleTransition = async () => {
    if (!selectedEntry) return
    setTransitionLoading(true)
    setTransitionError(null)
    try {
      await api.put(`/queue/${selectedEntry.id}`, { status: newStatus })
      toast(t('queue.transition_success'), 'success')
      setTransitionOpen(false)
      setSelectedEntry(null)
      load()
    } catch (e: unknown) {
      const err = e as { response?: { data?: { message?: string } } }
      setTransitionError(err.response?.data?.message ?? t('queue.transition_failed'))
    } finally {
      setTransitionLoading(false)
    }
  }

  const openTransition = (entry: QueueEntry) => {
    const allowed = STATUS_TRANSITIONS[entry.status]
    if (allowed.length === 0) return
    setSelectedEntry(entry)
    setNewStatus(allowed[0])
    setTransitionOpen(true)
  }

  const columns = [
    { key: 'position', header: '#', className: 'w-12' },
    { key: 'patient', header: t('queue.patient'), className: 'min-w-[200px]' },
    { key: 'doctor', header: t('queue.doctor') },
    { key: 'scheduled', header: t('queue.scheduled'), className: 'w-36' },
    { key: 'wait', header: t('queue.wait_time'), className: 'w-28' },
    { key: 'status', header: t('queue.status'), className: 'w-40' },
    { key: 'actions', header: '', className: 'w-36 text-right' },
  ]

  const rows = entries.map((entry, idx) => ({
    id: entry.id,
    position: idx + 1,
    patient: (
      <div>
        <p className="font-medium text-clinical-slate-800">{entry.patient_name}</p>
        <p className="text-xs text-clinical-slate-400">{entry.mrn}</p>
      </div>
    ),
    doctor: entry.doctor_name ?? '—',
    scheduled: entry.checked_in_at
      ? new Date(entry.checked_in_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
      : '—',
    wait: entry.checked_in_at ? waitDuration(entry.checked_in_at) : '—',
    status: <StatusBadge status={entry.status} />,
    actions: (
      <div className="flex items-center justify-end gap-2">
        {STATUS_TRANSITIONS[entry.status].length > 0 && (
          <button
            onClick={() => openTransition(entry)}
            className="text-sm font-medium text-clinical-indigo-600 hover:underline"
            disabled={transitionLoading}
          >
            {t('queue.transition')}
          </button>
        )}
      </div>
    ),
  }))

  return (
    <div className="mx-auto max-w-6xl">
      <PageHeader
        title={t('queue.title')}
        description={t('queue.description')}
        actions={
          <PrimaryButton onClick={() => setCheckInOpen(true)} className="whitespace-nowrap">
            {t('queue.check_in')}
          </PrimaryButton>
        }
      />

      <DataTable
        columns={columns}
        rows={rows}
        rowKey={(row) => row.id}
        loading={loading}
        error={error}
        onRetry={load}
        emptyTitle={t('queue.empty_title')}
        emptyDescription={t('queue.empty_description')}
      />

      <Modal open={checkInOpen} title={t('queue.check_in')} onClose={() => setCheckInOpen(false)} size="md">
        <FormSection title={t('queue.check_in_form_title')}>
          <Field label={t('queue.appointment_id')} htmlFor="appointment_id" required error={checkInError}>
            <TextInput
              id="appointment_id"
              type="number"
              value={appointmentId}
              onChange={(e) => setAppointmentId(e.target.value)}
              placeholder={t('queue.appointment_placeholder')}
              disabled={checkInLoading}
            />
          </Field>
          <Field label={t('queue.branch_id')} htmlFor="branch_id">
            <Select
              id="branch_id"
              value={branchId}
              onChange={(e) => setBranchId(e.target.value)}
              disabled={checkInLoading}
            >
              <option value="">{t('queue.default_branch')}</option>
            </Select>
          </Field>
        </FormSection>
        <div className="flex justify-end gap-2 mt-4">
          <button onClick={() => setCheckInOpen(false)} className="px-4 py-2 text-sm font-medium text-clinical-slate-700 hover:text-clinical-slate-900">
            {t('common.cancel')}
          </button>
          <PrimaryButton onClick={handleCheckIn} disabled={checkInLoading}>
            {checkInLoading ? t('common.loading') : t('queue.check_in')}
          </PrimaryButton>
        </div>
      </Modal>

      <Modal open={transitionOpen} title={t('queue.transition_title')} onClose={() => setTransitionOpen(false)} size="sm">
        {selectedEntry && (
          <>
            <p className="text-sm text-clinical-slate-600">
              {t('queue.transition_message', { patient: selectedEntry.patient_name, status: STATUS_LABELS[selectedEntry.status] })}
            </p>
            <FormSection className="mt-4">
              <Field label={t('queue.new_status')} htmlFor="new_status" required>
                <Select
                  id="new_status"
                  value={newStatus}
                  onChange={(e) => setNewStatus(e.target.value as QueueStatus)}
                  disabled={transitionLoading}
                >
                  {STATUS_TRANSITIONS[selectedEntry.status].map((s) => (
                    <option key={s} value={s}>
                      {STATUS_LABELS[s]}
                    </option>
                  ))}
                </Select>
              </Field>
            </FormSection>
            {transitionError && <p className="mt-2 text-sm text-red-600" role="alert">{transitionError}</p>}
            <div className="flex justify-end gap-2 mt-4">
              <button onClick={() => setTransitionOpen(false)} className="px-4 py-2 text-sm font-medium text-clinical-slate-700 hover:text-clinical-slate-900">
                {t('common.cancel')}
              </button>
              <PrimaryButton onClick={handleTransition} disabled={transitionLoading}>
                {transitionLoading ? t('common.loading') : t('queue.transition')}
              </PrimaryButton>
            </div>
          </>
        )}
      </Modal>
    </div>
  )
}