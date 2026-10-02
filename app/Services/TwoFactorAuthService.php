<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorAuthService
{
    public function __construct(private readonly Google2FA $google2fa) {}

    /**
     * A fresh TOTP secret for the account.
     */
    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    /**
     * Authenticator provisioning URI used to render the QR code.
     */
    public function provisioningUri(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl(
            config('app.name', 'ClinicSuite'),
            $user->email,
            $secret,
        );
    }

    /**
     * Verify a TOTP code for the given secret within a 4-step window.
     */
    public function verifyCode(string $secret, string $code): bool
    {
        if ($code === '') {
            return false;
        }

        try {
            return (bool) $this->google2fa->verify($code, $secret, 4);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Verify a challenge code against the user's TOTP secret or recovery codes.
     */
    public function verifyChallenge(User $user, string $code): bool
    {
        if ($this->verifyCode($user->two_factor_secret ?? '', $code)) {
            return true;
        }

        return $this->consumeRecoveryCode($user, $code);
    }

    /**
     * Generate and persist recovery codes, returning the plain-text copies.
     *
     * @return array<int, string>
     */
    public function issueRecoveryCodes(User $user, int $count = 8): array
    {
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $codes[] = implode('-', [
                $this->randomChunk(),
                $this->randomChunk(),
                $this->randomChunk(),
                $this->randomChunk(),
            ]);
        }

        $user->two_factor_recovery_codes = array_map(
            fn (string $code): string => Hash::make($this->normalizeRecoveryCode($code)),
            $codes,
        );
        $user->save();

        return $codes;
    }

    /**
     * Complete the 2FA setup once an initial code has been verified.
     *
     * @return array<int, string>
     */
    public function confirmSetup(User $user, string $secret, string $code): array
    {
        if ($user->twoFactorEnabled()) {
            throw ValidationException::withMessages([
                'code' => ['Two-factor authentication is already enabled for this account.'],
            ]);
        }

        if (! $this->verifyCode($secret, $code)) {
            throw ValidationException::withMessages([
                'code' => ['The verification code is invalid or has expired.'],
            ]);
        }

        $user->two_factor_secret = $secret;
        $user->two_factor_confirmed_at = now();
        $user->save();

        return $this->issueRecoveryCodes($user);
    }

    /**
     * Disable 2FA after confirming the account password.
     */
    public function disable(User $user, string $password): void
    {
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['The password is incorrect.'],
            ]);
        }

        $user->two_factor_secret = null;
        $user->two_factor_confirmed_at = null;
        $user->two_factor_recovery_codes = null;
        $user->save();
    }

    /**
     * Consume a valid recovery code (one-time use), returning whether it matched.
     */
    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $submitted = $this->normalizeRecoveryCode($code);
        $recoveryCodes = $user->two_factor_recovery_codes ?? [];

        foreach ($recoveryCodes as $index => $hash) {
            if (Hash::check($submitted, $hash)) {
                unset($recoveryCodes[$index]);
                $user->two_factor_recovery_codes = array_values($recoveryCodes);
                $user->save();

                return true;
            }
        }

        return false;
    }

    private function normalizeRecoveryCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $code) ?? '');
    }

    private function randomChunk(): string
    {
        return Str::upper(Str::random(4));
    }
}
