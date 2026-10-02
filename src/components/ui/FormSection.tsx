import React from 'react'

interface FormSectionProps {
  title: string
  description?: string
  children: React.ReactNode
  twoColumn?: boolean
}

export const FormSection: React.FC<FormSectionProps> = ({ title, description, children, twoColumn = false }) => (
  <fieldset className="rounded-xl border border-clinical-slate-200 bg-white p-5 shadow-sm">
    <legend className="px-2 text-sm font-semibold text-clinical-slate-700">{title}</legend>
    {description && <p className="mb-4 mt-1 px-2 text-xs text-clinical-slate-500">{description}</p>}
    <div className={twoColumn ? 'grid grid-cols-1 gap-4 md:grid-cols-2' : 'flex flex-col gap-4'}>{children}</div>
  </fieldset>
)

interface FieldProps {
  label: string
  htmlFor?: string
  required?: boolean
  error?: string
  hint?: string
  children: React.ReactNode
}

export const Field: React.FC<FieldProps> = ({ label, htmlFor, required, error, hint, children }) => (
  <div>
    <label htmlFor={htmlFor} className="mb-1 block text-sm font-medium text-clinical-slate-700">
      {label}
      {required && (
        <span aria-hidden className="ml-0.5 text-red-500">
          *
        </span>
      )}
    </label>
    {children}
    {hint && !error && <p className="mt-1 text-xs text-clinical-slate-400">{hint}</p>}
    {error && (
      <p className="mt-1 text-xs font-medium text-red-600" role="alert">
        {error}
      </p>
    )}
  </div>
)

export const TextInput: React.FC<React.InputHTMLAttributes<HTMLInputElement>> = (props) => (
  <input
    {...props}
    className={`w-full rounded-lg border border-clinical-slate-300 bg-white px-3 py-2 text-sm text-clinical-slate-900 shadow-sm placeholder:text-clinical-slate-400 focus:border-clinical-indigo-500 focus:outline-none focus:ring-2 focus:ring-clinical-indigo-500 disabled:cursor-not-allowed disabled:bg-clinical-slate-50 ${props.className ?? ''}`}
  />
)

export const Select: React.FC<React.SelectHTMLAttributes<HTMLSelectElement>> = (props) => (
  <select
    {...props}
    className={`w-full rounded-lg border border-clinical-slate-300 bg-white px-3 py-2 text-sm text-clinical-slate-900 shadow-sm focus:border-clinical-indigo-500 focus:outline-none focus:ring-2 focus:ring-clinical-indigo-500 ${props.className ?? ''}`}
  />
)

export const TextArea: React.FC<React.TextareaHTMLAttributes<HTMLTextAreaElement>> = (props) => (
  <textarea
    {...props}
    className={`w-full rounded-lg border border-clinical-slate-300 bg-white px-3 py-2 text-sm text-clinical-slate-900 shadow-sm placeholder:text-clinical-slate-400 focus:border-clinical-indigo-500 focus:outline-none focus:ring-2 focus:ring-clinical-indigo-500 ${props.className ?? ''}`}
  />
)