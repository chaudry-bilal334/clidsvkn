import React, { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import api from '../services/api'
import { DataTable } from '../components/ui/DataTable'
import { StatusBadge } from '../components/ui/StatusBadge'
import { EmptyState, ErrorState, LoadingState } from '../components/ui/States'
import { Modal } from '../components/ui/Modal'
import { ConfirmDialog } from '../components/ui/ConfirmDialog'
import { PageHeader, PrimaryButton, DangerButton } from '../components/ui/PageHeader'
import { FormSection, Field, TextInput, TextArea, Select } from '../components/ui/FormSection'
import { useToast } from '../components/ui/Toast'

type EncounterStatus = 'draft' | 'finalized' | 'voided'

interface EncounterRecord {
  id: number
  patient_id: number
  patient_name: string
  mrn?: string
  doctor_id: number
  doctor_name: string
  appointment_id?: number
  status: EncounterStatus
  finalized_at?: string | null
  finalized_by?: number
  voided_at?: string | null
  void_reason?: string | null
  amends_encounter_id?: number
  notes?: string
  created_at: string
}

interface VitalRecord {
  id: number
  blood_pressure?: string | null
  temperature?: number | null
  heart_rate?: number | null
  weight?: number | null
  recorded_at: string
  recorded_by?: number
}

interface DiagnosisRecord {
  id: number
  icd_code: string
  description?: string | null
  recorded_at: string
  recorded_by?: number
}

interface FullEncounter extends EncounterRecord {
  vitals: VitalRecord[]
  diagnoses: DiagnosisRecord[]
}

export const EncountersView: React.FC = () => {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { toast } = useToast()

  const [encounters, setEncounters] = useState<EncounterRecord[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const [createOpen, setCreateOpen] = useState(false)
  const [createLoading, setCreateLoading] = useState(false)
  const [createError, setCreateError] = useState<string | null>(null)
  const [createPatientId, setCreatePatientId] = useState('')
  const [createNotes, setCreateNotes] = useState('')

  const [detailOpen, setDetailOpen] = useState(false)
  const [detailLoading, setDetailLoading] = useState(false)
  const [detailError, setDetailError] = useState<string | null>(null)
  const [selectedEncounter, setSelectedEncounter] = useState<FullEncounter | null>(null)

  const [vitalsOpen, setVitalsOpen] = useState(false)
  const [vitalsLoading, setVitalsLoading] = useState(false)
  const [vitalsError, setVitalsError] = useState<string | null>(null)
  const [vitalBp, setVitalBp] = useState('')
  const [vitalTemp, setVitalTemp] = useState('')
  const [vitalHr, setVitalHr] = useState('')
  const [vitalWeight, setVitalWeight] = useState('')

  const [diagnosisOpen, setDiagnosisOpen] = useState(false)
  const [diagnosisLoading, setDiagnosisLoading] = useState(false)
  const [diagnosisError, setDiagnosisError] = useState<string | null>(null)
  const [diagnosisIcd, setDiagnosisIcd] = useState('')
  const [diagnosisDesc, setDiagnosisDesc] = useState('')

  const [finalizeDialogOpen, setFinalizeDialogOpen] = useState(false)
  const [finalizeLoading, setFinalizeLoading] = useState(false)

  const [voidDialogOpen, setVoidDialogOpen] = useState(false)
  const [voidLoading, setVoidLoading] = useState(false)
  const [voidError, setVoidError] = useState<string | null>(null)
  const [voidReason, setVoidReason] = useState('')

  const load = () => {
    setLoading(true)
    setError(null)
    api
      .get('/encounters')
      .then((res) => setEncounters(res.data.data ?? []))
      .catch(() => setError(t('encounters.load_error')))
      .finally(() => setLoading(false))
  }

  useEffect(load, [])

  const handleCreate = async () => {
    if (!createPatientId) {
      setCreateError(t('encounters.patient_required'))
      return
    }
    setCreateLoading(true)
    setCreateError(null)
    try {
      const res = await api.post('/encounters', {
        patient_id: Number(createPatientId),
        notes: createNotes || null,
      })
      toast(t('encounters.create_success'), 'success')
      setCreateOpen(false)
      setCreatePatientId('')
      setCreateNotes('')
      load()
      // Open the new encounter detail
      if (res.data.data?.id) {
        openDetail(res.data.data.id)
      }
    } catch (e: unknown) {
      const err = e as { response?: { data?: { message?: string } } }
      setCreateError(err.response?.data?.message ?? t('encounters.create_failed'))
    } finally {
      setCreateLoading(false)
    }
  }

  const openDetail = async (id: number) => {
    setDetailLoading(true)
    setDetailError(null)
    try {
      const res = await api.get(`/encounters/${id}`)
      setSelectedEncounter(res.data.data)
      setDetailOpen(true)
    } catch (e: unknown) {
      const err = e as { response?: { data?: { message?: string } } }
      setDetailError(err.response?.data?.message ?? t('encounters.load_error'))
    } finally {
      setDetailLoading(false)
    }
  }

  const handleFinalize = async () => {
    if (!selectedEncounter) return
    setFinalizeLoading(true)
    try {
      const res = await api.post(`/encounters/${selectedEncounter.id}/finalize`)
      toast(t('encounters.finalize_success'), 'success')
      setFinalizeDialogOpen(false)
      setSelectedEncounter(res.data.data)
      load()
    } catch (e: unknown) {
      const err = e as { response?: { data?: { message?: string } } }
      toast(err.response?.data?.message ?? t('encounters.finalize_failed'), 'error')
    } finally {
      setFinalizeLoading(false)
    }
  }

  const handleVoid = async () => {
    if (!selectedEncounter || !voidReason.trim()) {
      setVoidError(t('encounters.void_reason_required'))
      return
    }
    setVoidLoading(true)
    setVoidError(null)
    try {
      const res = await api.post(`/encounters/${selectedEncounter.id}/void`, { reason: voidReason })
      toast(t('encounters.void_success'), 'success')
      setVoidDialogOpen(false)
      setVoidReason('')
      setSelectedEncounter(res.data.data)
      load()
    } catch (e: unknown) {
      const err = e as { response?: { data?: { message?: string } } }
      setVoidError(err.response?.data?.message ?? t('encounters.void_failed'))
    } finally {
      setVoidLoading(false)
    }
  }

  const handleAddVitals = async () => {
    if (!selectedEncounter) return
    setVitalsLoading(true)
    setVitalsError(null)
    try {
      await api.post(`/encounters/${selectedEncounter.id}/vitals`, {
        blood_pressure: vitalBp || null,
        temperature: vitalTemp ? Number(vitalTemp) : null,
        heart_rate: vitalHr ? Number(vitalHr) : null,
        weight: vitalWeight ? Number(vitalWeight) : null,
      })
      toast(t('encounters.vitals_success'), 'success')
      setVitalsOpen(false)
      setVitalBp('')
      setVitalTemp('')
      setVitalHr('')
      setVitalWeight('')
      // Reload detail
      const res = await api.get(`/encounters/${selectedEncounter.id}`)
      setSelectedEncounter(res.data.data)
    } catch (e: unknown) {
      const err = e as { response?: { data?: { message?: string } } }
      setVitalsError(err.response?.data?.message ?? t('encounters.vitals_failed'))
    } finally {
      setVitalsLoading(false)
    }
  }

  const handleAddDiagnosis = async () => {
    if (!selectedEncounter || !diagnosisIcd.trim()) {
      setDiagnosisError(t('encounters.icd_required'))
      return
    }
    setDiagnosisLoading(true)
    setDiagnosisError(null)
    try {
      await api.post(`/encounters/${selectedEncounter.id}/diagnoses`, {
        icd_code: diagnosisIcd,
        description: diagnosisDesc || null,
      })
      toast(t('encounters.diagnosis_success'), 'success')
      setDiagnosisOpen(false)
      setDiagnosisIcd('')
      setDiagnosisDesc('')
      const res = await api.get(`/encounters/${selectedEncounter.id}`)
      setSelectedEncounter(res.data.data)
    } catch (e: unknown) {
      const err = e as { response?: { data?: { message?: string } } }
      setDiagnosisError(err.response?.data?.message ?? t('encounters.diagnosis_failed'))
    } finally {
      setDiagnosisLoading(false)
    }
  }

  const formatDate = (iso?: string | null) => (iso ? new Date(iso).toLocaleString() : '—')

  const statusBadge = (status: EncounterStatus) => {
    if (status === 'finalized') return <StatusBadge status="finalized" />
    if (status === 'voided') return <StatusBadge status="voided" />
    return <StatusBadge status="draft" />
  }

  const columns = [
    { key: 'patient', header: t('encounters.patient'), className: 'min-w-[200px]' },
    { key: 'mrn', header: t('patients.mrn'), className: 'w-36' },
    { key: 'doctor', header: t('encounters.doctor'), className: 'w-40' },
    { key: 'status', header: t('encounters.status'), className: 'w-36' },
    { key: 'finalized', header: t('encounters.finalized_at'), className: 'w-40' },
    { key: 'actions', header: '', className: 'w-36 text-right' },
  ]

  const rows = encounters.map((e) => {
    const voided = e.voided_at != null
    const finalized = !voided && e.finalized_at != null
    return {
      id: e.id,
      patient: (
        <div>
          <p className="font-medium text-clinical-slate-800">{e.patient_name}</p>
          <p className="text-xs text-clinical-slate-400">MRN: {e.mrn ?? '—'}</p>
        </div>
      ),
      mrn: e.mrn ?? '—',
      doctor: e.doctor_name,
      status: statusBadge(e.status),
      finalized: finalized ? formatDate(e.finalized_at) : voided ? formatDate(e.voided_at) : '—',
      actions: (
        <div className="flex items-center justify-end gap-2">
          <button
            onClick={() => openDetail(e.id)}
            className="text-sm font-medium text-clinical-indigo-600 hover:underline"
            disabled={detailLoading}
          >
            {t('common.view')}
          </button>
        </div>
      ),
    }
  })

  return (
    <div className="mx-auto max-w-6xl">
      <PageHeader
        title={t('encounters.title')}
        description={t('encounters.description')}
        actions={
          <PrimaryButton onClick={() => setCreateOpen(true)} className="whitespace-nowrap">
            {t('encounters.create_encounter')}
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
        emptyTitle={t('encounters.empty_title')}
        emptyDescription={t('encounters.empty_description')}
      />

      <Modal open={createOpen} title={t('encounters.create_encounter')} onClose={() => setCreateOpen(false)} size="md">
        <FormSection title={t('encounters.create_encounter')}>
          <Field label={t('encounters.patient')} htmlFor="patient_id" required error={createError}>
            <Select
              id="patient_id"
              value={createPatientId}
              onChange={(e) => setCreatePatientId(e.target.value)}
              disabled={createLoading}
            >
              <option value="">{t('encounters.patient_placeholder')}</option>
            </Select>
          </Field>
          <Field label={t('encounters.notes')} htmlFor="notes">
            <TextArea
              id="notes"
              value={createNotes}
              onChange={(e) => setCreateNotes(e.target.value)}
              placeholder={t('encounters.notes_placeholder')}
              rows={3}
              disabled={createLoading}
            />
          </Field>
        </FormSection>
        <div className="flex justify-end gap-2 mt-4">
          <button onClick={() => setCreateOpen(false)} className="px-4 py-2 text-sm font-medium text-clinical-slate-700 hover:text-clinical-slate-900">
            {t('common.cancel')}
          </button>
          <PrimaryButton onClick={handleCreate} disabled={createLoading}>
            {createLoading ? t('common.loading') : t('encounters.create')}
          </PrimaryButton>
        </div>
      </Modal>

      <Modal open={detailOpen} title={t('encounters.encounter_detail')} onClose={() => setDetailOpen(false)} size="xl">
        {detailLoading ? (
          <LoadingState label={t('common.loading')} />
        ) : detailError ? (
          <ErrorState message={detailError} onRetry={() => selectedEncounter && openDetail(selectedEncounter.id)} />
        ) : selectedEncounter ? (
          <>
            <div className="grid gap-4 sm:grid-cols-3">
              <div className="sm:col-span-2 space-y-4">
                <FormSection title={t('encounters.notes')}>
                  <p className="text-sm text-clinical-slate-700 whitespace-pre-wrap">{selectedEncounter.notes ?? t('common.no_data')}</p>
                </FormSection>

                <FormSection title={t('encounters.vitals')}>
                  {selectedEncounter.vitals.length === 0 ? (
                    <p className="text-sm text-clinical-slate-500">{t('encounters.no_vitals')}</p>
                  ) : (
                    <div className="overflow-x-auto">
                      <table className="w-full text-sm divide-y divide-clinical-slate-200">
                        <thead>
                          <tr className="text-left text-xs font-semibold text-clinical-slate-500 uppercase">
                            <th className="pb-2">{t('encounters.blood_pressure')}</th>
                            <th className="pb-2">{t('encounters.temperature')}</th>
                            <th className="pb-2">{t('encounters.heart_rate')}</th>
                            <th className="pb-2">{t('encounters.weight')}</th>
                            <th className="pb-2">{t('encounters.recorded_at')}</th>
                          </tr>
                        </thead>
                        <tbody>
                          {selectedEncounter.vitals.map((v) => (
                            <tr key={v.id} className="text-clinical-slate-700">
                              <td className="py-2">{v.blood_pressure ?? '—'}</td>
                              <td className="py-2">{v.temperature ? `${v.temperature}°C` : '—'}</td>
                              <td className="py-2">{v.heart_rate ? `${v.heart_rate} bpm` : '—'}</td>
                              <td className="py-2">{v.weight ? `${v.weight} kg` : '—'}</td>
                              <td className="py-2">{formatDate(v.recorded_at)}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  )}
                  {selectedEncounter.status === 'draft' && (
                    <PrimaryButton onClick={() => setVitalsOpen(true)} className="mt-3">
                      {t('encounters.add_vitals')}
                    </PrimaryButton>
                  )}
                </FormSection>

                <FormSection title={t('encounters.diagnoses')}>
                  {selectedEncounter.diagnoses.length === 0 ? (
                    <p className="text-sm text-clinical-slate-500">{t('encounters.no_diagnoses')}</p>
                  ) : (
                    <div className="overflow-x-auto">
                      <table className="w-full text-sm divide-y divide-clinical-slate-200">
                        <thead>
                          <tr className="text-left text-xs font-semibold text-clinical-slate-500 uppercase">
                            <th className="pb-2">{t('encounters.icd_code')}</th>
                            <th className="pb-2">{t('encounters.description')}</th>
                            <th className="pb-2">{t('encounters.recorded_at')}</th>
                          </tr>
                        </thead>
                        <tbody>
                          {selectedEncounter.diagnoses.map((d) => (
                            <tr key={d.id} className="text-clinical-slate-700">
                              <td className="py-2 font-mono">{d.icd_code}</td>
                              <td className="py-2">{d.description ?? '—'}</td>
                              <td className="py-2">{formatDate(d.recorded_at)}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  )}
                  {selectedEncounter.status === 'draft' && (
                    <PrimaryButton onClick={() => setDiagnosisOpen(true)} className="mt-3">
                      {t('encounters.add_diagnosis')}
                    </PrimaryButton>
                  )}
                </FormSection>
              </div>

              <div className="space-y-4">
                <FormSection title={t('common.status')}>
                  <div className="flex items-center gap-3">
                    {statusBadge(selectedEncounter.status)}
                    {selectedEncounter.status === 'draft' && (
                      <>
                        <PrimaryButton
                          onClick={() => setFinalizeDialogOpen(true)}
                          className="flex-1"
                        >
                          {t('encounters.finalize')}
                        </PrimaryButton>
                        <DangerButton
                          onClick={() => setVoidDialogOpen(true)}
                          className="flex-1"
                        >
                          {t('encounters.void')}
                        </DangerButton>
                      </>
                    )}
                    {selectedEncounter.status === 'finalized' && (
                      <DangerButton
                        onClick={() => setVoidDialogOpen(true)}
                        className="flex-1"
                      >
                        {t('encounters.void')}
                      </DangerButton>
                    )}
                    {selectedEncounter.status === 'voided' && (
                      <p className="text-sm text-clinical-slate-500">{t('encounters.status_voided')}</p>
                    )}
                  </div>
                </FormSection>

                <FormSection title={t('encounters.meta')}>
                  <dl className="space-y-2 text-sm">
                    <div className="flex justify-between">
                      <dt className="text-clinical-slate-500">{t('encounters.created_at')}</dt>
                      <dd className="font-medium text-clinical-slate-800">{formatDate(selectedEncounter.created_at)}</dd>
                    </div>
                    {selectedEncounter.finalized_at && (
                      <div className="flex justify-between">
                        <dt className="text-clinical-slate-500">{t('encounters.finalized_at')}</dt>
                        <dd className="font-medium text-clinical-slate-800">
                          {formatDate(selectedEncounter.finalized_at)}
                          {selectedEncounter.finalized_by && ` (by #${selectedEncounter.finalized_by})`}
                        </dd>
                      </div>
                    )}
                    {selectedEncounter.voided_at && (
                      <div className="flex justify-between">
                        <dt className="text-clinical-slate-500">{t('encounters.voided_at')}</dt>
                        <dd className="font-medium text-clinical-slate-800">
                          {formatDate(selectedEncounter.voided_at)}
                          {selectedEncounter.voided_by && ` (by #${selectedEncounter.voided_by})`}
                        </dd>
                      </div>
                    )}
                    {selectedEncounter.void_reason && (
                      <div className="flex justify-between">
                        <dt className="text-clinical-slate-500">{t('encounters.void_reason_label')}</dt>
                        <dd className="font-medium text-clinical-slate-800">{selectedEncounter.void_reason}</dd>
                      </div>
                    )}
                  </dl>
                </FormSection>
              </div>
            </div>

            <div className="flex justify-end gap-2 mt-4 border-t border-clinical-slate-200 pt-4">
              <button onClick={() => setDetailOpen(false)} className="px-4 py-2 text-sm font-medium text-clinical-slate-700 hover:text-clinical-slate-900">
                {t('common.close')}
              </button>
              {selectedEncounter.patient_id && (
                <button
                  onClick={() => {
                    setDetailOpen(false)
                    navigate(`/patients/${selectedEncounter.patient_id}`)
                  }}
                  className="px-4 py-2 text-sm font-medium text-clinical-indigo-600 hover:text-clinical-indigo-700"
                >
                  {t('encounters.open_patient_record')}
                </button>
              )}
            </div>
          </>
        ) : null}
      </Modal>

      <Modal open={vitalsOpen} title={t('encounters.add_vitals')} onClose={() => setVitalsOpen(false)} size="md">
        <FormSection title={t('encounters.add_vitals')}>
          <Field label={t('encounters.blood_pressure')} htmlFor="bp">
            <TextInput id="bp" value={vitalBp} onChange={(e) => setVitalBp(e.target.value)} placeholder="120/80" disabled={vitalsLoading} />
          </Field>
          <Field label={t('encounters.temperature')} htmlFor="temp">
            <TextInput id="temp" type="number" step="0.1" value={vitalTemp} onChange={(e) => setVitalTemp(e.target.value)} placeholder="37.0" disabled={vitalsLoading} />
          </Field>
          <Field label={t('encounters.heart_rate')} htmlFor="hr">
            <TextInput id="hr" type="number" value={vitalHr} onChange={(e) => setVitalHr(e.target.value)} placeholder="72" disabled={vitalsLoading} />
          </Field>
          <Field label={t('encounters.weight')} htmlFor="weight">
            <TextInput id="weight" type="number" step="0.1" value={vitalWeight} onChange={(e) => setVitalWeight(e.target.value)} placeholder="70.5" disabled={vitalsLoading} />
          </Field>
        </FormSection>
        {vitalsError && <p className="mt-2 text-sm text-red-600" role="alert">{vitalsError}</p>}
        <div className="flex justify-end gap-2 mt-4">
          <button onClick={() => setVitalsOpen(false)} className="px-4 py-2 text-sm font-medium text-clinical-slate-700 hover:text-clinical-slate-900">
            {t('common.cancel')}
          </button>
          <PrimaryButton onClick={handleAddVitals} disabled={vitalsLoading}>
            {vitalsLoading ? t('common.loading') : t('encounters.add_vitals')}
          </PrimaryButton>
        </div>
      </Modal>

      <Modal open={diagnosisOpen} title={t('encounters.add_diagnosis')} onClose={() => setDiagnosisOpen(false)} size="md">
        <FormSection title={t('encounters.add_diagnosis')}>
          <Field label={t('encounters.icd_code')} htmlFor="icd" required error={diagnosisError}>
            <TextInput id="icd" value={diagnosisIcd} onChange={(e) => setDiagnosisIcd(e.target.value)} placeholder="I10" disabled={diagnosisLoading} />
          </Field>
          <Field label={t('encounters.description')} htmlFor="desc">
            <TextArea id="desc" value={diagnosisDesc} onChange={(e) => setDiagnosisDesc(e.target.value)} placeholder={t('encounters.description')} rows={3} disabled={diagnosisLoading} />
          </Field>
        </FormSection>
        <div className="flex justify-end gap-2 mt-4">
          <button onClick={() => setDiagnosisOpen(false)} className="px-4 py-2 text-sm font-medium text-clinical-slate-700 hover:text-clinical-slate-900">
            {t('common.cancel')}
          </button>
          <PrimaryButton onClick={handleAddDiagnosis} disabled={diagnosisLoading}>
            {diagnosisLoading ? t('common.loading') : t('encounters.add_diagnosis')}
          </PrimaryButton>
        </div>
      </Modal>

      <ConfirmDialog
        open={finalizeDialogOpen}
        title={t('encounters.finalize')}
        message={t('encounters.finalize_confirm')}
        confirmLabel={t('encounters.finalize')}
        danger
        loading={finalizeLoading}
        onConfirm={handleFinalize}
        onCancel={() => setFinalizeDialogOpen(false)}
      />

      <Modal open={voidDialogOpen} title={t('encounters.void')} onClose={() => { setVoidDialogOpen(false); setVoidReason(''); setVoidError(null); }} size="md">
        <FormSection title={t('encounters.void')}>
          <p className="text-sm text-clinical-slate-600 mb-4">{t('encounters.void_confirm')}</p>
          <Field label={t('encounters.void_reason')} htmlFor="void_reason" required error={voidError}>
            <TextArea
              id="void_reason"
              value={voidReason}
              onChange={(e) => setVoidReason(e.target.value)}
              placeholder={t('encounters.void_reason_placeholder')}
              rows={3}
              disabled={voidLoading}
            />
          </Field>
        </FormSection>
        <div className="flex justify-end gap-2 mt-4">
          <button onClick={() => { setVoidDialogOpen(false); setVoidReason(''); }} className="px-4 py-2 text-sm font-medium text-clinical-slate-700 hover:text-clinical-slate-900">
            {t('common.cancel')}
          </button>
          <DangerButton onClick={handleVoid} disabled={voidLoading}>
            {voidLoading ? t('common.loading') : t('encounters.void')}
          </DangerButton>
        </div>
      </Modal>
    </div>
  )
}