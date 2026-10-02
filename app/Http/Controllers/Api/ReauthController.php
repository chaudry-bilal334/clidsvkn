<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TwoFactorAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Re-authentication for sensitive state transitions (finalize / void /
 * amend / refund). A confirmed re-auth stamps `reauth_verified_at` into the
 * session, which the `RequireReauth` middleware accepts as fresh for
 * `auth.reauth_timeout` seconds. This mirrors the `2fa` middleware pattern
 * (session marker + 403 prompt) already used elsewhere in the API.
 */
class ReauthController extends Controller
{
    public function __construct(private readonly TwoFactorAuthService $twoFactorAuth) {}

    /**
     * Report which re-auth methods apply to the signed-in user.
     */
    public function status(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'available' => true,
                'two_factor' => $user->twoFactorEnabled(),
                'password' => true,
            ],
        ]);
    }

    /**
     * Confirm the current password (or a TOTP code) and stamp the session.
     */
    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required_without:two_factor_code', 'string'],
            'two_factor_code' => ['required_without:password', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        if (filled($data['two_factor_code'] ?? null)) {
            if (! $user->twoFactorEnabled() || ! $this->twoFactorAuth->verifyChallenge($user, (string) $data['two_factor_code'])) {
                throw ValidationException::withMessages([
                    'two_factor_code' => ['The two-factor code is invalid.'],
                ]);
            }
        } elseif (! Hash::check((string) $data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['The password is incorrect.'],
            ]);
        }

        $request->session()->put('reauth_verified_at', now()->timestamp);

        return response()->json([
            'success' => true,
            'data' => [
                'reauth_verified_at' => now()->timestamp,
            ],
        ]);
    }
}
