import React from 'react'

type Tone = 'neutral' | 'info' | 'success' | 'warning' | 'danger' | 'critical' | 'progress' | 'final'

const toneClasses: Record<Tone, string> = {
  neutral: 'bg-clinical-slate-100 text-clinical-slate-700 border-clinical-slate-200',
  info: 'bg-clinical-indigo-50 text-clinical-indigo-700 border-clinical-indigo-200',
  success: 'bg-clinical-teal-50 text-clinical-teal-700 border-clinical-teal-200',
  warning: 'bg-amber-50 text-amber-700 border-amber-200',
  danger: 'bg-red-50 text-red-700 border-red-200',
  critical: 'bg-red-100 text-red-900 border-red-300',
  progress: 'bg-sky-50 text-sky-700 border-sky-200',
  final: 'bg-emerald-50 text-emerald-700 border-emerald-200',
}

const dotClasses: Record<Tone, string> = {
  neutral: 'bg-clinical-slate-400',
  info: 'bg-clinical-indigo-500',
  success: 'bg-clinical-teal-500',
  warning: 'bg-amber-500',
  danger: 'bg-red-500',
  critical: 'bg-red-600',
  progress: 'bg-sky-500',
  final: 'bg-emerald-500',
}

/** Map common domain statuses to a badge tone. Text + color (never color alone). */
export function statusTone(status: string): Tone {
  const s = status.toLowerCase()
  if (['finalized', 'paid', 'completed', 'dispensed', 'final', 'approved', 'confirmed', 'active'].includes(s)) {
    return 'success'
  }
  if (['final'].includes(s)) return 'final'
  if (['in_consultation', 'in_progress', 'processing', 'waiting', 'sample_collected', 'result_entered', 'awaiting_verification', 'booked'].includes(s))
    return 'progress'
  if (['critical', 'blocked', 'contraindicated'].includes(s)) return 'critical'
  if (['no_show', 'cancelled', 'lost', 'voided', 'rejected', 'expired', 'overdue'].includes(s)) return 'danger'
  if (['draft', 'pending', 'scheduled', 'new', 'contacted', 'generated', 'submitted'].includes(s)) return 'warning'
  return 'neutral'
}

interface StatusBadgeProps {
  status: string
  tone?: Tone
  withDot?: boolean
}

export const StatusBadge: React.FC<StatusBadgeProps> = ({ status, tone, withDot = true }) => {
  const resolvedTone = tone ?? statusTone(status)
  return (
    <span
      className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium ${toneClasses[resolvedTone]}`}
      role="status"
    >
      {withDot && <span aria-hidden className={`h-1.5 w-1.5 rounded-full ${dotClasses[resolvedTone]}`} />}
      {status.replace(/_/g, ' ')}
    </span>
  )
}