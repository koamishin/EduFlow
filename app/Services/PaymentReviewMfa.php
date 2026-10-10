<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PaymentAuthorization;
use App\Models\PaymentReviewerEnrollment;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;
use SensitiveParameter;
use Throwable;

final readonly class PaymentReviewMfa
{
    public function __construct(private Google2FA $totp) {}

    public function hitAttempt(User $actor): void
    {
        $limiterKey = 'finance.payment-review-mfa.'.$actor->id;
        if (RateLimiter::tooManyAttempts($limiterKey, 5)) {
            throw ValidationException::withMessages(['mfa_code' => 'Too many payment authentication attempts; wait before retrying.']);
        }
        RateLimiter::hit($limiterKey, 60);
    }

    /** @return array{method: string, factor_fingerprint: string, timestep: int, verified_at: string} */
    public function verify(User $actor, #[SensitiveParameter] string $password, #[SensitiveParameter] string $code, string $method): array
    {
        if ($actor->isImpersonated() || ! $actor->hasVerifiedEmail() || ! Hash::check($password, $actor->password)
            || preg_match('/^[0-9]{6}$/D', $code) !== 1) {
            $this->refuse();
        }
        try {
            $secret = self::factorSecret($actor, $method);
            $fingerprint = self::factorFingerprint($actor, $method);
            $lastPaymentStep = PaymentAuthorization::query()->where('reviewed_by', $actor->id)
                ->where('factor_fingerprint', $fingerprint)->max('mfa_timestep');
            $lastEnrollmentStep = PaymentReviewerEnrollment::query()->where('approved_by', $actor->id)
                ->where('checker_factor_fingerprint', $fingerprint)->max('checker_timestep');
            $loginStep = Cache::get('filament.app_authentication_codes.'.md5($secret), 0);
            $fortifyStep = Cache::get('fortify.2fa_codes.'.md5($code), 0);
            $lastStep = max((int) $lastPaymentStep, (int) $lastEnrollmentStep, (int) $loginStep, (int) $fortifyStep);
            $step = $this->totp->verifyKeyNewer($secret, $code, $lastStep, 1, intdiv(now()->timestamp, 30));
            if (! is_int($step)) {
                $this->refuse();
            }
            $filamentKey = 'filament.app_authentication_codes.'.md5($secret);
            $fortifyKey = 'fortify.2fa_codes.'.md5($code);
            // Publish replay markers only after durable review succeeds, including cross-provider reuse of the same factor.
            DB::afterCommit(static function () use ($filamentKey, $fortifyKey, $step): void {
                Cache::put($filamentKey, max($step, (int) Cache::get($filamentKey, 0)), 600);
                Cache::put($fortifyKey, max($step, (int) Cache::get($fortifyKey, 0)), 600);
            });
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->refuse();
        }

        return ['method' => $method, 'factor_fingerprint' => $fingerprint, 'timestep' => $step,
            'verified_at' => now()->utc()->toIso8601String()];
    }

    public static function factorFingerprint(User $actor, string $method): string
    {
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw ValidationException::withMessages(['mfa_method' => 'Application key is required for pinned payment authentication.']);
        }

        return hash_hmac('sha256', self::factorSecret($actor, $method), $key);
    }

    private static function factorSecret(User $actor, string $method): string
    {
        $secret = match ($method) {
            'filament_app' => $actor->getAppAuthenticationSecret(),
            'fortify_totp' => $actor->getAttribute('two_factor_confirmed_at') !== null && is_string($actor->getAttribute('two_factor_secret'))
                ? decrypt($actor->getAttribute('two_factor_secret')) : null,
            default => null,
        };
        if (! is_string($secret) || $secret === '') {
            throw ValidationException::withMessages(['mfa_method' => 'Selected authenticator must already be enrolled and confirmed.']);
        }

        return $secret;
    }

    private function refuse(): never
    {
        throw ValidationException::withMessages(['mfa_code' => 'Fresh password and an unused code from the selected enrolled authenticator are required.']);
    }
}
