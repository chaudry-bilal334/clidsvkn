import React, { useState, useEffect } from 'react'
import { useAuth } from '../context/AuthContext'
import api from '../services/api'
import { StatusBadge } from '../components/ui/StatusBadge'
import { EmptyState, ErrorState, LoadingState } from '../components/ui/States'

interface FinancialMetric {
  label: string
  total: number
  target?: number
  period: 'daily' | 'monthly'
}

interface VisitVolume {
  date: string
  count: number
}

interface LabTest {
  name: string
  count: number
}

interface LowStockAudit {
  item_sku: string
  item_name: string
  current_stock: number
  reorder_level: number
  shortage: number
}

interface RevenueAnalytics {
  range?: { from: string; to: string }
  total_revenue?: number
  payment_count?: number
  by_payment_method?: Array<{ method: string; total: number }>
  by_insurance_carrier?: Array<{ carrier: string; total: number }>
}

interface UtilizationDoctor {
  doctor_name: string
  booked_slots: number
  available_slots: number
  utilization_pct: number
}

interface FunnelRow {
  status: string
  count: number
}

interface BranchVolume {
  branch_name: string
  visit_count: number
  unique_patients: number
}

interface ReportsViewProps {
  onLowStockAuditClick?: (item: LowStockAudit) => void
}

const ROLES = {
  ADMIN: 'admin' as const,
  ACCOUNTANT: 'accountant' as const,
  DOCTOR: 'doctor' as const,
  PHARMACIST: 'pharmacist' as const,
}

function hasFinancialRole(userRole: string): boolean {
  return userRole === ROLES.ADMIN || userRole === ROLES.ACCOUNTANT
}

