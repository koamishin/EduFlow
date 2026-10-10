<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\PaymentReviewMfa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * @property int $id
 * @property string $request_key
 * @property int $organization_id
 * @property int $reviewer_id
 * @property int $approved_by
 * @property string $mfa_method
 * @property string $factor_fingerprint
 * @property string $checker_factor_fingerprint
 * @property int $checker_timestep
 * @property array<string, mixed> $snapshot
 * @property string $snapshot_digest
 */
class PaymentReviewerEnrollment extends Model
{
    protected $fillable = ['request_key', 'organization_id', 'reviewer_id', 'approved_by', 'mfa_method', 'factor_fingerprint',
        'checker_factor_fingerprint', 'checker_timestep', 'snapshot', 'snapshot_digest'];

    protected $hidden = ['factor_fingerprint', 'checker_factor_fingerprint', 'snapshot'];

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (self $enrollment): void {
            if (! $enrollment->hasValidEvidence()) {
                throw new LogicException('Reviewer enrollment requires independently verified authenticator evidence.');
            }
        });
        static::updating(function (): never {
            throw new LogicException('Payment reviewer enrollment is immutable; factor rotation requires independent recovery review.');
        });
        static::deleting(function (): never {
            throw new LogicException('Payment reviewer enrollment evidence cannot be deleted.');
        });
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['organization_id' => 'integer', 'reviewer_id' => 'integer', 'approved_by' => 'integer', 'checker_timestep' => 'integer', 'snapshot' => 'array'];
    }

    public function hasValidEvidence(): bool
    {
        $s = $this->getAttribute('snapshot');

        return is_array($s) && Str::isUuid($this->request_key) && $this->request_key === strtolower($this->request_key)
            && $this->organization_id > 0 && $this->reviewer_id > 0 && $this->approved_by > 0 && $this->approved_by !== $this->reviewer_id
            && in_array($this->mfa_method, ['filament_app', 'fortify_totp'], true)
            && preg_match('/^[0-9a-f]{64}$/D', $this->factor_fingerprint) === 1
            && preg_match('/^[0-9a-f]{64}$/D', $this->checker_factor_fingerprint) === 1
            && $this->factor_fingerprint !== $this->checker_factor_fingerprint && $this->checker_timestep > 0
            && ($s['schema_version'] ?? null) === 1 && ($s['purpose'] ?? null) === 'payment_reviewer_enrollment'
            && ($s['request_key'] ?? null) === $this->request_key && ($s['institution_id'] ?? null) === $this->organization_id
            && ($s['reviewer_id'] ?? null) === $this->reviewer_id && ($s['approved_by'] ?? null) === $this->approved_by
            && ($s['mfa_method'] ?? null) === $this->mfa_method && ($s['factor_fingerprint'] ?? null) === $this->factor_fingerprint
            && ($s['checker_mfa']['factor_fingerprint'] ?? null) === $this->checker_factor_fingerprint
            && ($s['checker_mfa']['timestep'] ?? null) === $this->checker_timestep
            && is_string($s['verification_reference'] ?? null) && trim($s['verification_reference']) !== ''
            && hash_equals($this->snapshot_digest, PaymentIntent::digest($s));
    }

    public function matchesCurrentFactor(User $reviewer): bool
    {
        try {
            return $this->hasValidEvidence() && $reviewer->id === $this->reviewer_id
                && hash_equals($this->factor_fingerprint, PaymentReviewMfa::factorFingerprint($reviewer, $this->mfa_method));
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    public function evidence(): array
    {
        return ['id' => $this->id, 'reviewer_id' => $this->reviewer_id, 'approved_by' => $this->approved_by,
            'mfa_method' => $this->mfa_method, 'snapshot_digest' => $this->snapshot_digest,
            'verification_reference' => $this->snapshot['verification_reference'] ?? null,
            'can_execute' => false, 'payment_approved' => false];
    }
}
