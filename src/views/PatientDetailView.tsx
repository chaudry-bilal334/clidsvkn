import React, { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import api from '../services/api'
import { ErrorState, LoadingState } from '../components/ui/States'
import { StatusBadge } from '../components/ui/StatusBadge'
import { DataTable } from '../components/ui/DataTable'
import { PageHeader } from '../components/ui/PageHeader'
import { FormSection } from '../components/ui/FormSection'
import { useToast } from '../components/ui/Toast'

interface PatientDetail {
  id: number
  mrn?: string
  name?: string
  email?: string
  phone?: string
  gender?: string
  date_of_birth?: string
  blood_group?: string
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  [key: string]: any
}

interface AllergyRecord {
  id: number
  category: string
  allergen_id?: number
  term?: string
  reaction?: string
  severity: string
  is_active: boolean
  onset_date?: string
  recorded_by?: number
}

interface MedicationRecord {
  id: number
  medicine_name: string
  dosage?: string
  frequency?: string
  source: string
  is_active: boolean
  reconciled_at?: string
  reconciled_by?: number
}

interface EncounterRecord {
  id: number
  status: string
  created_at: string
  doctor_name?: string
  finalized_at?: string | null
  voided_at?: string | null
}

interface PrescriptionRecord {
  id: number
  status: string
  created_at: string
  medication_name?: string
}

interface LabOrderRecord {
  id: number
  status: string
  created_at: string
  test_name?: string
}

interface DocumentRecord {
  id: number
  title: string
  created_at: string
  document_type?: string
}

export const PatientDetailView: React.FC = () => {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { id } = useParams<{ id: string }>()
  const { toast } = useToast()

  const [patient, setPatient] = useState<PatientDetail | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const [allergies, setAllergies] = useState<AllergyRecord[]>([])
  const [medications, setMedications] = useState<MedicationRecord[]>([])
  const [encounters, setEncounters] = useState<EncounterRecord[]>([])
  const [prescriptions, setPrescriptions] = useState<PrescriptionRecord[]>([])
  const [labOrders, setLabOrders] = useState<LabOrderRecord[]>([])
  const [documents, setDocuments] = useState<DocumentRecord[]>([])

  const [loadingLinks, setLoadingLinks] = useState(false)

  useEffect(() => {
    if (!id) return
    api
      .get(`/patients/${id}`)
      .then((res) => setPatient(res.data.data ?? res.data ?? null))
      .catch(() => setError(t('patient_detail.load_error')))
      .finally(() => setLoading(false))
  }, [id])

  useEffect(() => {
    if (!id) return
    setLoadingLinks(true)
    Promise.all([
      api.get(`/patients/${id}/medical-history`).then((res) => res.data.data ?? []),
      api.get(`/patients/${id}/medication-reconciliations`).then((res) => res.data.data ?? []),
      api.get('/encounters', { params: { patient_id: id } }).then((res) => res.data.data ?? []),
      api.get('/prescriptions', { params: { patient_id: id } }).then((res) => res.data.data ?? []),
      api.get('/lab-orders', { params: { patient_id: id } }).then((res) => res.data.data ?? []),
      api.get(`/patients/${id}/documents`).then((res) => res.data.data ?? []),
    ])
      .then(([allergiesRes, medicationsRes, encountersRes, prescriptionsRes, labOrdersRes, documentsRes]) => {
        setAllergies(allergiesRes.filter((a: AllergyRecord) => a.category === 'allergy' && a.is_active))
        setMedications(medicationsRes.filter((m: MedicationRecord) => m.is_active))
        setEncounters(encountersRes)
        setPrescriptions(prescriptionsRes)
        setLabOrders(labOrdersRes)
        setDocuments(documentsRes)
      })
      .catch(() => {
        // Silently fail for linked records
      })
      .finally(() => setLoadingLinks(false))
  }, [id])

  if (loading) return <LoadingState label="Loading patient…" />
  if (error) return <ErrorState message={error} />
  if (!patient) return <ErrorState message="Patient not found." />

  const age = patient.date_of_birth
    ? Math.floor((Date.now() - new Date(patient.date_of_birth).getTime()) / (365.25 * 24 * 60 * 60 * 1000))
    : null

  const severityTone = (severity: string) => {
    switch (severity) {
      case 'severe': return 'critical'
      case 'moderate': return 'warning'
      case 'mild': return 'info'
      default: return 'neutral'
    }
  }

  const activeAllergies = allergies.filter((a) => a.is_active)
  const activeMedications = medications.filter((m) => m.is_active)

  const hasSafetyAlerts = activeAllergies.length > 0 || activeMedications.length > 0

  const encounterColumns = [
    { key: 'date', header: t('patient_detail.encounters') },
    { key: 'doctor', header: t('encounters.doctor') },
    { key: 'status', header: t('common.status') },
  ]

  const encounterRows = encounters.map((e) => ({
    id: e.id,
    date: new Date(e.created_at).toLocaleDateString(),
    doctor: e.doctor_name ?? '—',
    status: <StatusBadge status={e.status} />,
  }))

  const prescriptionColumns = [
    { key: 'medication', header: t('patient_detail.prescriptions') },
    { key: 'status', header: t('common.status') },
    { key: 'date', header: t('encounters.created_at') },
  ]

  const prescriptionRows = prescriptions.map((p) => ({
    id: p.id,
    medication: p.medication_name ?? `Prescription #${p.id}`,
    status: <StatusBadge status={p.status} />,
    date: new Date(p.created_at).toLocaleDateString(),
  }))

  const labOrderColumns = [
    { key: 'test', header: t('patient_detail.lab_orders') },
    { key: 'status', header: t('common.status') },
    { key: 'date', header: t('encounters.created_at') },
  ]

  const labOrderRows = labOrders.map((l) => ({
    id: l.id,
    test: l.test_name ?? `Lab Order #${l.id}`,
    status: <StatusBadge status={l.status} />,
    date: new Date(l.created_at).toLocaleDateString(),
  }))

  const documentColumns = [
    { key: 'title', header: t('patient_detail.documents') },
    { key: 'type', header: t('patient_detail.document_type') },
    { key: 'date', header: t('encounters.created_at') },
  ]

  const documentRows = documents.map((d) => ({
    id: d.id,
    title: d.title,
    type: d.document_type ?? '—',
    date: new Date(d.created_at).toLocaleDateString(),
  }))

  return (
    <div className="mx-auto max-w-6xl">
      <button onClick={() => navigate('/patients')} className="mb-4 text-sm text-clinical-slate-500 hover:text-clinical-indigo-600">
        ← {t('common.back')} {t('patients.add_patient')}
      </button>

      <PageHeader
        title={patient.name ?? 'Unknown Patient'}
        description={
          <>
            {patient.mrn && <span className="mr-3">{patient.mrn}</span>}
            {[patient.gender, age != null ? `${age} {t('common.years')}` : null, patient.blood_group].filter(Boolean).join(' · ')}
          </>
        }
      />

      {hasSafetyAlerts && (
        <div className="mb-6 rounded-xl border-2 border-amber-200 bg-amber-50 p-4" role="region" aria-label={t('patient_detail.safety_banner')}>
          <div className="flex items-start gap-3">
            <svg className="flex-shrink-0 mt-0.5 h-5 w-5 text-amber-600" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
              <path fillRule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clipRule="evenodd" />
            </svg>
            <div className="flex-1">
              <h3 className="text-sm font-semibold text-amber-900">{t('patient_detail.safety_banner')}</h3>
              <div className="mt-2 flex flex-wrap gap-2">
                {activeAllergies.map((a) => (
                  <span key={a.id} className="inline-flex items-center gap-1 rounded-full border bg-white px-2.5 py-1 text-xs font-medium text-amber-800">
                    <span className="h-1.5 w-1.5 rounded-full bg-amber-500" aria-hidden="true" />
                    {a.term ?? a.allergen_id ? `Allergen #${a.allergen_id}` : t('patient_detail.allergies')}
                    {a.severity && (
                      <StatusBadge status={a.severity} tone={severityTone(a.severity) as any} withDot={false} />
                    )}
                    {a.reaction && <span className="ml-1 text-amber-700">({a.reaction})</span>}
                  </span>
                ))}
                {activeMedications.map((m) => (
                  <span key={m.id} className="inline-flex items-center gap-1 rounded-full border bg-white px-2.5 py-1 text-xs font-medium text-clinical-indigo-800">
                    <span className="h-1.5 w-1.5 rounded-full bg-clinical-indigo-500" aria-hidden="true" />
                    {m.medicine_name}
                    {m.dosage && <span className="ml-1 text-clinical-indigo-700">{m.dosage}</span>}
                    {m.frequency && <span className="ml-1 text-clinical-indigo-700">{m.frequency}</span>}
                  </span>
                ))}
              </div>
            </div>
          </div>
        </div>
      )}

      <div className="clinical-card p-6">
        <div className="flex items-start justify-between">
          <div className="flex items-center gap-4">
            <div className="flex h-14 w-14 items-center justify-center rounded-full bg-clinical-indigo-100 text-xl font-semibold text-clinical-indigo-700">
              {(patient.name ?? '?').slice(0, 1).toUpperCase()}
            </div>
            <div>
              <h1 className="text-xl font-semibold text-clinical-slate-900">{patient.name}</h1>
              <p className="text-sm text-clinical-slate-400">
                {patient.mrn ? `${patient.mrn} · ` : ''}
                {[patient.gender, age != null ? `${age} yrs` : null, patient.blood_group].filter(Boolean).join(' · ')}
              </p>
            </div>
          </div>
        </div>

        <dl className="mt-6 grid gap-4 sm:grid-cols-2">
          {patient.email && (
            <div>
              <dt className="text-xs text-clinical-slate-400">{t('patients.email')}</dt>
              <dd className="text-sm text-clinical-slate-800">{patient.email}</dd>
            </div>
          )}
          {patient.phone && (
            <div>
              <dt className="text-xs text-clinical-slate-400">{t('patients.phone')}</dt>
              <dd className="text-sm text-clinical-slate-800">{patient.phone}</dd>
            </div>
          )}
          {patient.date_of_birth && (
            <div>
              <dt className="text-xs text-clinical-slate-400">{t('patients.date_of_birth')}</dt>
              <dd className="text-sm text-clinical-slate-800">
                {new Date(patient.date_of_birth).toLocaleDateString()}
              </dd>
            </div>
          )}
        </dl>
      </div>

      <div className="mt-6 grid gap-6 lg:grid-cols-2">
        <FormSection title={t('patient_detail.encounters')}>
          {encounters.length === 0 ? (
            <p className="text-sm text-clinical-slate-500">{t('patient_detail.no_encounters')}</p>
          ) : loadingLinks ? (
            <LoadingState label={t('common.loading')} />
          ) : (
            <DataTable
              columns={encounterColumns}
              rows={encounterRows}
              rowKey={(row) => row.id}
              loading={loadingLinks}
              emptyTitle={t('patient_detail.no_encounters')}
            />
          )}
        </FormSection>

        <FormSection title={t('patient_detail.prescriptions')}>
          {prescriptions.length === 0 ? (
            <p className="text-sm text-clinical-slate-500">{t('patient_detail.no_prescriptions')}</p>
          ) : loadingLinks ? (
            <LoadingState label={t('common.loading')} />
          ) : (
            <DataTable
              columns={prescriptionColumns}
              rows={prescriptionRows}
              rowKey={(row) => row.id}
              loading={loadingLinks}
              emptyTitle={t('patient_detail.no_prescriptions')}
            />
          )}
        </FormSection>

        <FormSection title={t('patient_detail.lab_orders')}>
          {labOrders.length === 0 ? (
            <p className="text-sm text-clinical-slate-500">{t('patient_detail.no_lab_orders')}</p>
          ) : loadingLinks ? (
            <LoadingState label={t('common.loading')} />
          ) : (
            <DataTable
              columns={labOrderColumns}
              rows={labOrderRows}
              rowKey={(row) => row.id}
              loading={loadingLinks}
              emptyTitle={t('patient_detail.no_lab_orders')}
            />
          )}
        </FormSection>

        <FormSection title={t('patient_detail.documents')}>
          {documents.length === 0 ? (
            <p className="text-sm text-clinical-slate-500">{t('patient_detail.no_documents')}</p>
          ) : loadingLinks ? (
            <LoadingState label={t('common.loading')} />
          ) : (
            <DataTable
              columns={documentColumns}
              rows={documentRows}
              rowKey={(row) => row.id}
              loading={loadingLinks}
              emptyTitle={t('patient_detail.no_documents')}
            />
          )}
        </FormSection>
      </div>
    </div>
  )
}