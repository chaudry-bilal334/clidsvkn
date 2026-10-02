<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApproveExpenseRequest;
use App\Http\Requests\StoreExpenseRequest;
use App\Http\Requests\UpdateExpenseRequest;
use App\Models\AuditLog;
use App\Models\Expense;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ExpenseController extends Controller
{
    public function index(Request $request): JsonResponse
    {

        $validated = $request->validate([
            'expense_category_id' => ['nullable', 'integer'],
            'payment_status' => ['nullable', 'string', Rule::in(['pending', 'paid', 'partial', 'rejected'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $query = Expense::query()
            ->with(['category:id,name', 'creator:id,name', 'approver:id,name'])
            ->orderByDesc('expense_date');

        if ($catId = $validated['expense_category_id'] ?? null) {
            $query->where('expense_category_id', $catId);
        }

        if ($status = $validated['payment_status'] ?? null) {
            $query->where('payment_status', $status);
        }

        if ($from = $validated['date_from'] ?? null) {
            $query->whereDate('expense_date', '>=', $from);
        }

        if ($to = $validated['date_to'] ?? null) {
            $query->whereDate('expense_date', '<=', $to);
        }

        return response()->json([
            'success' => true,
            'data' => $query->get()->map(fn ($e) => $this->present($e)),
        ]);
    }

    public function store(StoreExpenseRequest $request): JsonResponse
    {

        $validated = $request->validated();
        $validated['created_by'] = $request->user()->id;
        $validated['total'] = round(($validated['amount'] ?? 0) + ($validated['tax'] ?? 0), 2);
        $validated['expense_number'] = 'TMP-'.Str::uuid();

        $expense = Expense::create($validated);
        $expense->update(['expense_number' => sprintf('EXP-%06d', $expense->id)]);

        return response()->json([
            'success' => true,
            'data' => $this->present($expense->fresh()),
        ], 201);
    }

    public function show(Expense $expense): JsonResponse
    {

        return response()->json([
            'success' => true,
            'data' => $this->present($expense->load(['category', 'creator', 'approver'])),
        ]);
    }

    public function update(UpdateExpenseRequest $request, Expense $expense): JsonResponse
    {

        if ($expense->payment_status === 'paid') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot update a fully paid expense.',
            ], 422);
        }

        $validated = $request->validated();
        if (isset($validated['amount']) || isset($validated['tax'])) {
            $amount = $validated['amount'] ?? $expense->amount;
            $tax = $validated['tax'] ?? $expense->tax;
            $validated['total'] = round($amount + $tax, 2);
        }

        $expense->update($validated);

        return response()->json([
            'success' => true,
            'data' => $this->present($expense->fresh()),
        ]);
    }

    public function approve(ApproveExpenseRequest $request, Expense $expense): JsonResponse
    {

        if ($expense->payment_status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending expenses can be approved.',
            ], 422);
        }

        $expense->update([
            'payment_status' => 'paid',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Expense approved.',
            'data' => $this->present($expense->fresh()),
        ]);
    }

    public function reject(Request $request, Expense $expense): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        if ($expense->payment_status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending expenses can be rejected.',
            ], 422);
        }

        $expense->update([
            'payment_status' => 'rejected',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'notes' => $expense->notes."\nRejected: ".$validated['reason'],
        ]);

        AuditLog::record(
            $request->user(),
            'expense.rejected',
            $expense,
            newValues: [
                'expense_number' => $expense->expense_number,
                'payment_status' => 'rejected',
                'reason' => $validated['reason'],
            ],
        );

        return response()->json([
            'success' => true,
            'message' => 'Expense rejected.',
            'data' => $this->present($expense->fresh()),
        ]);
    }

    public function destroy(Expense $expense): JsonResponse
    {

        if ($expense->payment_status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending expenses can be deleted.',
            ], 422);
        }

        $expense->delete();

        return response()->json(['success' => true, 'message' => 'Expense deleted.']);
    }

    private function present(Expense $expense): array
    {
        return [
            'id' => $expense->id,
            'expense_number' => $expense->expense_number,
            'category' => $expense->category ? ['id' => $expense->category->id, 'name' => $expense->category->name] : null,
            'expense_date' => $expense->expense_date,
            'vendor' => $expense->vendor,
            'amount' => $expense->amount,
            'tax' => $expense->tax,
            'total' => $expense->total,
            'payment_status' => $expense->payment_status,
            'payment_method' => $expense->payment_method,
            'description' => $expense->description,
            'notes' => $expense->notes,
            'created_by' => $expense->creator ? ['id' => $expense->creator->id, 'name' => $expense->creator->name] : null,
            'approved_by' => $expense->approver ? ['id' => $expense->approver->id, 'name' => $expense->approver->name] : null,
            'approved_at' => $expense->approved_at,
        ];
    }
}
