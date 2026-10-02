<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforce the two-factor challenge for sensitive clinical and administrative
 * endpoints. Admin and Doctor accounts that have completed 2FA setup must
 * verify a TOTP code during the current session before these routes respond.
 */
class TwoFactorChallenge
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

        if ($user !== null && $user->twoFactorRequired() && ! $request->session()->has('two_factor_verified_at')) {
            return response()->json([
                'success' => false,
                'message' => 'Two-factor authentication verification is required before continuing.',
                'two_factor_required' => true,
            ], 403);
        }

        return $next($request);
    }
}
