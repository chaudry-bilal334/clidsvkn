<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use App\Models\User;
use App\Services\BranchContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validate and guard the active branch for the request.
 *
 * The active branch is derived lazily from the request by BranchContext (see
 * App\Services\BranchContext), so it is available to global Eloquent scopes
 * and route model binding no matter which pipeline runs first. This middleware
 * performs the authoritative validation: a non-numeric or unknown X-Branch-ID
 * header is rejected, and selecting a branch you are not assigned to is
 * rejected with 403 before the request proceeds.
 *
 * A valid X-Branch-ID header overrides the user's assigned branch. Super admins
 * and authorized cross-branch users see all branches by default (bypass), and
 * opt into a branch by sending the header.
 *
 * It replaces SubstituteBindings in the "web" group so implicit model binding
 * runs through it after the guards have passed.
 */
class ResolveBranch extends SubstituteBindings
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle($request, Closure $next)
    {
        BranchContext::reset();

        /** @var User|null $user */
        $user = $request->user();

        if ($user !== null) {
            $response = $this->resolveFromRequest($request, $user);

            if ($response !== null) {
                return $response;
            }
        }

        return parent::handle($request, $next);
    }

    private function resolveFromRequest(Request $request, User $user): ?Response
    {
        $requested = $request->header('X-Branch-ID');

        if ($requested !== null && $requested !== '') {
            return $this->resolveFromHeader($user, $requested);
        }

        if ($this->isGlobalViewer($user)) {
            BranchContext::bypass(true);
        } elseif ($user->branch_id !== null) {
            BranchContext::set((int) $user->branch_id);
        }

        return null;
    }

    private function resolveFromHeader(User $user, string $requested): ?Response
    {
        BranchContext::bypass(false);

        if (! ctype_digit($requested)) {
            return $this->errorResponse('The X-Branch-ID header must be a branch id.', 422);
        }

        $branchId = (int) $requested;
        $branch = Branch::query()->find($branchId);

        if ($branch === null || ! $branch->is_active) {
            return $this->errorResponse('The selected branch does not exist or is inactive.', 422);
        }

        if (! $this->mayAccessBranch($user, $branchId)) {
            return $this->errorResponse('You are not assigned to this branch.', 403);
        }

        BranchContext::set($branchId);

        return null;
    }

    private function mayAccessBranch(User $user, int $branchId): bool
    {
        if ($this->isGlobalViewer($user)) {
            return true;
        }

        return (int) $user->branch_id === $branchId;
    }

    private function isGlobalViewer(User $user): bool
    {
        return $user->role === 'Admin' || (bool) $user->is_cross_branch;
    }

    private function errorResponse(string $message, int $status): Response
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
