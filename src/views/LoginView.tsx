import React, { useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { useTranslation } from 'react-i18next'

interface LoginErrors {
  email?: string
  password?: string
  code?: string
  general?: string
}

export const LoginView: React.FC = () => {
  const { status, login, completeTwoFactor } = useAuth()
  const needTwoFactor = status === 'two-factor'
  const { t } = useTranslation()

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [code, setCode] = useState('')
  const [remember, setRemember] = useState(false)
  const [errors, setErrors] = useState<LoginErrors>({})
  const [submitting, setSubmitting] = useState(false)

  const validateCredentials = (): boolean => {
    const next: LoginErrors = {}
    if (!email.trim()) next.email = t('login.error_email')
    if (!password) next.password = t('login.error_password')
    setErrors(next)
    return Object.keys(next).length === 0
  }

  const handleCredentials = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!validateCredentials()) return
    setSubmitting(true)
    setErrors({})
    try {
      await login(email.trim(), password, remember)
    } catch (err: unknown) {
      const statusCode = (err as { response?: { status?: number } })?.response?.status
      setErrors({
        general:
          statusCode === 401
            ? t('login.error_invalid')
            : statusCode === 429
              ? t('login.error_too_many')
              : t('login.error_unable'),
      })
    } finally {
      setSubmitting(false)
    }
  }

  const handleTwoFactor = async (e: React.FormEvent) => {
    e.preventDefault()
    setSubmitting(true)
    setErrors({})
    try {
      await completeTwoFactor(code.trim())
    } catch {
      setErrors({ code: t('login.error_code') })
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="flex min-h-screen bg-clinical-slate-50">
      <div className="hidden flex-1 items-center justify-center bg-gradient-to-br from-clinical-indigo-700 to-clinical-teal-600 p-12 lg:flex">
        <div className="max-w-md text-white">
          <div className="mb-6 flex items-center gap-3">
            <span className="flex h-12 w-12 items-center justify-center rounded-xl bg-white/15 text-lg font-bold">CS</span>
            <span className="text-xl font-semibold">{t('app.name')}</span>
          </div>
          <h1 className="text-3xl font-bold leading-tight">{t('login.subtitle') || 'Care, scheduling, and billing under one calm roof.'}</h1>
          <p className="mt-4 text-clinical-teal-50/90">{t('login.description') || 'Secure staff workspace. Every action is recorded in the audit trail.'}</p>
        </div>
      </div>

      <div className="flex flex-1 items-center justify-center p-6">
        <div className="w-full max-w-sm">
          <div className="mb-8 lg:hidden">
            <span className="flex h-12 w-12 items-center justify-center rounded-xl bg-clinical-indigo-600 text-lg font-bold text-white">
              CS
            </span>
          </div>

          <h2 className="text-2xl font-semibold text-clinical-slate-900">
            {needTwoFactor ? t('login.two_factor') : t('login.submit')}
          </h2>
          <p className="mt-1 text-sm text-clinical-slate-500">
            {needTwoFactor
              ? t('login.code_instruction')
              : t('login.credentials_continue')}
          </p>

          {errors.general && (
            <div className="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
              {errors.general}
            </div>
          )}

          {!needTwoFactor ? (
            <form onSubmit={handleCredentials} className="mt-8 space-y-5" noValidate>
              <div>
                <label htmlFor="email" className="mb-1.5 block text-sm font-medium text-clinical-slate-700">
                  Email address
                </label>
                <input
                  id="email"
                  type="email"
                  autoComplete="username"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  placeholder="you@clinic.com"
                  className={`w-full rounded-lg border bg-white px-3.5 py-2.5 text-sm shadow-sm placeholder:text-clinical-slate-400 focus:outline-none focus:ring-2 ${
                    errors.email
                      ? 'border-red-300 focus:border-red-400 focus:ring-red-100'
                      : 'border-clinical-slate-300 focus:border-clinical-indigo-500 focus:ring-clinical-indigo-100'
                  }`}
                />
                {errors.email && <p className="mt-1 text-xs text-red-600">{errors.email}</p>}
              </div>

              <div>
                <div className="mb-1.5 flex items-center justify-between">
                  <label htmlFor="password" className="block text-sm font-medium text-clinical-slate-700">
                    Password
                  </label>
                </div>
                <input
                  id="password"
                  type="password"
                  autoComplete="current-password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  placeholder="••••••••"
                  className={`w-full rounded-lg border bg-white px-3.5 py-2.5 text-sm shadow-sm placeholder:text-clinical-slate-400 focus:outline-none focus:ring-2 ${
                    errors.password
                      ? 'border-red-300 focus:border-red-400 focus:ring-red-100'
                      : 'border-clinical-slate-300 focus:border-clinical-indigo-500 focus:ring-clinical-indigo-100'
                  }`}
                />
                {errors.password && <p className="mt-1 text-xs text-red-600">{errors.password}</p>}
              </div>

              <label className="flex items-center gap-2 text-sm text-clinical-slate-600">
                <input
                  type="checkbox"
                  checked={remember}
                  onChange={(e) => setRemember(e.target.checked)}
                  className="h-4 w-4 rounded border-clinical-slate-300 text-clinical-indigo-600 focus:ring-clinical-indigo-200"
                />
                {t('common.keep_me_signed_in')}
              </label>

              <button
                type="submit"
                disabled={submitting}
                className="w-full rounded-lg bg-clinical-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-clinical-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
              >
                {submitting ? t('common.signing_in') : t('common.sign_in')}
              </button>

              <p className="text-center text-sm text-clinical-slate-500">
                Not staff?{' '}
                <Link to="/public" className="font-medium text-clinical-indigo-600 hover:underline">
                  {t('login.public_website')}
                </Link>
              </p>
            </form>
          ) : (
            <form onSubmit={handleTwoFactor} className="mt-8 space-y-5" noValidate>
              <div>
                <label htmlFor="code" className="mb-1.5 block text-sm font-medium text-clinical-slate-700">
                  {t('login.authenticator_code')}
                </label>
                <input
                  id="code"
                  type="text"
                  inputMode="numeric"
                  autoComplete="one-time-code"
                  maxLength={6}
                  value={code}
                  onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
                  placeholder="000000"
                  className={`w-full rounded-lg border bg-white px-3.5 py-2.5 text-center text-lg tracking-[0.5em] shadow-sm focus:outline-none focus:ring-2 ${
                    errors.code
                      ? 'border-red-300 focus:border-red-400 focus:ring-red-100'
                      : 'border-clinical-slate-300 focus:border-clinical-indigo-500 focus:ring-clinical-indigo-100'
                  }`}
                />
                {errors.code && <p className="mt-1 text-xs text-red-600">{errors.code}</p>}
              </div>

              <button
                type="submit"
                disabled={submitting || code.length !== 6}
                className="w-full rounded-lg bg-clinical-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-clinical-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
              >
                {submitting ? t('common.verifying') : t('common.verify_code')}
              </button>
            </form>
          )}
        </div>
      </div>
    </div>
  )
}