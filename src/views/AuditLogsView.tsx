import React, { useState, useEffect } from 'react'
import api from '../services/api'
import { useTranslation } from 'react-i18next'
import { StatusBadge } from '../components/ui/StatusBadge'
import { Pagination } from '../components/ui/DataTable'
import { EmptyState, ErrorState, LoadingState } from '../components/ui/States'

interface SecurityEvent {
  id: number
  timestamp: string
  severity: 'info' | 'warning' | 'critical'
  actor: string
  ip_address: string
  action: string
  details?: string
}

interface PageMeta {
  page: number
  per_page: number
  total: number
  last_page: number
}

const initialMeta: PageMeta = { page: 1, per_page: 25, total: 0, last_page: 1 }

export const AuditLogsView: React.FC = () => {
  const { t } = useTranslation()
  const [events, setEvents] = useState<SecurityEvent[]>([])
  const [meta, setMeta] = useState<PageMeta>(initialMeta)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const loadPage = (page = meta.page) => {
    setLoading(true)
    api
      .get('/security-events', { params: { page, per_page: meta.per_page } })
      .then((res) => {
        setEvents(res.data.data ?? [])
        setMeta(res.data.meta ?? { ...initialMeta, page })
      })
      .catch(() => setError(t('audit_logs.load_error')))
      .finally(() => setLoading(false))
  }

  useEffect(() => {
    loadPage(1)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  if (loading) return <LoadingState label={t('audit_logs.loading')} />
  if (error) return <ErrorState message={error} onRetry={() => loadPage(meta.page)} />
  if (events.length === 0) return <EmptyState title={t('audit_logs.no_events')} />

  return (
    <div className="mx-auto max-w-6xl">
      <div className="mb-4 flex items-center justify-between">
        <h1 className="text-2xl font-semibold text-clinical-slate-900">{t('audit_logs.title')}</h1>
        <span className="text-sm text-clinical-slate-400">{t('audit_logs.total_events', { total: meta.total })} events</span>
      </div>

      <div className="clinical-card overflow-x-auto">
        <table className="w-full min-w-max divide-y divide-clinical-slate-100">
          <thead className="bg-clinical-slate-50">
            <tr>
              {['timestamp', 'severity', 'actor', 'ip_address', 'action', 'details'].map((h) => (
                <th key={h} className="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-clinical-slate-500">
                  {t(`audit_logs.${h}`)}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {events.map((event) => (
              <tr key={event.id} className="hover:bg-clinical-slate-50">
                <td className="px-5 py-3.5 whitespace-nowrap text-sm text-clinical-slate-500">
                  {new Date(event.timestamp).toLocaleString()}
                </td>
                <td className="px-5 py-3.5 whitespace-nowrap">
                  <StatusBadge status={event.severity} tone={event.severity === 'critical' ? 'critical' : event.severity === 'warning' ? 'warning' : 'info'} />
                </td>
                <td className="px-5 py-3.5 whitespace-nowrap text-sm text-clinical-slate-700">{event.actor}</td>
                <td className="px-5 py-3.5 whitespace-nowrap text-sm text-clinical-slate-500">{event.ip_address ?? '—'}</td>
                <td className="px-5 py-3.5 whitespace-nowrap text-sm font-medium text-clinical-slate-800">
                  {event.action.replace(/_/g, ' ')}
                </td>
                <td className="px-5 py-3.5 text-sm text-clinical-slate-500">{event.details ?? '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
        <div className="px-5 py-3">
          <Pagination
            page={meta.page}
            lastPage={meta.last_page}
            total={meta.total}
            onPageChange={(page) => loadPage(page)}
          />
        </div>
      </div>
    </div>
  )
}