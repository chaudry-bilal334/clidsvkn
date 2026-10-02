<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use App\Services\BranchContext;
use App\Services\QueryCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BranchController extends Controller
{
    public function __construct(private readonly QueryCacheService $queryCache) {}

    /**
     * Branches available to the current user for the header switcher.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $branchKey = ($user->role === 'Admin' || $user->is_cross_branch)
            ? 'all'
            : 'branch.'.($user->branch_id ?? 'none');

        $branches = $this->queryCache->remember(
            $branchKey,
            function () use ($user): array {
                $rows = ($user->role === 'Admin' || $user->is_cross_branch)
                    ? Branch::query()->orderBy('name')->get()
                    : collect([$user->branch_id ? Branch::find($user->branch_id) : null])
                        ->filter()
                        ->values();

                return ['branches' => $rows->map(fn (Branch $branch): array => $branch->toArray())->all()];
            },
            600,
        );

        return response()->json([
            'success' => true,
            'data' => $branches['branches'],
            'current_branch_id' => BranchContext::id(),
        ]);
    }

    /**
     * Staff assigned to a branch (Admin / cross-branch only).
     */
    public function staff(Request $request, Branch $branch): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->role !== 'Admin' && ! $user->is_cross_branch) {
            return response()->json([
                'success' => false,
                'message' => 'You are not allowed to view branch staff.',
            ], 403);
        }

        $staff = User::query()
            ->where('branch_id', $branch->id)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role']);

        return response()->json([
            'success' => true,
            'data' => $staff,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $branch = Branch::create($request->validate([
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:20', Rule::unique('branches', 'code')],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:40'],
            'is_active' => ['nullable', 'boolean'],
        ]));

        return response()->json(['success' => true, 'data' => $branch], 201);
    }

    public function update(Request $request, Branch $branch): JsonResponse
    {
        $branch->update($request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'code' => ['sometimes', 'string', 'max:20', Rule::unique('branches', 'code')->ignore($branch->id)],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:40'],
            'is_active' => ['nullable', 'boolean'],
        ]));

        return response()->json(['success' => true, 'data' => $branch]);
    }

    public function destroy(Branch $branch): JsonResponse
    {
        if ($branch->users()->exists() || $branch->patients()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete a branch that still has staff or patients assigned.',
            ], 422);
        }

        $branch->delete();

        return response()->json(['success' => true, 'message' => 'Branch deleted.']);
    }
}
