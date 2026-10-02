import React, { useEffect, useState } from 'react'
import { useAuth } from '../context/AuthContext'
import { useTranslation } from 'react-i18next'
import api from '../services/api'
import { Field, TextInput, Select } from '../components/ui/FormSection'
import { useToast } from '../components/ui/Toast'
import { EmptyState, ErrorState, LoadingState } from '../components/ui/States'

interface SystemSetting {
  id: number
  key: string
  value: unknown
  type: string
  created_at?: string
  updated_at?: string
}

const EDIT_TYPES = ['string', 'boolean', 'integer', 'float', 'json']

export const SettingsView: React.FC = () => {
  const { user } = useAuth()
  const { t } = useTranslation()
  const { toast } = useToast()
  const isAdmin = (user?.role ?? '') === 'Admin' || (user?.permissions ?? []).includes('*')

  const [settings, setSettings] = useState<SystemSetting[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [drafts, setDrafts] = useState<Record<number, string>>({})
  const [typeDrafts, setTypeDrafts] = useState<Record<number, string>>({})
  const [saving, setSaving] = useState<number | null>(null)

  const [showNew, setShowNew] = useState(false)
  const [newKey, setNewKey] = useState('')
  const [newValue, setNewValue] = useState('')
  const [newType, setNewType] = useState('string')

  const loadSettings = () => {
    setLoading(true)
    api
      .get('/settings')
      .then((res) => {
        const list: SystemSetting[] = res.data.data ?? []
        setSettings(list)
        setDrafts(Object.fromEntries(list.map((s) => [s.id, formatValue(s)])))
        setTypeDrafts(Object.fromEntries(list.map((s) => [s.id, s.type])))
      })
      .catch(() => setError(t('settings.load_error')))
      .finally(() => setLoading(false))
  }

  useEffect(loadSettings, [])

  const formatValue = (s: SystemSetting): string => {
    if (s.type === 'boolean') return s.value === true ? '1' : '0'
    return typeof s.value === 'string' ? s.value : JSON.stringify(s.value)
  }

  const persist = async (id: number) => {
    setSaving(id)
    try {
      let value: unknown = drafts[id]
      const type = typeDrafts[id] ?? 'string'
      if (type === 'boolean') value = drafts[id] === '1'
      else if (type === 'integer') value = Number(drafts[id])
      else if (type === 'float') value = Number.parseFloat(drafts[id] as string || '0')
      else if (type === 'json') {
        try {
          value = JSON.parse(drafts[id] as string)
        } catch {
          toast(t('settings.json_error'), 'error')
          return
        }
      }
      await api.put(`/settings/${id}`, { value, type })
      toast(t('settings.setting_saved'), 'success')
      loadSettings()
    } catch {
      toast(t('settings.save_failed'), 'error')
    } finally {
      setSaving(null)
    }
  }

  const createSetting = async (e: React.FormEvent) => {
    e.preventDefault()
    try {
      let value: unknown = newValue
      if (newType === 'boolean') value = newValue === '1'
      else if (newType === 'integer') value = Number(newValue)
      else if (newType === 'float') value = Number.parseFloat(newValue || '0')
      else if (newType === 'json') value = JSON.parse(newValue || '{}')
      await api.post('/settings', { key: newKey.trim(), value, type: newType })
      setShowNew(false)
      setNewKey('')
      setNewValue('')
      setNewType('string')
      toast(t('settings.setting_created'), 'success')
      loadSettings()
    } catch (err: unknown) {
      const message = (err as { response?: { data?: { message?: string } } })?.response?.data?.message
      toast(message ?? t('settings.save_failed'), 'error')
    }
  }

  const removeSetting = async (setting: SystemSetting) => {
    try {
      await api.delete(`/settings/${setting.id}`)
      toast(t('settings.deleted', { key: setting.key }), 'success')
      loadSettings()
    } catch {
      toast(t('settings.delete_failed'), 'error')
    }
  }

  if (error) return <ErrorState title={t('settings.unavailable')} message={error} onRetry={loadSettings} />

  return (
    <div className="mx-auto max-w-4xl">
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold text-clinical-slate-900">{t('settings.title')}</h1>
          <p className="mt-1 text-sm text-clinical-slate-500">{t('settings.description')}</p>
        </div>
        {isAdmin && (
          <button onClick={() => setShowNew(true)} className="rounded-lg bg-clinical-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-clinical-indigo-700">
            {t('settings.new_setting')}
          </button>
        )}
      </div>

      {loading ? (
        <LoadingState label={t('settings.loading')} />
      ) : settings.length === 0 ? (
        <EmptyState title={t('settings.no_settings')} description={t('settings.no_settings_description')} />
      ) : (
        <div className="clinical-card divide-y divide-clinical-slate-100">
          {settings.map((setting) => (
            <div key={setting.id} className="flex items-center gap-4 px-5 py-4">
              <div className="min-w-0 flex-1">
                <p className="truncate font-mono text-sm font-medium text-clinical-slate-800">{setting.key}</p>
                <p className="text-xs text-clinical-slate-400">{t('settings.type_label')}: {setting.type}</p>
              </div>
              <div className="w-64">
                <Select value={typeDrafts[setting.id] ?? setting.type} onChange={(e) => setTypeDrafts({ ...typeDrafts, [setting.id]: e.target.value })} aria-label={t('settings.type_aria', { key: setting.key })}>
                  {EDIT_TYPES.map((t) => (
                    <option key={t} value={t}>
                      {t}
                    </option>
                  ))}
                </Select>
              </div>
              <div className="w-72">
                <TextInput
                  value={drafts[setting.id] ?? ''}
                  onChange={(e) => setDrafts({ ...drafts, [setting.id]: e.target.value })}
                  aria-label={t('settings.value_aria', { key: setting.key })}
                />
              </div>
              <button
                onClick={() => persist(setting.id)}
                disabled={saving === setting.id}
                className="flex-none rounded-lg bg-clinical-indigo-600 px-3 py-2 text-xs font-semibold text-white hover:bg-clinical-indigo-700 disabled:opacity-60"
              >
                {saving === setting.id ? t('settings.saving') : t('settings.save')}
              </button>
              {isAdmin && (
                <button
                  onClick={() => removeSetting(setting)}
                  aria-label={t('settings.delete_aria', { key: setting.key })}
                  className="flex-none rounded-lg p-2 text-clinical-slate-400 hover:bg-red-50 hover:text-red-600"
                >
                  <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.87 12.14A2 2 0 0116.14 21H7.86a2 2 0 01-1.99-1.86L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                  </svg>
                </button>
              )}
            </div>
          ))}
        </div>
      )}

      {showNew && (
        <div className="clinical-card mt-6 p-5">
          <h2 className="mb-4 text-sm font-semibold text-clinical-slate-900">{t('settings.new_setting_form')}</h2>
          <form onSubmit={createSetting} className="flex flex-col gap-4 sm:flex-row sm:items-end">
            <div className="min-w-0 flex-1">
              <Field label={t('settings.key')} htmlFor="new-setting-key">
                <TextInput id="new-setting-key" value={newKey} onChange={(e) => setNewKey(e.target.value)} placeholder={t('settings.key_placeholder')} required />
              </Field>
            </div>
            <div className="w-40">
              <Select value={newType} onChange={(e) => setNewType(e.target.value)} aria-label={t('settings.type')}>
                {EDIT_TYPES.map((t) => (
                  <option key={t} value={t}>
                    {t}
                  </option>
                ))}
              </Select>
            </div>
            <div className="min-w-0 flex-1">
              <Field label={t('settings.value')} htmlFor="new-setting-value">
                <TextInput id="new-setting-value" value={newValue} onChange={(e) => setNewValue(e.target.value)} placeholder={newType === 'json' ? t('settings.value_placeholder_json') : newType === 'boolean' ? t('settings.value_placeholder_boolean') : t('settings.value_placeholder_default')} />
              </Field>
            </div>
            <button type="submit" className="rounded-lg bg-clinical-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-clinical-indigo-700">
              {t('common.create')}
            </button>
            <button type="button" onClick={() => setShowNew(false)} className="rounded-lg border border-clinical-slate-200 px-4 py-2 text-sm text-clinical-slate-600 hover:bg-clinical-slate-50">
              {t('common.cancel')}
            </button>
          </form>
        </div>
      )}
    </div>
  )
}