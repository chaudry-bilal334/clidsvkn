import React, { createContext, useCallback, useContext, useState } from 'react'

type ToastTone = 'success' | 'error' | 'info' | 'warning'

interface Toast {
  id: number
  tone: ToastTone
  message: string
}

interface ToastContextType {
  toast: (message: string, tone?: ToastTone) => void
}

const ToastContext = createContext<ToastContextType | undefined>(undefined)

const toneClasses: Record<ToastTone, string> = {
  success: 'border-clinical-teal-200 bg-clinical-teal-50 text-clinical-teal-800',
  error: 'border-red-200 bg-red-50 text-red-800',
  info: 'border-clinical-indigo-200 bg-clinical-indigo-50 text-clinical-indigo-800',
  warning: 'border-amber-200 bg-amber-50 text-amber-800',
}

let toastId = 0

export const ToastProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [toasts, setToasts] = useState<Toast[]>([])

  const dismiss = useCallback((id: number) => {
    setToasts((prev) => prev.filter((t) => t.id !== id))
  }, [])

  const toast = useCallback(
    (message: string, tone: ToastTone = 'info') => {
      const id = ++toastId
      setToasts((prev) => [...prev, { id, tone, message }])
      window.setTimeout(() => dismiss(id), 5000)
    },
    [dismiss],
  )

  return (
    <ToastContext.Provider value={{ toast }}>
      {children}
      <div className="pointer-events-none fixed bottom-4 right-4 z-[60] flex w-80 flex-col gap-2" aria-live="polite">
        {toasts.map((t) => (
          <div
            key={t.id}
            role="status"
            className={`pointer-events-auto flex items-start justify-between gap-3 rounded-lg border px-4 py-3 shadow-lg ${toneClasses[t.tone]}`}
          >
            <p className="text-sm">{t.message}</p>
            <button onClick={() => dismiss(t.id)} aria-label="Dismiss" className="shrink-0 opacity-60 hover:opacity-100">
              <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  )
}

export const useToast = () => {
  const context = useContext(ToastContext)
  if (!context) {
    throw new Error('useToast must be used within a ToastProvider')
  }
  return context
}