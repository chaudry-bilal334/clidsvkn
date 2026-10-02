<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TwoFactorAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TwoFactorAuthController extends Controller
{
    public function __construct(private readonly TwoFactorAuthService $twoFactorAuth) {}

    /**
     * Stage a fresh TOTP secret and return the provisioning QR payload.
     */
    public function enable(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->twoFactorEnabled()) {
            return response()->json([
                'success' => false,
                'message' => 'Two-factor authentication is already enabled for this account.',
            ], 422);
        }

        $secret = $this->twoFactorAuth->generateSecret();
        $user->two_factor_secret = $secret;
        $user->two_factor_confirmed_at = null;
        $user->save();

        return response()->json([
            'success' => true,
            'secret' => $secret,
            'qr_uri' => $this->twoFactorAuth->provisioningUri($user, $secret),
        ]);
    }

    /**
     * Confirm the staged secret with a live TOTP code; returns recovery codes.
     */
    public function confirm(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        /** @var string|null $secret */
        $secret = $user->two_factor_secret;

        if ($secret === null) {
            return response()->json([
                'success' => false,
                'message' => 'Enable two-factor authentication before confirming a code.',
            ], 422);
        }

        $codes = $this->twoFactorAuth->confirmSetup($user, $secret, (string) $request->input('code'));

        $request->session()->put('two_factor_verified_at', now()->timestamp);

        return response()->json([
            'success' => true,
            'recovery_codes' => $codes,
        ]);
    }

    /**
     * Disable 2FA after confirming the account password.
     */
    public function disable(Request $request): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'string'],
        ]);

        $this->twoFactorAuth->disable($request->user(), (string) $request->input('password'));

        $request->session()->forget('two_factor_verified_at');

        return response()->json([
            'success' => true,
            'message' => 'Two-factor authentication disabled.',
        ]);
    }
}
