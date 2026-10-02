<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Require a fresh re-authentication before a sensitive state transition.
 *
 * Complements the `2fa` middleware: `2fa` gates Doctor/Admin accounts that
 * have completed 2FA setup, while this guard applies to every authenticated
 * user performing a finalize / void / amend / refund action. A successful
 * re-auth (password or TOTP) writes `reauth_verified_at` into the session
 * (see ReauthController), which is valid for `auth.reauth_timeout` seconds.
 * A login-time 2FA verification (`two_factor_verified_at`) is accepted as an
 * equivalent marker for accounts that completed the 2FA login challenge.
 */
class RequireReauth
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user !== null && ! $this->isFreshAuthentication($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Re-authentication is required before this action. Re-enter your password or a two-factor code to continue.',
                'reauth_required' => true,
            ], 403);
        }

        return $next($request);
    }

    private function isFreshAuthentication(Request $request): bool
    {
        $timeout = (int) config('auth.reauth_timeout', 300);
        $now = now()->timestamp;

        $reauthAt = (int) $request->session()->get('reauth_verified_at', 0);
        $twoFactorAt = (int) $request->session()->get('two_factor_verified_at', 0);

        $latest = max($reauthAt, $twoFactorAt);

        return $latest > 0 && ($now - $latest) <= $timeout;
    }
}
