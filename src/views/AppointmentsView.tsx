import { useState, useEffect } from 'react'
import api from '../services/api'

interface Appointment {
  id: number
  patient_name: string
  patient_id: number
  date: string
  time: string
  status: 'scheduled' | 'confirmed' | 'completed' | 'cancelled'
  reason: string
}

interface AppointmentsViewProps {
  onAppointmentUpdated: (appointment: Appointment) => void
}

export const AppointmentsView = ({ onAppointmentUpdated }: AppointmentsViewProps) => {
  const [appointments, setAppointments] = useState<Appointment[]>([])
  const [loading, setLoading] = useState(true)
  const [statusFilter, setStatusFilter] = useState<'all' | 'scheduled' | 'confirmed' | 'completed' | 'cancelled'>('all')

  useEffect(() => {
    const loadAppointments = async () => {
      setLoading(true)
      try {
        const response = await api.get('/appointments')
        setAppointments(response.data.data || [])
      } catch (error) {
        console.error('Failed to load appointments:', error)
      } finally {
        setLoading(false)
      }
    }
    loadAppointments()
  }, [statusFilter])

  const filteredAppointments = appointments.filter((apt) => {
    if (statusFilter === 'all') return true
    return apt.status === statusFilter
  })

  const handleStatusChange = async (appointmentId: number, newStatus: Appointment['status']) => {
    try {
      const response = await api.put(`/appointments/${appointmentId}`, { status: newStatus })
      setAppointments((prev) =>
        prev.map((apt) => (apt.id === appointmentId ? response.data.data || apt : apt))
      )
      onAppointmentUpdated?.(response.data.data || { ...appointments.find((a) => a.id === appointmentId)!, status: newStatus })
    } catch (error) {
      console.error('Failed to update appointment status:', error)
    }
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-col sm:flex-row gap-2">
        <select
          onChange={(e) => setStatusFilter(e.target.value as any)}
          className="rounded border border-clinical-slate-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-clinical-indigo-200"
        >
          <option value="all">All Appointments</option>
          <option value="scheduled">Scheduled</option>
          <option value="confirmed">Confirmed</option>
          <option value="completed">Completed</option>
          <option value="cancelled">Cancelled</option>
        </select>

        <button
          className="bg-clinical-teal-600 text-white px-4 py-2 rounded hover:bg-clinical-teal-700 transition-colors"
        >
          Schedule New
        </button>
      </div>

      {loading ? (
        <div className="animate-pulse rounded bg-clinical-slate-800 p-4">
          Loading appointments...
        </div>
      ) : (
        <div className="overflow-x-auto rounded bg-white p-4 shadow-sm">
          <table className="w-full min-w-full divide-y divide-clinical-slate-200">
            <thead className="bg-clinical-slate-50">
              <tr>
                <th className="px-6 py-3 text-left text-xs font-medium text-clinical-slate-500 uppercase tracking-wider">
                  Patient
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-clinical-slate-500 uppercase tracking-wider">
                  Date & Time
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-clinical-slate-500 uppercase tracking-wider">
                  Reason
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-clinical-slate-500 uppercase tracking-wider">
                  Status
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-clinical-slate-500 uppercase tracking-wider">
                  Actions
                </th>
              </tr>
            </thead>
            <tbody>
              {filteredAppointments.map((appointment) => (
                <tr key={appointment.id} className="hover:bg-clinical-slate-50">
                  <td className="px-6 py-4 whitespace-nowrap">
                    <div className="text-sm font-medium text-clinical-slate-900">{appointment.patient_name}</div>
                  </td>
                  <td className="px-6 py-4 whitespace-nap">
                    <div className="text-sm text-clinical-slate-500">
                      {new Date(appointment.date).toLocaleDateString()} at {appointment.time}
                    </div>
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap">
                    <div className="text-sm text-clinical-slate-500">{appointment.reason}</div>
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap">
                    <span className={`px-2 inline-flex text-xs rounded ${appointment.status === 'scheduled' ? 'bg-clinical-indigo-100 text-clinical-indigo-600' : appointment.status === 'confirmed' ? 'bg-clinical-teal-100 text-clinical-teal-600' : appointment.status === 'completed' ? 'bg-clinical-slate-100 text-clinical-slate-600' : 'bg-clinical-slate-200 text-clinical-slate-500'}`}>
                      {appointment.status}
                    </span>
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                    <select
                      onChange={(e) => handleStatusChange(appointment.id, e.target.value as any)}
                      className="rounded border border-clinical-slate-300 px-2 py-1 focus:outline-none focus:ring-2 focus:ring-clinical-indigo-200"
                    >
                      <option value="scheduled">Scheduled</option>
                      <option value="confirmed">Confirmed</option>
                      <option value="completed">Completed</option>
                      <option value="cancelled">Cancelled</option>
                    </select>
                  </td>
                </tr>
              ))}
              {filteredAppointments.length === 0 && (
                <tr>
                  <td colSpan={5} className="px-6 py-4 text-center text-clinical-slate-400">
                    No appointments found
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}