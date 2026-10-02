import React, { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import api from '../services/api'
import { DataTable, Column, Pagination } from '../components/ui/DataTable'
import { ConfirmDialog } from '../components/ui/ConfirmDialog'
import { Modal } from '../components/ui/Modal'
import { FormSection, Field, TextInput, Select } from '../components/ui/FormSection'
import { PageHeader, PrimaryButton, SecondaryButton } from '../components/ui/PageHeader'
import { useToast } from '../components/ui/Toast'
import { useTranslation } from 'react-i18next'

interface Patient {
  id: number
  mrn?: string
  name: string
  email?: string
  phone?: string
  date_of_birth?: string
  gender?: string
  address?: string
}

interface PatientsViewProps {
  onPatientSelected?: (patient: Patient) => void
  onPatientSaved?: (patient: Patient) => void
}

const EMPTY_FORM: Patient = { id: 0, name: '', email: '', phone: '', date_of_birth: '', gender: '', address: '' }

export const PatientsView = ({ onPatientSaved }: PatientsViewProps = {}) => {
  const navigate = useNavigate()
  const { toast } = useToast()
  const { t } = useTranslation()
  const [patients, setPatients] = useState<Patient[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [searchTerm, setSearchTerm] = useState('')
  const [page, setPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [lastPage, setLastPage] = useState(1)
  const [editing, setEditing] = useState<Patient | null>(null)
  const [saving, setSaving] = useState(false)
  const [deleteTarget, setDeleteTarget] = useState<Patient | null>(null)
  const [deleting, setDeleting] = useState(false)

  const loadPatients = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const response = await api.get('/patients', { params: { search: searchTerm, page, per_page: perPage } })
      setPatients(response.data.data || [])
      setTotal(response.data.meta?.total ?? 0)
      setLastPage(response.data.meta?.last_page ?? 1)
    } catch {
      setError('Unable to load patients.')
    } finally {
      setLoading(false)
    }
  }, [searchTerm, page, perPage])

  useEffect(() => {
    loadPatients()
  }, [loadPatients])

  const handleSave = async () => {
    if (!editing || !editing.name.trim()) return
    setSaving(true)
    try {
      if (editing.id) {
        await api.put(`/patients/${editing.id}`, editing)
      } else {
        const res = await api.post('/patients', editing)
        const created = res.data?.data as Patient
        if (created?.id) setEditing({ ...editing, id: created.id })
      }
      await loadPatients()
      setEditing(null)
      toast('Patient saved', 'success')
      onPatientSaved?.(editing)
    } catch {
      toast('Failed to save patient', 'error')
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async () => {
    if (!deleteTarget) return
    setDeleting(true)
    try {
      await api.delete(`/patients/${deleteTarget.id}`)
      setPatients((prev) => prev.filter((p) => p.id !== deleteTarget.id))
      toast('Patient deleted', 'success')
      setDeleteTarget(null)
    } catch {
      toast('Failed to delete patient', 'error')
    } finally {
      setDeleting(false)
    }
  }

  const columns: Column<Patient>[] = [
    {
      key: 'mrn',
      header: 'MRN',
      render: (p) => <span className="font-medium text-clinical-slate-500">{p.mrn ?? '—'}</span>,
    },
    {
      key: 'name',
      header: 'Name',
      render: (p) => <span className="font-medium text-clinical-slate-900">{p.name}</span>,
    },
    {
      key: 'email',
      header: 'Email',
      render: (p) => <span className="text-clinical-slate-600">{p.email ?? '—'}</span>,
    },
    {
      key: 'phone',
      header: 'Phone',
      render: (p) => <span className="text-clinical-slate-600">{p.phone ?? '—'}</span>,
    },
    {
      key: 'actions',
      header: '',
      render: (p) => (
        <div className="flex items-center justify-end gap-3 text-sm font-medium">
          <button
            onClick={() => navigate(`/patients/${p.id}`)}
            className="text-clinical-indigo-600 hover:underline"
            title="View medical history"
          >
            History
          </button>
          <button
            onClick={() => setEditing(p)}
            className="text-clinical-teal-600 hover:underline"
            title="Edit patient record"
          >
            Edit
          </button>
          <button onClick={() => setDeleteTarget(p)} className="text-clinical-slate-400 hover:underline" title="Delete patient">
            Delete
          </button>
        </div>
      ),
    },
  ]

  return (
    <div className="space-y-4">
      <PageHeader
        title={t('patients.patients')}
        description={t('patients.total_patients', { total })}
        actions={
          <PrimaryButton onClick={() => setEditing({ ...EMPTY_FORM })}>{t('patients.add_patient')}</PrimaryButton>
        }
      />

      <input
        type="text"
        placeholder={t('patients.search_placeholder')}
        value={searchTerm}
        onChange={(e) => {
          setSearchTerm(e.target.value)
          setPage(1)
        }}
        className="w-full rounded-lg border border-clinical-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-clinical-indigo-200"
      />

      <DataTable
        columns={columns}
        rows={patients}
        rowKey={(p) => p.id}
        loading={loading}
        error={error}
        onRetry={loadPatients}
        emptyTitle={t('patients.no_patients')}
        emptyDescription={t('patients.no_patients_description', 'Try adjusting your search or add a new patient.')}
      />
      <div className="px-1">
        <Pagination page={page} lastPage={lastPage} total={total} onPageChange={(p) => setPage(p)} />
      </div>

      <Modal
        open={editing != null}
        title={editing?.id ? t('patients.edit_patient') : t('patients.add_patient')}
        onClose={() => setEditing(null)}
        size="md"
        footer={
          <>
            <SecondaryButton onClick={() => setEditing(null)} disabled={saving}>
              {t('common.cancel')}
            </SecondaryButton>
            <PrimaryButton onClick={handleSave} disabled={saving || !editing?.name.trim()}>
              {saving ? t('common.saving') : t('common.save_patient')}
            </PrimaryButton>
          </>
        }
      >
        <FormSection title="Patient details" twoColumn>
          <Field label={t('patients.full_name')} required htmlFor="patient-name">
            <TextInput
              id="patient-name"
              value={editing?.name ?? ''}
              onChange={(e) => setEditing((prev) => (prev ? { ...prev, name: e.target.value } : prev))}
              required
            />
          </Field>
          <Field label={t('patients.gender')} htmlFor="patient-gender">
            <Select
              id="patient-gender"
              value={editing?.gender ?? ''}
              onChange={(e) => setEditing((prev) => (prev ? { ...prev, gender: e.target.value } : prev))}
            >
              <option value="">{t('patients.gender_unknown')}</option>
              <option value="male">{t('patients.gender_male')}</option>
              <option value="female">{t('patients.gender_female')}</option>
              <option value="other">{t('patients.gender_other')}</option>
            </Select>
          </Field>
          <Field label={t('patients.email')} htmlFor="patient-email">
            <TextInput
              id="patient-email"
              type="email"
              value={editing?.email ?? ''}
              onChange={(e) => setEditing((prev) => (prev ? { ...prev, email: e.target.value } : prev))}
            />
          </Field>
          <Field label={t('patients.phone')} htmlFor="patient-phone">
            <TextInput
              id="patient-phone"
              type="tel"
              value={editing?.phone ?? ''}
              onChange={(e) => setEditing((prev) => (prev ? { ...prev, phone: e.target.value } : prev))}
            />
          </Field>
          <Field label={t('patients.date_of_birth')} htmlFor="patient-dob">
            <TextInput
              id="patient-dob"
              type="date"
              value={editing?.date_of_birth ?? ''}
              onChange={(e) => setEditing((prev) => (prev ? { ...prev, date_of_birth: e.target.value } : prev))}
            />
          </Field>
          <Field label={t('patients.address')} htmlFor="patient-address">
            <TextInput
              id="patient-address"
              value={editing?.address ?? ''}
              onChange={(e) => setEditing((prev) => (prev ? { ...prev, address: e.target.value } : prev))}
            />
          </Field>
        </FormSection>
      </Modal>

      <ConfirmDialog
        open={deleteTarget != null}
        title="Delete patient"
        message={t('patients.delete_confirm', { name: deleteTarget?.name ?? 'this patient' })}
        confirmLabel={t('common.delete')}
        danger
        loading={deleting}
        onConfirm={handleDelete}
        onCancel={() => setDeleteTarget(null)}
      />
    </div>
  )
}