export const ReportsView = ({ onLowStockAuditClick }: ReportsViewProps = {}) => {
  const { user } = useAuth()
  const userRole = user?.role || ''

  const [metrics, setMetrics] = useState<FinancialMetric[]>([])
  const [visitVolumes, setVisitVolumes] = useState<VisitVolume[]>([])
  const [labTests, setLabTests] = useState<LabTest[]>([])
  const [lowStockItems, setLowStockItems] = useState<LowStockAudit[]>([])
  const [revenue, setRevenue] = useState<RevenueAnalytics | null>(null)
  const [utilization, setUtilization] = useState<UtilizationDoctor[]>([])
  const [funnel, setFunnel] = useState<FunnelRow[]>([])
  const [branchVolumes, setBranchVolumes] = useState<BranchVolume[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const loadData = async () => {
    setLoading(true)
    setError(null)
    const settled = await Promise.allSettled([
      api.get('/reports/financial'),
      api.get('/reports/visits'),
      api.get('/reports/lab-tests'),
      api.get('/reports/low-stock'),
      api.get('/reports/analytics/revenue'),
      api.get('/reports/analytics/utilization'),
      api.get('/reports/analytics/funnel'),
      api.get('/reports/analytics/branch-volumes'),
    ])
    const [financialResp, visitsResp, labsResp, stockResp, revenueResp, utilResp, funnelResp, branchesResp] = settled

    if (financialResp.status === 'fulfilled') setMetrics(financialResp.value.data.data || [])
    if (visitsResp.status === 'fulfilled') setVisitVolumes(visitsResp.value.data.data || [])
    if (labsResp.status === 'fulfilled') setLabTests(labsResp.value.data.data || [])
    if (stockResp.status === 'fulfilled') setLowStockItems(stockResp.value.data.data?.items || [])
    if (revenueResp.status === 'fulfilled') setRevenue(revenueResp.value.data.data ?? null)
    if (utilResp.status === 'fulfilled') setUtilization(utilResp.value.data.data?.doctors ?? [])
    if (funnelResp.status === 'fulfilled') setFunnel(funnelResp.value.data.data?.funnel ?? [])
    if (branchesResp.status === 'fulfilled') setBranchVolumes(branchesResp.value.data.data ?? [])

    const anyFailures = settled.some((r) => r.status === 'rejected')
    if (anyFailures) setError('Some reports could not be loaded.')
    setLoading(false)
  }

  useEffect(() => {
    loadData()
  }, [])

  const maxVisits = Math.max(0, ...visitVolumes.map((v) => v.count))
  const maxLab = Math.max(0, ...labTests.map((t) => t.count))
  const maxFunnel = Math.max(0, ...funnel.map((f) => f.count))
  const canViewFinancial = hasFinancialRole(userRole)

  if (loading) return <LoadingState label="Loading reports…" />

  if (error) return <ErrorState title="Partial report load" message={error} onRetry={loadData} />

  return (
    <div className="mx-auto max-w-6xl">
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold text-clinical-slate-900">Reports & Analytics</h1>
          {revenue?.range && (
            <p className="mt-1 text-sm text-clinical-slate-500">
              {revenue.range.from} → {revenue.range.to} (last 30 days by default)
            </p>
          )}
        </div>
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {metrics.map((metric, index) => (
          <div key={index} className="clinical-card flex items-start justify-between p-4">
            <span className="text-sm text-clinical-slate-500">{metric.label}</span>
            <span className="text-lg font-semibold text-clinical-slate-900">{metric.total}</span>
          </div>
        ))}
      </div>

      <div className="clinical-card mt-6 p-6">
        <h2 className="mb-4 font-medium text-clinical-slate-900">Patient visit volumes</h2>
        {visitVolumes.length === 0 ? (
          <EmptyState title="No visit data available." />
        ) : (
          <div className="flex h-48 items-end gap-1.5 overflow-x-auto">
            {visitVolumes.map((v) => (
              <div key={v.date} className="flex h-full min-w-[18px] flex-col-reverse items-center gap-1" title={`${v.date}: ${v.count}`}>
                <span className="text-[10px] text-clinical-slate-400">{v.count}</span>
                <div
                  className="w-4 rounded-t bg-clinical-indigo-500"
                  style={{ height: `${maxVisits > 0 ? Math.round((v.count / maxVisits) * 100) : 0}%` }}
                />
              </div>
            ))}
          </div>
        )}
      </div>

      <div className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <section className="clinical-card p-6">
          <h2 className="mb-4 font-medium text-clinical-slate-900">Popular lab tests</h2>
          {labTests.length === 0 ? (
            <EmptyState title="No lab test data yet." />
          ) : (
            <ul className="space-y-3">
              {labTests.slice(0, 6).map((test) => (
                <li key={test.name}>
                  <div className="mb-1 flex items-center justify-between text-sm">
                    <span className="text-clinical-slate-600">{test.name}</span>
                    <span className="font-medium text-clinical-indigo-600">{test.count}</span>
                  </div>
                  <div className="h-2 rounded-full bg-clinical-slate-100">
                    <div
                      className="h-2 rounded-full bg-clinical-indigo-500"
                      style={{ width: `${maxLab > 0 ? Math.round((test.count / maxLab) * 100) : 0}%` }}
                    />
                  </div>
                </li>
              ))}
            </ul>
          )}
        </section>

        <section className="clinical-card p-6">
          <h2 className="mb-4 font-medium text-clinical-slate-900">Booking funnel</h2>
          {funnel.length === 0 ? (
            <EmptyState title="No funnel data yet." />
          ) : (
            <ul className="space-y-3">
              {funnel.map((row) => (
                <li key={row.status}>
                  <div className="mb-1 flex items-center justify-between text-sm">
                    <span className="capitalize text-clinical-slate-600">{row.status.replace(/_/g, ' ')}</span>
                    <span className="font-medium text-clinical-slate-700">{row.count}</span>
                  </div>
                  <div className="h-2 rounded-full bg-clinical-slate-100">
                    <div
                      className="h-2 rounded-full bg-clinical-indigo-500"
                      style={{ width: `${maxFunnel > 0 ? Math.round((row.count / maxFunnel) * 100) : 0}%` }}
                    />
                  </div>
                </li>
              ))}
            </ul>
          )}
        </section>
      </div>

      {utilization.length > 0 && (
        <section className="clinical-card mt-6 p-6">
          <h2 className="mb-4 font-medium text-clinical-slate-900">Doctor utilization</h2>
          <div className="overflow-x-auto">
            <table className="w-full min-w-max divide-y divide-clinical-slate-100">
              <thead className="bg-clinical-slate-50">
                <tr>
                  {['Doctor', 'Booked', 'Available', 'Utilization'].map((h) => (
                    <th key={h} className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-clinical-slate-500">
                      {h}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {utilization.map((d) => (
                  <tr key={d.doctor_name} className="hover:bg-clinical-slate-50">
                    <td className="px-4 py-3 text-sm font-medium text-clinical-slate-800">{d.doctor_name}</td>
                    <td className="px-4 py-3 text-sm text-clinical-slate-600">{d.booked_slots}</td>
                    <td className="px-4 py-3 text-sm text-clinical-slate-600">{d.available_slots}</td>
                    <td className="px-4 py-3">
                      <div className="flex items-center gap-2">
                        <div className="h-2 w-28 rounded-full bg-clinical-slate-100">
                          <div
                            className="h-2 rounded-full bg-clinical-indigo-500"
                            style={{ width: `${Math.min(100, d.utilization_pct)}%` }}
                          />
                        </div>
                        <span className="text-xs text-clinical-slate-500">{d.utilization_pct}%</span>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}

      {branchVolumes.length > 0 && (
        <section className="clinical-card mt-6 p-6">
          <h2 className="mb-4 font-medium text-clinical-slate-900">Branch volumes</h2>
          <ul className="divide-y divide-clinical-slate-100">
            {branchVolumes.map((b) => (
              <li key={b.branch_name} className="flex items-center justify-between py-2.5">
                <span className="text-sm font-medium text-clinical-slate-800">{b.branch_name}</span>
                <span className="text-sm text-clinical-slate-500">
                  {b.visit_count} visits · {b.unique_patients} unique patients
                </span>
              </li>
            ))}
          </ul>
        </section>
      )}

      {canViewFinancial && revenue && (
        <section className="clinical-card mt-6 p-6">
          <h2 className="mb-4 font-medium text-clinical-slate-900">Revenue analytics</h2>
          <div className="grid gap-4 sm:grid-cols-3">
            <div className="rounded-lg border border-clinical-slate-100 p-4">
              <p className="text-xs text-clinical-slate-400">Total revenue</p>
              <p className="mt-1 text-xl font-semibold text-clinical-slate-900">
                ${(revenue.total_revenue ?? 0).toFixed(2)}
              </p>
              <p className="text-xs text-clinical-slate-400">{revenue.payment_count ?? 0} payments</p>
            </div>
            <div>
              <p className="mb-2 text-xs font-medium uppercase tracking-wider text-clinical-slate-400">By payment method</p>
              <ul className="space-y-1">
                {(revenue.by_payment_method ?? []).map((m) => (
                  <li key={m.method} className="flex justify-between text-sm capitalize">
                    <span className="text-clinical-slate-600">{m.method.replace(/_/g, ' ')}</span>
                    <span className="text-clinical-slate-800">${m.total.toFixed(2)}</span>
                  </li>
                ))}
              </ul>
            </div>
            <div>
              <p className="mb-2 text-xs font-medium uppercase tracking-wider text-clinical-slate-400">By insurance carrier</p>
              <ul className="space-y-1">
                {(revenue.by_insurance_carrier ?? []).length === 0 ? (
                  <li className="text-sm text-clinical-slate-400">No insurance splits</li>
                ) : (
                  revenue.by_insurance_carrier!.map((c) => (
                    <li key={c.carrier} className="flex justify-between text-sm">
                      <span className="text-clinical-slate-600">{c.carrier}</span>
                      <span className="text-clinical-slate-800">${c.total.toFixed(2)}</span>
                    </li>
                  ))
                )}
              </ul>
            </div>
          </div>
        </section>
      )}

      <section className="clinical-card mt-6 p-6">
        <div className="mb-4 flex items-center justify-between">
          <h2 className="font-medium text-clinical-slate-900">Low-stock audits</h2>
          <StatusBadge status={lowStockItems.length > 0 ? `${lowStockItems.length} alerts` : 'ok'} tone={lowStockItems.length > 0 ? 'danger' : 'success'} />
        </div>
        {lowStockItems.length === 0 ? (
          <EmptyState title="No low-stock items." />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-max divide-y divide-clinical-slate-100">
              <thead className="bg-clinical-slate-50">
                <tr>
                  {['SKU', 'Item', 'Current stock', 'Reorder level', 'Shortage'].map((h) => (
                    <th key={h} className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-clinical-slate-500">
                      {h}
                    </th>
                  ))}
                  {onLowStockAuditClick && <th className="px-4 py-3" />}
                </tr>
              </thead>
              <tbody>
                {lowStockItems.map((item) => (
                  <tr key={item.item_sku} className={item.shortage > 0 ? 'bg-red-50/40 hover:bg-red-50' : 'hover:bg-clinical-slate-50'}>
                    <td className="px-4 py-3 text-sm font-medium text-clinical-slate-900">{item.item_sku}</td>
                    <td className="px-4 py-3 text-sm text-clinical-slate-600">{item.item_name}</td>
                    <td className="px-4 py-3 text-sm font-medium text-clinical-slate-900">{item.current_stock}</td>
                    <td className="px-4 py-3 text-sm text-clinical-slate-600">{item.reorder_level}</td>
                    <td className="px-4 py-3 text-sm font-medium text-red-600">{item.shortage}</td>
                    {onLowStockAuditClick && (
                      <td className="px-4 py-3 text-right">
                        <button onClick={() => onLowStockAuditClick(item)} className="text-sm font-medium text-clinical-indigo-600 hover:underline">
                          Details
                        </button>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </div>
  )
}