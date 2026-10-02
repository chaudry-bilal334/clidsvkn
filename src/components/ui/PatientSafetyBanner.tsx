import React from 'react'

export interface SafetyItem {
  id: number
  category: string
  term: string
  severity?: string
  reaction?: string
}

export interface ReconciliationItem {
  medicine_name: string
  dosage: string
  frequency: string
  source: string
  is_active: boolean
}

interface PatientSafetyBannerProps {
  loading?: boolean
  allergies: SafetyItem[]
  medications: ReconciliationItem[]
  criticalNotes?: string[]
}

export const PatientSafetyBanner: React.FC<PatientSafetyBannerProps> = ({
  loading,
  allergies,
  medications,
  criticalNotes = [],
}) => {
  const hasCritical = allergies.some((a) => a.severity === 'critical') || criticalNotes.length > 0

  if (loading) {
    return (
      <div className="mb-6 rounded-xl border border-clinical-slate-200 bg-white p-4 text-sm text-clinical-slate-500">
        Loading safety information…
      </div>
    )
  }

  return (
    <div
      className={`mb-6 rounded-xl border p-4 ${
        hasCritical ? 'border-red-300 bg-red-50' : 'border-clinical-teal-200 bg-clinical-teal-50'
      }`}
      role="complementary"
      aria-label="Patient safety summary"
      data-testid="patient-safety-banner"
    >
      <div className="mb-2 flex items-center gap-2">
        <svg className="h-4 w-4 text-clinical-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path
            strokeLinecap="round"
            strokeLinejoin="round"
            strokeWidth={2}
            d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
          />
        </svg>
        <h3 className="text-sm font-semibold text-clinical-slate-800">Safety Summary</h3>
      </div>

      <div className="grid gap-3 md:grid-cols-3">
        <div>
          <p className="text-xs font-semibold uppercase tracking-wide text-clinical-slate-500">Allergies</p>
          {allergies.length === 0 ? (
            <p className="mt-1 text-sm text-clinical-teal-700">None recorded</p>
          ) : (
            <ul className="mt-1 space-y-1">
              {allergies.map((a) => (
                <li
                  key={a.id}
                  className={`text-sm ${a.severity === 'critical' ? 'font-medium text-red-700' : 'text-clinical-slate-700'}`}
                >
                  {a.term}
                  {a.reaction ? ` — ${a.reaction}` : ''}
                </li>
              ))}
            </ul>
          )}
        </div>

        <div>
          <p className="text-xs font-semibold uppercase tracking-wide text-clinical-slate-500">Current Medications</p>
          {medications.length === 0 ? (
            <p className="mt-1 text-sm text-clinical-teal-700">None recorded</p>
          ) : (
            <ul className="mt-1 space-y-1">
              {medications
                .filter((m) => m.is_active)
                .map((m, i) => (
                  <li key={i} className="text-sm text-clinical-slate-700">
                    {m.medicine_name} — {m.dosage} {m.frequency}
                  </li>
                ))}
            </ul>
          )}
        </div>

        <div>
          <p className="text-xs font-semibold uppercase tracking-wide text-clinical-slate-500">Critical History</p>
          {criticalNotes.length === 0 ? (
            <p className="mt-1 text-sm text-clinical-teal-700">None</p>
          ) : (
            <ul className="mt-1 space-y-1">
              {criticalNotes.map((n, i) => (
                <li key={i} className="text-sm font-medium text-red-700">
                  {n}
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>
    </div>
  )
}