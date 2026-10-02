import { useState, useEffect } from 'react'
import api from '../services/api'

interface Invoice {
  id: number
  patient_name: string
  patient_id: number
  subtotal: number
  tax: number
  total: number
  balance_due: number
  status: 'draft' | 'generated' | 'paid' | 'overdue'
  created_at: string
  payment_date?: string
}

interface BillingViewProps {
  onInvoiceGenerated?: (invoice: Invoice) => void
}

export const BillingView = ({ onInvoiceGenerated }: BillingViewProps) => {
  const [invoices, setInvoices] = useState<Invoice[]>([])
  const [loading, setLoading] = useState(true)
  const [form, setForm] = useState({
    patient_name: '',
    subtotal: 0,
    tax: 0,
    total: 0,
    balance_due: 0,
  })

  useEffect(() => {
    const loadInvoices = async () => {
      setLoading(true)
      try {
        const response = await api.get('/invoices')
        setInvoices(response.data.data || [])
      } catch (error) {
        console.error('Failed to load invoices:', error)
      } finally {
        setLoading(false)
      }
    }
    loadInvoices()
  }, [])

  const calculateTax = (subtotal: number) => {
    return Math.round(subtotal * 0.13)
  }

  const onSubtotalChange = (value: number) => {
    const tax = calculateTax(value)
    setForm({ ...form, subtotal: value, tax, total: value + tax, balance_due: value + tax })
  }

  const handleGenerateInvoice = async () => {
    try {
      const response = await api.post('/invoices', {
        patient_name: form.patient_name,
        subtotal: form.subtotal,
        tax: form.tax,
        total: form.total,
        balance_due: form.balance_due,
        status: 'generated',
      })
      setInvoices([response.data.data || {}, ...invoices])
      setForm({ patient_name: '', subtotal: 0, tax: 0, total: 0, balance_due: 0 })
      onInvoiceGenerated?.(response.data.data || {})
    } catch (error) {
      console.error('Failed to generate invoice:', error)
    }
  }

  const handleRecordPayment = async (invoiceId: number, amount: number) => {
    try {
      await api.post(`/invoices/${invoiceId}/payments`, { amount })
      setInvoices((prev) =>
        prev.map((inv) =>
          inv.id === invoiceId ? { ...inv, balance_due: inv.balance_due - amount, status: 'paid' } : inv
        )
      )
    } catch (error) {
      console.error('Failed to record payment:', error)
    }
  }

  return (
    <div className="space-y-4">
      <div>
        <h2 className="font-semibold text-clinical-slate-900 mb-4">Billing & Invoices</h2>

        <div className="flex flex-col sm:flex-row gap-2 mb-4">
          <input
            type="text"
            placeholder="Patient name or ID"
            value={form.patient_name}
            onChange={(e) => setForm({ ...form, patient_name: e.target.value })}
            className="flex-1 rounded border border-clinical-slate-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-clinical-indigo-200"
          />
          <button
            onClick={() => setForm({ patient_name: '', subtotal: 0, tax: 0, total: 0, balance_due: 0 })}
            className="hidden sm:inline-block bg-clinical-teal-600 text-white px-4 py-2 rounded hover:bg-clinical-teal-700 transition-colors"
          >
            Clear
          </button>
          <button
            onClick={handleGenerateInvoice}
            className="bg-clinical-indigo-600 text-white px-4 py-2 rounded hover:bg-clinical-indigo-700 transition-colors"
          >
            Generate Invoice
          </button>
        </div>

        {loading ? (
          <div className="animate-pulse rounded bg-clinical-slate-800 p-4">
            Loading invoices...
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
                    Subtotal
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-clinical-slate-500 uppercase tracking-wider">
                    Tax (13%)
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-clinical-slate-500 uppercase tracking-wider">
                    Total
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-clinical-slate-500 uppercase tracking-wider">
                    Balance Due
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
                {invoices.map((invoice) => (
                  <tr key={invoice.id} className="hover:bg-clinical-slate-50">
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div className="text-sm font-medium text-clinical-slate-900">{invoice.patient_name}</div>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div className="text-right text-sm font-medium text-clinical-indigo-600">
                        ${invoice.subtotal}
                      </div>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div className="text-right text-sm text-clinical-slate-500">
                        {invoice.tax}
                      </div>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div className="text-right text-sm font-medium text-clinical-slate-900">
                        ${invoice.total}
                      </div>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div className="text-right">
                        {invoice.balance_due > 0 ? (
                          <span className="text-sm text-clinical-slate-500">
                            ${invoice.balance_due}
                          </span>
                        ) : (
                          <span className="text-sm text-clinical-teal-600">Paid</span>
                        )}
                      </div>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <span className={`px-2 inline-flex text-xs rounded ${invoice.status === 'draft' ? 'bg-clinical-slate-200' : invoice.status === 'generated' ? 'bg-clinical-indigo-100' : invoice.status === 'paid' ? 'bg-clinical-teal-100' : 'bg-clinical-slate-200'} text-xs font-medium`}>
                        {invoice.status}
                      </span>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                      <button
                        onClick={() => handleRecordPayment(invoice.id, invoice.balance_due)}
                        className="text-clinical-teal-600 hover:underline mr-2"
                        title="Record payment"
                      >
                        Record Payment
                      </button>
                      <button className="text-clinical-indigo-600 hover:underline" title="View details">
                        Details
                      </button>
                    </td>
                  </tr>
                ))}
                {invoices.length === 0 && (
                  <tr>
                    <td colSpan={7} className="px-6 py-4 text-center text-clinical-slate-400">
                      No invoices found
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        )}

        {/* New Invoice Form Modal */}
        {form.patient_name && (
          <div className="fixed inset-0 bg-black/50 backdrop-blur-zsm flex items-center justify-center z-50">
            <div className="bg-white rounded-lg p-6 w-full max-w-md shadow-2xl">
              <h3 className="font-medium text-clinical-slate-900 mb-4">Generate New Invoice</h3>
              <form>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                  <div>
                    <label className="block text-sm font-medium text-clinical-slate-500 mb-1">Patient Name</label>
                    <input
                      type="text"
                      value={form.patient_name}
                      onChange={(e) => setForm({ ...form, patient_name: e.target.value })}
                      className="rounded border border-clinical-slate-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-clinical-indigo-200"
                      required
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-clinical-slate-500 mb-1">Subtotal</label>
                    <input
                      type="number"
                      value={form.subtotal}
                      onChange={(e) => onSubtotalChange(parseFloat(e.target.value) || 0)}
                      className="rounded border border-clinical-slate-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-clinical-indigo-200"
                    />
                  </div>
                </div>
                <div className="mt-2 flex justify-between">
                  <span className="text-sm text-clinical-slate-500">Tax (13%):</span>
                  <span className="font-medium text-clinical-indigo-600">
                    {form.tax}
                  </span>
                </div>
                <div className="mt-2 flex justify-between">
                  <span className="text-sm text-clinical-slate-500">Total:</span>
                  <span className="font-medium text-clinical-slate-900">
                    {form.total}
                  </span>
                </div>
                <div className="mt-4 flex gap-2">
                  <button
                    type="button"
                    onClick={() => setForm({ patient_name: '', subtotal: 0, tax: 0, total: 0, balance_due: 0 })}
                    className="flex-1 bg-clinical-slate-300 text-clinical-slate-700 px-4 py-2 rounded hover:bg-clinical-slate-400 transition-colors"
                  >
                    Cancel
                  </button>
                  <button
                    type="button"
                    onClick={handleGenerateInvoice}
                    className="flex-1 bg-clinical-indigo-600 text-white px-4 py-2 rounded hover:bg-clinical-indigo-700 transition-colors"
                  >
                    Generate
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