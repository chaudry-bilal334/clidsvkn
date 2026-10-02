import React from 'react'

interface StatCardProps {
  label: string
  value: string | number
  hint?: string
  icon?: React.ReactNode
  tone?: 'neutral' | 'success' | 'warning' | 'danger'
}

const iconTones: Record<NonNullable<StatCardProps['tone']>, string> = {
  neutral: 'bg-clinical-indigo-50 text-clinical-indigo-600',
  success: 'bg-clinical-teal-50 text-clinical-teal-600',
  warning: 'bg-amber-50 text-amber-600',
  danger: 'bg-red-50 text-red-600',
}

export const StatCard: React.FC<StatCardProps> = ({ label, value, hint, icon, tone = 'neutral' }) => (
  <div className="rounded-xl border border-clinical-slate-200 bg-white p-5 shadow-sm">
    <div className="flex items-start justify-between gap-3">
      <div className="min-w-0">
        <p className="text-sm font-medium text-clinical-slate-500">{label}</p>
        <p className="mt-2 text-2xl font-semibold text-clinical-slate-900" data-testid={`stat-${label}`}>
          {value}
        </p>
        {hint && <p className="mt-1 text-xs text-clinical-slate-400">{hint}</p>}
      </div>
      {icon && (
        <span aria-hidden className={`flex h-10 w-10 items-center justify-center rounded-lg ${iconTones[tone]}`}>
          {icon}
        </span>
      )}
    </div>
  </div>
)