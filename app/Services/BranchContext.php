<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Holds the active branch for the current request.
 *
 * The active branch is derived lazily from the current request at query time:
 * a valid X-Branch-ID header wins, otherwise a cross-branch viewer bypasses the
 * scope, otherwise the authenticated user's assigned branch is used. This makes
 * the value available to global Eloquent scopes regardless of when a query is
 * built (including route model binding).
 *
 * Guards can override the derived value with set()/bypass().
 */
class BranchContext
{
    private static ?int $explicitId = null;

    private static bool $explicitBypass = false;

    private static ?int $memoRequestId = null;

    private static ?int $memoId = null;

    private static bool $memoBypassed = false;

    private static bool $memoized = false;

    /**
     * Clear the request-scoped state (called at the start of each request and
     * between requests in long-running processes).
     */
    public static function reset(): void
    {
        static::$explicitId = null;
        static::$explicitBypass = false;
        static::$memoized = false;
    }

    public static function set(?int $branchId): void
    {
        static::$explicitId = $branchId;
        static::$memoized = false;
    }

    public static function bypass(bool $bypass = true): void
    {
        static::$explicitBypass = $bypass;
        static::$memoized = false;
    }

    public static function id(): ?int
    {
        return static::current()['id'];
    }

    public static function bypassed(): bool
    {
        return static::current()['bypassed'];
    }

    /**
     * Resolve the branch state for the current request.
     *
     * @return array{id: int|null, bypassed: bool}
     */
    private static function current(): array
    {
        if (static::$explicitId !== null || static::$explicitBypass) {
            return ['id' => static::$explicitId, 'bypassed' => static::$explicitBypass];
        }

        if ($request = static::currentRequest()) {
            $signature = spl_object_id($request);

            if (static::$memoized && static::$memoRequestId === $signature) {
                return ['id' => static::$memoId, 'bypassed' => static::$memoBypassed];
            }

            return static::resolveFromRequest($request, $signature);
        }

        return ['id' => null, 'bypassed' => false];
    }

    /**
     * @return array{id: int|null, bypassed: bool}
     */
    private static function resolveFromRequest(Request $request, int $signature): array
    {
        $id = null;
        $bypassed = false;

        /** @var User|null $user */
        $user = $request->user();

        if ($user !== null) {
            $requested = trim((string) $request->header('X-Branch-ID', ''));

            if ($requested !== '') {
                if (ctype_digit($requested)) {
                    $id = (int) $requested;
                }
            } elseif ($user->role === 'Admin' || $user->is_cross_branch) {
                $bypassed = true;
            } elseif ($user->branch_id !== null) {
                $id = (int) $user->branch_id;
            }
        }

        static::$memoRequestId = $signature;
        static::$memoId = $id;
        static::$memoBypassed = $bypassed;
        static::$memoized = true;

        return ['id' => $id, 'bypassed' => $bypassed];
    }

    private static function currentRequest(): ?Request
    {
        $app = app();

        return $app->bound('request') && $app->make('request') instanceof Request
            ? $app->make('request')
            : null;
    }
}
