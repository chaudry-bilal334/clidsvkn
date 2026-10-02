<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TwoFactorAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly TwoFactorAuthService $twoFactorAuth) {}

    /**
     * Initialize the session and rotate tokens so the SPA obtains its
     * XSRF-TOKEN cookie before sending stateful (session-based) requests.
     */
    public function csrfCookie(Request $request): JsonResponse
    {
        $request->session()->regenerate();

        return response()->json([
            'message' => 'CSRF cookie initialized.',
        ]);
    }

    /**
     * Authenticate a session and return a session handle plus the user payload.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        /** @var User $user */
        $user = Auth::user();

        if ($user->twoFactorRequired()) {
            $request->session()->put('pending_two_factor_id', $user->id);

            return response()->json([
                'two_factor_required' => true,
            ]);
        }

        $request->session()->regenerate();

        return response()->json([
            'token' => $request->session()->getId(),
            'user' => $this->userPayload($user),
        ]);
    }

    /**
     * Complete a pending 2FA challenge and authenticate the session.
     */
    public function verifyTwoFactor(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string'],
        ]);

        $userId = $request->session()->get('pending_two_factor_id');

        if ($userId === null) {
            throw ValidationException::withMessages([
                'code' => ['No two-factor challenge is pending for this session.'],
            ]);
        }

        /** @var User|null $user */
        $user = User::find($userId);

        if ($user === null || ! $this->twoFactorAuth->verifyChallenge($user, (string) $request->input('code'))) {
            throw ValidationException::withMessages([
                'code' => ['The two-factor code is invalid.'],
            ]);
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('two_factor_verified_at', now()->timestamp);

        return response()->json([
            'token' => $request->session()->getId(),
            'user' => $this->userPayload($user),
        ]);
    }

    /**
     * Return the currently authenticated user (session restore on page reload).
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'user' => $this->userPayload($user),
        ]);
    }

    /**
     * Invalidate the session and its CSRF token.
     */
    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Signed out.',
        ]);
    }

    /**
     * Normalize a user for the SPA (role + permissions kept out of hidden attrs).
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'permissions' => $user->permissions,
            'branch_id' => $user->branch_id,
            'is_cross_branch' => (bool) $user->is_cross_branch,
            'two_factor_enabled' => $user->twoFactorEnabled(),
        ];
    }
}
