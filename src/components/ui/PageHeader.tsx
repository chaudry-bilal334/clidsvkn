import React from 'react'

interface PageHeaderProps {
  title: string
  description?: string
  actions?: React.ReactNode
}

export const PageHeader: React.FC<PageHeaderProps> = ({ title, description, actions }) => (
  <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
    <div>
      <h1 className="text-2xl font-semibold text-clinical-slate-900">{title}</h1>
      {description && <p className="mt-1 text-sm text-clinical-slate-500">{description}</p>}
    </div>
    {actions && <div className="flex items-center gap-2">{actions}</div>}
  </div>
)

export const PrimaryButton: React.FC<React.ButtonHTMLAttributes<HTMLButtonElement>> = (props) => (
  <button
    {...props}
    className={`inline-flex items-center justify-center rounded-lg bg-clinical-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition-colors hover:bg-clinical-indigo-700 focus:outline-none focus:ring-2 focus:ring-clinical-indigo-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 ${props.className ?? ''}`}
  />
)

export const SecondaryButton: React.FC<React.ButtonHTMLAttributes<HTMLButtonElement>> = (props) => (
  <button
    {...props}
    className={`inline-flex items-center justify-center rounded-lg border border-clinical-slate-300 bg-white px-4 py-2 text-sm font-medium text-clinical-slate-700 shadow-sm transition-colors hover:bg-clinical-slate-50 focus:outline-none focus:ring-2 focus:ring-clinical-indigo-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 ${props.className ?? ''}`}
  />
)

export const DangerButton: React.FC<React.ButtonHTMLAttributes<HTMLButtonElement>> = (props) => (
  <button
    {...props}
    className={`inline-flex items-center justify-center rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition-colors hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 ${props.className ?? ''}`}
  />
)