import { useState, useEffect } from 'react'
import api from '../services/api'
import { useAuth } from '../context/AuthContext'

interface PrescriptionItem {
  medicine_name: string
  dosage: string
  frequency: string
  duration: string
  instructions: string
}

interface Prescription {
  id: number
  patient_id: number
  appointment_id: number | null
  items: PrescriptionItem[]
  status: 'pending' | 'in_progress' | 'completed' | 'cancelled'
  created_at: string
}

interface LabOrder {
  id: number
  patient_id: number
  test_name: string
  status: 'pending' | 'in_progress' | 'completed' | 'cancelled'
  result?: string
  recorded_at: string
}

interface PrescriptionsViewProps {
  patientId: number
  appointmentId: number | null
  onPrescriptionCreated: (prescription: Prescription) => void
  onLabOrderUpdated: (labOrder: LabOrder) => void
}

export const PrescriptionsView = ({
  patientId,
  appointmentId,
  onPrescriptionCreated,
  onLabOrderUpdated
}: PrescriptionsViewProps) => {
  const [prescriptions, setPrescriptions] = useState<Prescription[]>([])
  const [labOrders, setLabOrders] = useState<LabOrder[]>([])
  const [loading, setLoading] = useState(true)
  const [form, setForm] = useState<{
    medicine_name: string
    dosage: string
    frequency: string
    duration: string
    instructions: string
    items: PrescriptionItem[]
  }>({
    medicine_name: '',
    dosage: '',
    frequency: '',
    duration: '',
    instructions: '',
    items: []
  })
  const [editingLabOrder, setEditingLabOrder] = useState<LabOrder | null>(null)
  const [newLabOrder, setNewLabOrder] = useState<{
    test_name: string
  }>({ test_name: '' })
  const { user } = useAuth()

  useEffect(() => {
    const loadData = async () => {
      setLoading(true)
      try {
        const [rxResp, labResp] = await Promise.all([
          api.get(`/prescriptions?patient_id=${patientId}`),
          api.get(`/lab-orders?patient_id=${patientId}`)
        ])
        setPrescriptions(rxResp.data.data || [])
        setLabOrders(labResp.data.data || [])
      } catch (error) {
        console.error('Failed to load prescriptions/lab orders:', error)
      } finally {
        setLoading(false)
      }
    }
    loadData()
  }, [patientId])

  const handleAddItem = () => {
    setForm({
      ...form,
      medicine_name: '',
      dosage: '',
      frequency: '',
      duration: '',
      instructions: ''
    })
  }

  const handleAddPrescription = async () => {
    if (!form.medicine_name.trim()) return
    const newPrescriptionItem: PrescriptionItem = { ...form }
    const newPrescription: Prescription = {
      id: Date.now(),
      patient_id: patientId,
      appointment_id: appointmentId,
      items: [...(form.items || []), newPrescriptionItem],
      status: 'pending',
      created_at: new Date().toISOString()
    }
    try {
      const response = await api.post('/prescriptions', newPrescription)
      setPrescriptions([response.data.data || newPrescription, ...prescriptions])
      onPrescriptionCreated?.(response.data.data || newPrescription)
      handleAddItem()
    } catch (error) {
      console.error('Failed to create prescription:', error)
    }
  }

  const handleUpdateLabOrder = async (id: number, updates: Partial<LabOrder>) => {
    try {
      const response = await api.put(`/lab-orders/${id}`, updates)
      setLabOrders(labOrders.map((lo) => (lo.id === id ? response.data.data || lo : lo)))
      onLabOrderUpdated?.(response.data.data || labOrders.find((lo) => lo.id === id)!)
    } catch (error) {
      console.error('Failed to update lab order:', error)
    }
  }

  const handleResultEntry = (labOrderId: number, result: string) => {
    handleUpdateLabOrder(labOrderId, { result, status: 'completed' as const })
  }

  return (
    <div className="space-y-4">
      {/* Doctor Prescription Creation Form */}
      <div>
        <h2 className="font-semibold text-clinical-slate-900 mb-4">
          {appointmentId ? 'Prescribe for Appointment' : 'New Prescription'}
        </h2>

        <div className="bg-white rounded-lg p-4 shadow-sm">
          <form
            className="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4"
            onSubmit={(e) => {
              e.preventDefault()
              handleAddPrescription()
            }}
          >
            <div>
              <label className="block text-sm font-medium text-clinical-slate-500 mb-1">
                Medicine Name
              </label>
              <input
                type="text"
                value={form.medicine_name}
                onChange={(e) =>
                  setForm({ ...form, medicine_name: e.target.value })
                }
                className="rounded border border-clinical-slate-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-clinical-indigo-200"
                required
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-clinical-slate-500 mb-1">
                Dosage
              </label>
              <input
                type="text"
                value={form.dosage}
                onChange={(e) =>
                  setForm({ ...form, dosage: e.target.value })
                }
                className="rounded border border-clinical-slate-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-clinical-indigo-200"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-clinical-slate-500 mb-1">
                Frequency
              </label>
              <input
                type="text"
                value={form.frequency}
                onChange={(e) =>
                  setForm({ ...form, frequency: e.target.value })
                }
                className="rounded border border-clinical-slate-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-clinical-indigo-200"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-clinical-slate-500 mb-1">
                Duration
              </label>
              <input
                type="text"
                value={form.duration}
                onChange={(e) =>
                  setForm({ ...form, duration: e.target.value })
                }
                className="rounded border border-clinical-slate-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-clinical-indigo-200"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-clinical-slate-500 mb-1">
                Instructions
              </label>
              <textarea
                value={form.instructions}
                onChange={(e) =>
                  setForm({ ...form, instructions: e.target.value })
                }
                rows={2}
                className="rounded border border-clinical-slate-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-clinical-indigo-200"
                required
              />
            </div>
            <div className="sm:col-span-2">
              <button
                type="submit"
                className="w-full bg-clinical-indigo-600 text-white px-4 py-2 rounded hover:bg-clinical-indigo-700 transition-colors"
              >
                Add Prescription Item
              </button>
            </div>
          </form>
        </div>
      </div>

      {/* Patient EMR Tab - Historical Prescriptions and Lab Orders */}
      <div>
        <h2 className="font-semibold text-clinical-slate-900 mb-4">
          Patient EMR - Historical Records
        </h2>

        <div className="bg-white rounded-lg p-4 shadow-sm">
          {/* Prescriptions Table */}
          <div className="overflow-x-auto rounded bg-white p-4 shadow-sm">
            <table className="w-full min-w-full divide-y divide-clinical-slate-200">
              <thead className="bg-clinical-slate-50">
                <tr>
                  <th className="px-6 py-3 text-left text-xs font-medium text-clinical-slate-500 uppercase tracking-wider">
                    Medicine
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-clinical-slate-500 uppercase tracking-wider">
                    Dosage
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-clinical-slate-500 uppercase tracking-wider">
                    Frequency
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-clinical-slate-500 uppercase tracking-wider">
                    Duration
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-clinical-slate-500 uppercase tracking-wider">
                    Instructions
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
                {prescriptions.map((prescription) => (
                  <tr key={prescription.id} className="hover:bg-clinical-slate-50">
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div className="text-sm font-medium text-clinical-slate-900">
                        {prescription.items.map((item) => item.medicine_name).join(', ')}
                      </div>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div className="text-sm text-clinical-slate-500">{prescription.items.map((item) => item.dosage).join(', ')}</div>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div className="text-sm text-clinical-slate-500">
                        {prescription.items.map((item) => item.frequency).join(', ')}
                      </div>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div className="text-sm text-clinical-slate-500">
                        {prescription.items.map((item) => item.duration).join(', ')}
                      </div>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div className="text-sm text-clinical-slate-500">
                        {prescription.items.map((item) => item.instructions).join(' | ')}
                      </div>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <span className={`px-2 inline-flex text-xs rounded ${prescription.status === 'pending' ? 'bg-clinical-slate-100 text-clinical-slate-600' : prescription.status === 'in_progress' ? 'bg-clinical-indigo-100 text-clinical-indigo-600' : prescription.status === 'completed' ? 'bg-clinical-teal-100 text-clinical-teal-600' : 'bg-clinical-slate-200 text-clinical-slate-500'}`}>
                        {prescription.status}
                      </span>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                      <button
                        className="text-clinical-indigo-600 hover:underline mr-2"
                        title="View details"
                      >
                        Details
                      </button>
                      <button
                        className="text-clinical-teal-600 hover:underline"
                        title="Update status"
                      >
                        Update
                      </button>
                    </td>
                  </tr>
                ))}
                {prescriptions.length === 0 && (
                  <tr>
                    <td colSpan={7} className="px-6 py-4 text-center text-clinical-slate-400">
                      No prescriptions found
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>

          {/* Lab Orders Table */}
          <div className="mt-4 overflow-x-auto rounded bg-white p-4 shadow-sm">
            <table className="w-full min-w-full divide-y divide-clinical-slate-200">
              <thead className="bg-clinical-slate-50">
                <tr>
                  <th className="px-6 py-3 text-left text-xs font-medium text-clinical-slate-500 uppercase tracking-wider">
                    Test Name
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-clinical-slate-500 uppercase tracking-wider">
                    Status
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-clinical-slate-500 uppercase tracking-wider">
                    Result
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-clinical-slate-500 uppercase tracking-wider">
                    Recorded At
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-clinical-slate-500 uppercase tracking-wider">
                    Actions
                  </th>
                </tr>
              </thead>
              <tbody>
                {labOrders.map((labOrder) => (
                  <tr key={labOrder.id} className="hover:bg-clinical-slate-50">
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div className="text-sm font-medium text-clinical-slate-900">
                        {labOrder.test_name}
                      </div>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <span className={`px-2 inline-flex text-xs rounded ${labOrder.status === 'pending' ? 'bg-clinical-slate-100 text-clinical-slate-600' : labOrder.status === 'in_progress' ? 'bg-clinical-indigo-100 text-clinical-indigo-600' : labOrder.status === 'completed' ? 'bg-clinical-teal-100 text-clinical-teal-600' : 'bg-clinical-slate-200 text-clinical-slate-500'}`}>
                        {labOrder.status}
                      </span>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      {labOrder.result ? (
                        <div className="text-sm text-clinical-indigo-600">{labOrder.result}</div>
                      ) : (
                        <span className="text-sm text-clinical-slate-400">Pending</span>
                      )}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div className="text-sm text-clinical-slate-400">{labOrder.recorded_at}</div>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                      <button
                        className="text-clinical-indigo-600 hover:underline mr-2"
                        title="View details"
                      >
                        Details
                      </button>
                      {labOrder.status !== 'completed' && (
                        <button
                          className="text-clinical-teal-600 hover:underline"
                          title="Enter result"
                        >
                          Enter Result
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
                {labOrders.length === 0 && (
                  <tr>
                    <td colSpan={5} className="px-6 py-4 text-center text-clinical-slate-400">
                      No lab orders found
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </div>

        {/* Result Entry Modal for Lab Orders */}
        {editingLabOrder && (
          <div className="fixed inset-0 bg-black/50 backdrop-blur-zsm flex items-center justify-center z-50">
            <div className="bg-white rounded-lg p-6 w-full max-w-md shadow-2xl">
              <h3 className="font-medium text-clinical-slate-900 mb-4">
                Enter Lab Result - {editingLabOrder.test_name}
              </h3>
              <form
                onSubmit={(e) => {
                  e.preventDefault()
                  const resultInput = (e.target as HTMLFormElement).elements.namedItem(
                    'result'
                  ) as HTMLTextAreaElement
                  handleResultEntry(editingLabOrder.id, resultInput.value)
                  setEditingLabOrder(null)
                }}
              >
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                  <div>
                    <label className="block text-sm font-medium text-clinical-slate-500 mb-1">
                      Result Findings
                    </label>
                    <textarea
                      name="result"
                      value={editingLabOrder.result || ''}
onChange={() => {}}
                      rows={3}
                      className="rounded border border-clinical-slate-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-clinical-indigo-200"
                      required
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-clinical-slate-500 mb-1">
                      Status
                    </label>
                    <select
                      defaultValue={editingLabOrder.status}
                      className="rounded border border-clinical-slate-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-clinical-indigo-200"
                    >
                      <option value="pending">Pending</option>
                      <option value="in_progress">In Progress</option>
                      <option value="completed">Completed</option>
                      <option value="cancelled">Cancelled</option>
                    </select>
                  </div>
                </div>
                <div className="mt-4 flex gap-2">
                  <button
                    type="button"
                    onClick={() => setEditingLabOrder(null)}
                    className="flex-1 bg-clinical-slate-300 text-clinical-slate-700 px-4 py-2 rounded hover:bg-clinical-slate-400 transition-colors"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    className="flex-1 bg-clinical-teal-600 text-white px-4 py-2 rounded hover:bg-clinical-teal-700 transition-colors"
                  >
                    Save Result
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}

        {/* New Lab Order Form */}
        {newLabOrder.test_name && (
          <div className="fixed inset-0 bg-black/50 backdrop-blur-zsm flex items-center justify-center z-50">
            <div className="bg-white rounded-lg p-6 w-full max-w-md shadow-2xl">
              <h3 className="font-medium text-clinical-slate-900 mb-4">New Lab Order</h3>
              <form
                onSubmit={(e) => {
                  e.preventDefault()
                  const newOrder: LabOrder = {
                    id: Date.now(),
                    patient_id: patientId,
                    test_name: newLabOrder.test_name,
                    status: 'pending',
                    recorded_at: new Date().toISOString(),
                  }
                  setLabOrders([newOrder, ...labOrders])
                  onLabOrderUpdated?.(newOrder)
                  setNewLabOrder({ test_name: '' })
                }}
              >
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                  <div>
                    <label className="block text-sm font-medium text-clinical-slate-500 mb-1">
                      Test Name
                    </label>
                    <input
                      type="text"
                      value={newLabOrder.test_name}
                      onChange={(e) =>
                        setNewLabOrder({ test_name: e.target.value })
                      }
                      className="rounded border border-clinical-slate-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-clinical-indigo-200"
                      required
                    />
                  </div>
                </div>
                <div className="mt-4 flex gap-2">
                  <button
                    type="button"
                    onClick={() => setNewLabOrder({ test_name: '' })}
                    className="flex-1 bg-clinical-slate-300 text-clinical-slate-700 px-4 py-2 rounded hover:bg-clinical-slate-400 transition-colors"
                  >
                    Cancel
                  </button>
                  <button type="submit" className="flex-1 bg-clinical-teal-600 text-white px-4 py-2 rounded hover:bg-clinical-teal-700 transition-colors">
                    Add Lab Order
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}