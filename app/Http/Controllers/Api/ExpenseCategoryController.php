<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreExpenseCategoryRequest;
use App\Http\Requests\UpdateExpenseCategoryRequest;
use App\Models\ExpenseCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {

        $categories = ExpenseCategory::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $categories,
        ]);
    }

    public function store(StoreExpenseCategoryRequest $request): JsonResponse
    {

        $category = ExpenseCategory::create($request->validated());

        return response()->json([
            'success' => true,
            'data' => $category,
        ], 201);
    }

    public function update(UpdateExpenseCategoryRequest $request, ExpenseCategory $expenseCategory): JsonResponse
    {

        $expenseCategory->update($request->validated());

        return response()->json([
            'success' => true,
            'data' => $expenseCategory,
        ]);
    }

    public function destroy(ExpenseCategory $expenseCategory): JsonResponse
    {

        if ($expenseCategory->expenses()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete category with existing expenses.',
            ], 422);
        }

        $expenseCategory->delete();

        return response()->json(['success' => true, 'message' => 'Expense category deleted.']);
    }
}
