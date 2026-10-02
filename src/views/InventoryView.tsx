import { useState, useEffect } from 'react'
import api from '../services/api'
import { useAuth } from '../context/AuthContext'

interface InventoryItem {
  id: number
  sku: string
  category: 'medicine' | 'equipment' | 'supply'
  unit_price: number
  stock_quantity: number
  reorder_level: number
  last_restocked: string
}

interface InventoryTransaction {
  id: number
  item_id: number
  type: 'purchase' | 'dispense' | 'adjustment' | 'return'
  quantity: number
  unit_price: number
  total_amount: number
  reason?: string
  created_at: string
}

interface InventoryViewProps {
  onTransactionRecorded?: (transaction: InventoryTransaction) => void
}

export const InventoryView = ({
  onTransactionRecorded
}: InventoryViewProps) => {
  const [items, setItems] = useState<InventoryItem[]>([])
  const [transactions, setTransactions] = useState<InventoryTransaction[]>([])
  const [loading, setLoading] = useState(true)
  const [form, setForm] = useState<{
    type: 'purchase' | 'dispense' | 'adjustment' | 'return'
    quantity: number
    unit_price: number
    reason?: string
  }>({
    type: 'purchase',
    quantity: 0,
    unit_price: 0,
    reason: undefined,
  })
  const { user } = useAuth()

  useEffect(() => {
    const loadData = async () => {
      setLoading(true)
      try {
        const [itemsResp, transactionsResp] = await Promise.all([
          api.get('/inventory'),
          api.get('/inventory/transactions')
        ])
        setItems(itemsResp.data.data || [])
        setTransactions(transactionsResp.data.data || [])
      } catch (error) {
        console.error('Failed to load inventory data:', error)
      } finally {
        setLoading(false)
      }
    }
    loadData()
  }, [])

  const computeStockDelta = (type: InventoryTransaction['type'], quantity: number) => {
    if (type === 'purchase' || type === 'return') return quantity
    if (type === 'dispense' || type === 'adjustment') return -quantity
    return 0
  }

  const validateStock = (
    item: InventoryItem,
    type: InventoryTransaction['type'],
    quantity: number
  ): { valid: boolean; newStock: number } => {
    const delta = computeStockDelta(type, quantity)
    const newStock = item.stock_quantity + delta
    return { valid: newStock >= 0, newStock }
  }

  const handleRecordTransaction = async (itemId: number) => {
    const item = items.find((i) => i.id === itemId)
    if (!item) return

    const { valid, newStock } = validateStock(item, form.type, form.quantity)
    if (!valid) {
      alert('Insufficient stock for this transaction type')
      return
    }

    const total_amount = form.type === 'adjustment' ? form.quantity * form.unit_price : form.quantity * (item.unit_price || 0)

    const transaction: InventoryTransaction = {
      id: Date.now(),
      item_id: itemId,
      type: form.type,
      quantity: form.quantity,
      unit_price: form.unit_price,
      total_amount,
      reason: form.reason,
      created_at: new Date().toISOString()
    }

    try {
      const response = await api.post('/inventory/transactions', transaction)
      setTransactions([response.data.data || transaction, ...transactions])

      const updatedItem: InventoryItem = {
        ...item,
        stock_quantity: newStock,
      }
      setItems(items.map((i) => (i.id === itemId ? updatedItem : i)))

      onTransactionRecorded?.(transaction)
      setForm({ type: 'purchase', quantity: 0, unit_price: 0, reason: undefined })
    } catch (error) {
      console.error('Failed to record transaction:', error)
    }
  }

  return (
    <div className="space-y-4">
      <div>
        <h2 className="font-semibold text-clinical-slate-900 mb-4">Pharmacy & Inventory</h2>

        <div className="bg-white rounded-lg p-4 shadow-sm">
          <div className="overflow-x-auto rounded bg-white p-4 shadow-sm">
            <table className="w-full min-w-full divide-y divide-clinical-slate-200">
              <thead className="bg-clinical-slate-50">
                <tr>
                  <th className="px-6 py-3 text-left text-xs font-medium text-clinical-slate-500 uppercase tracking-wider">
                    SKU
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-clinical-slate-500 uppercase tracking-wider">
                    Category
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-clinical-slate-500 uppercase tracking-wider">
                    Unit Price
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-clinical-slate-500 uppercase tracking-wider">
                    Stock Qty
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-clinical-slate-500 uppercase tracking-wider">
                    Reorder Level
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
                {items.map((item) => {
                  const isLowStock = item.stock_quantity <= item.reorder_level
                  const statusClass = isLowStock
                    ? 'bg-clinical-slate-100 text-clinical-slate-600'
                    : 'bg-clinical-teal-100 text-clinical-teal-600'
                  return (
                    <tr key={item.id} className="hover:bg-clinical-slate-50">
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="text-sm font-medium text-clinical-slate-900">{item.sku}</div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className={`px-2 py-1 rounded text-xs ${item.category === 'medicine' ? 'bg-clinical-indigo-100 text-clinical-indigo-600' : item.category === 'equipment' ? 'bg-clinical-teal-100 text-clinical-teal-600' : 'bg-clinical-slate-100 text-clinical-slate-600'}`}>
                          {item.category}
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="text-right text-sm font-medium text-clinical-indigo-600">${item.unit_price}</div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="text-right text-sm font-medium text-clinical-slate-900">{item.stock_quantity}</div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="text-right text-sm text-clinical-slate-500">{item.reorder_level}</div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <span className={`px-2 inline-flex text-xs rounded ${statusClass}`}>
                          {isLowStock ? 'LOW STOCK' : 'OK'}
                        </span>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <button
                          className="text-clinical-indigo-600 hover:underline mr-2"
                          title="View details"
                        >
                          Details
                        </button>
                        {isLowStock && (
                          <button
                            className="text-clinical-slate-400 hover:underline"
                            title="Restock"
                          >
                            Restock
                          </button>
                        )}
                      </td>
                    </tr>
                  )
                })}
                {items.length === 0 && (
                  <tr>
                    <td colSpan={7} className="px-6 py-4 text-center text-clinical-slate-400">
                      No inventory items found
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </div>
      </div>

      {/* Transaction Modal */}
      <div>
        <h3 className="font-semibold text-clinical-slate-900 mb-4">Record Inventory Transaction</h3>

        <div className="bg-white rounded-lg p-4 shadow-sm">
          <form
            onSubmit={(e) => {
              e.preventDefault()
              // Find the item being transacted (first low-stock item for demo)
              const lowStockItem = items.find(
                (i) => i.stock_quantity <= i.reorder_level
              )
              const targetItemId = lowStockItem?.id || items[0]?.id || 0
              handleRecordTransaction(targetItemId)
            }}
          >
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
              <div>
                <label className="block text-sm font-medium text-clinical-slate-500 mb-1">
                  Transaction Type
                </label>
                <select
                  value={form.type}
                  onChange={(e) => setForm({ ...form, type: e.target.value as any })}
                  className="rounded border border-clinical-slate-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-clinical-indigo-200"
                >
                  <option value="purchase">Purchase</option>
                  <option value="dispense">Dispense</option>
                  <option value="adjustment">Adjustment</option>
                  <option value="return">Return</option>
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium text-clinical-slate-500 mb-1">
                  Quantity
                </label>
                <input
                  type="number"
                  value={form.quantity}
                  onChange={(e) =>
                    setForm({ ...form, quantity: parseFloat(e.target.value) || 0 })
                  }
                  className="rounded border border-clinical-slate-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-clinical-indigo-200"
                  min={0}
                  required
                />
              </div>
            </div>

            {form.type !== 'adjustment' ? (
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                <div>
                  <label className="block text-sm font-medium text-clinical-slate-500 mb-1">
                    Unit Price
                  </label>
                  <input
                    type="number"
                    value={form.unit_price}
                    onChange={(e) =>
                      setForm({ ...form, unit_price: parseFloat(e.target.value) || 0 })
                    }
                    className="rounded border border-clinical-slate-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-clinical-indigo-200"
                    min={0}
                    required
                  />
                </div>
              </div>
            ) : (
              <input
                type="hidden"
                value={form.unit_price}
                name="unit_price"
                defaultValue={form.unit_price.toString()}
                className="hidden"
              />
            )}

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
              <div>
                <label className="block text-sm font-medium text-clinical-slate-500 mb-1">
                  Reason (optional)
                </label>
                <input
                  type="text"
                  value={form.reason || ''}
                  onChange={(e) =>
                    setForm({ ...form, reason: e.target.value })
                  }
                  className="rounded border border-clinical-slate-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-clinical-indigo-200"
                />
              </div>
            </div>

            <div className="mt-4 flex gap-2">
              <button
                type="button"
                onClick={() => setForm({ type: 'purchase', quantity: 0, unit_price: 0, reason: undefined })}
                className="flex-1 bg-clinical-slate-300 text-clinical-slate-700 px-4 py-2 rounded hover:bg-clinical-slate-400 transition-colors"
              >
                Cancel
              </button>
              <button
                type="submit"
                className="flex-1 bg-clinical-teal-600 text-white px-4 py-2 rounded hover:bg-clinical-teal-700 transition-colors"
              >
                Record Transaction
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  )
}