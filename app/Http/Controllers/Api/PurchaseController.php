<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReceivePurchaseRequest;
use App\Http\Requests\StorePurchaseRequest;
use App\Http\Requests\UpdatePurchaseRequest;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Services\PurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PurchaseController extends Controller
{
    public function __construct(private readonly PurchaseService $purchases) {}

    public function index(Request $request): JsonResponse
    {

        $validated = $request->validate([
            'supplier_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', Rule::in(['draft', 'ordered', 'received', 'cancelled'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $query = Purchase::query()
            ->with(['supplier:id,name', 'creator:id,name', 'receiver:id,name'])
            ->orderByDesc('purchase_date');

        if ($supplierId = $validated['supplier_id'] ?? null) {
            $query->where('supplier_id', $supplierId);
        }

        if ($status = $validated['status'] ?? null) {
            $query->where('status', $status);
        }

        if ($from = $validated['date_from'] ?? null) {
            $query->whereDate('purchase_date', '>=', $from);
        }

        if ($to = $validated['date_to'] ?? null) {
            $query->whereDate('purchase_date', '<=', $to);
        }

        return response()->json([
            'success' => true,
            'data' => $query->get()->map(fn ($p) => $this->present($p)),
        ]);
    }

    public function store(StorePurchaseRequest $request): JsonResponse
    {

        $validated = $request->validated();
        $items = $validated['items'];
        unset($validated['items']);
        $validated['purchase_number'] = 'TMP-'.Str::uuid();
        $validated['created_by'] = $request->user()->id;

        $purchase = Purchase::create($validated);

        foreach ($items as $item) {
            $lineTotal = round($item['quantity'] * $item['unit_price'], 2);
            PurchaseItem::create([
                'purchase_id' => $purchase->id,
                ...$item,
                'line_total' => $lineTotal,
            ]);
        }

        $purchase->load('items');
        $this->recalculateTotals($purchase);
        $purchase->update(['purchase_number' => sprintf('PO-%06d', $purchase->id)]);

        return response()->json([
            'success' => true,
            'data' => $this->present($purchase->fresh()),
        ], 201);
    }

    public function show(Purchase $purchase): JsonResponse
    {

        return response()->json([
            'success' => true,
            'data' => $this->present($purchase->load(['items.inventoryItem', 'supplier:id,name', 'creator:id,name', 'receiver:id,name'])),
        ]);
    }

    public function update(UpdatePurchaseRequest $request, Purchase $purchase): JsonResponse
    {

        if ($purchase->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Only draft purchases can be updated.',
            ], 422);
        }

        $validated = $request->validated();
        $items = $validated['items'] ?? null;

        if ($items !== null) {
            $purchase->items()->delete();
            foreach ($items as $item) {
                $lineTotal = round($item['quantity'] * $item['unit_price'], 2);
                PurchaseItem::create([
                    'purchase_id' => $purchase->id,
                    ...$item,
                    'line_total' => $lineTotal,
                ]);
            }
            unset($validated['items']);
        }

        $purchase->update($validated);
        $this->recalculateTotals($purchase);

        return response()->json([
            'success' => true,
            'data' => $this->present($purchase->fresh()),
        ]);
    }

    public function receive(ReceivePurchaseRequest $request, Purchase $purchase): JsonResponse
    {

        if ($purchase->status === 'received') {
            return response()->json([
                'success' => false,
                'message' => 'Purchase already received.',
            ], 422);
        }

        if ($purchase->status === 'cancelled') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot receive a cancelled purchase.',
            ], 422);
        }

        $this->purchases->receivePurchase($purchase, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Purchase received and stock updated.',
            'data' => $this->present($purchase->fresh()->load('items.inventoryItem')),
        ]);
    }

    public function destroy(Purchase $purchase): JsonResponse
    {

        if ($purchase->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Only draft purchases can be deleted.',
            ], 422);
        }

        $purchase->delete();

        return response()->json(['success' => true, 'message' => 'Purchase deleted.']);
    }

    private function present(Purchase $purchase): array
    {
        return [
            'id' => $purchase->id,
            'purchase_number' => $purchase->purchase_number,
            'supplier' => $purchase->supplier ? ['id' => $purchase->supplier->id, 'name' => $purchase->supplier->name] : null,
            'purchase_date' => $purchase->purchase_date,
            'status' => $purchase->status,
            'subtotal' => $purchase->subtotal,
            'tax' => $purchase->tax,
            'total' => $purchase->total,
            'notes' => $purchase->notes,
            'created_by' => $purchase->creator ? ['id' => $purchase->creator->id, 'name' => $purchase->creator->name] : null,
            'received_by' => $purchase->receiver ? ['id' => $purchase->receiver->id, 'name' => $purchase->receiver->name] : null,
            'received_at' => $purchase->received_at,
            'items' => $purchase->items->map(fn ($item) => [
                'id' => $item->id,
                'inventory_item_id' => $item->inventory_item_id,
                'item_name' => $item->item_name,
                'item_sku' => $item->item_sku,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'tax_rate' => $item->tax_rate,
                'line_total' => $item->line_total,
                'batch_number' => $item->batch_number,
                'expiry_date' => $item->expiry_date,
                'notes' => $item->notes,
            ])->values(),
        ];
    }

    private function recalculateTotals(Purchase $purchase): void
    {
        $subtotal = $purchase->items->sum('line_total');
        $tax = $purchase->items->sum(fn ($i) => round($i->quantity * $i->unit_price * ($i->tax_rate ?? 0) / 100, 2));
        $purchase->update([
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => $subtotal + $tax,
        ]);
    }
}
