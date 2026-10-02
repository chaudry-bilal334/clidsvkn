import React from 'react'

interface LoadingStateProps {
  label?: string
}

export const LoadingState: React.FC<LoadingStateProps> = ({ label = 'Loading…' }) => (
  <div className="flex min-h-[12rem] items-center justify-center" role="status" aria-live="polite">
    <div className="flex flex-col items-center gap-3">
      <div className="h-8 w-8 animate-spin rounded-full border-2 border-clinical-slate-200 border-t-clinical-indigo-600" />
      <p className="text-sm text-clinical-slate-500">{label}</p>
    </div>
  </div>
)

interface EmptyStateProps {
  title: string
  description?: string
  action?: React.ReactNode
  icon?: React.ReactNode
}

export const EmptyState: React.FC<EmptyStateProps> = ({ title, description, action, icon }) => (
  <div className="flex min-h-[12rem] flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-clinical-slate-300 bg-white p-8 text-center">
    {icon && <span aria-hidden className="text-clinical-slate-400">{icon}</span>}
    <div>
      <p className="text-sm font-medium text-clinical-slate-700">{title}</p>
      {description && <p className="mt-1 text-sm text-clinical-slate-500">{description}</p>}
    </div>
    {action}
  </div>
)

interface ErrorStateProps {
  title?: string
  message: string
  onRetry?: () => void
}

export const ErrorState: React.FC<ErrorStateProps> = ({
  title = 'Something went wrong',
  message,
  onRetry,
}) => (
  <div
    className="flex min-h-[12rem] flex-col items-center justify-center gap-3 rounded-xl border border-red-200 bg-red-50 p-8 text-center"
    role="alert"
  >
    <p className="text-sm font-semibold text-red-800">{title}</p>
    <p className="max-w-md text-sm text-red-700">{message}</p>
    {onRetry && (
      <button
        onClick={onRetry}
        className="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700"
      >
        Try again
      </button>
    )}
  </div>
)