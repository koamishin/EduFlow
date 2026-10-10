<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\PaymentReviewerEnrollment;
use App\Models\User;
use App\Services\InstallationInstitution;
use App\Services\PaymentReviewMfa;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

final readonly class EnrollPaymentReviewer
{
    public function __construct(private InstallationInstitution $institutions, private PaymentReviewMfa $mfa) {}

    /** @return array<string, mixed> */
    public static function inputRules(): array
    {
        return ['request_key' => ['required', 'uuid'], 'reviewer_mfa_method' => ['required', Rule::in(['filament_app', 'fortify_totp'])],
            'verification_reference' => ['required', 'string', 'max:255'], 'password' => ['required', 'string', 'max:1024'],
            'mfa_code' => ['required', 'string', 'regex:/^[0-9]{6}$/D'], 'mfa_method' => ['required', Rule::in(['filament_app', 'fortify_totp'])],
            'approved_by' => ['prohibited'], 'organization_id' => ['prohibited'], 'factor_fingerprint' => ['prohibited'], 'snapshot' => ['prohibited']];
    }

    /** @param array<string, mixed> $input */
    public function handle(User $checker, User $reviewer, #[SensitiveParameter] array $input): PaymentReviewerEnrollment
    {
        Gate::forUser($checker)->authorize('create', PaymentReviewerEnrollment::class);
        $data = Validator::make($input, self::inputRules())->validate();
        $data['request_key'] = Str::lower($data['request_key']);
        $data['verification_reference'] = trim($data['verification_reference']);
        if ($checker->id === $reviewer->id || $data['verification_reference'] === '') {
            throw ValidationException::withMessages(['reviewer' => 'Another verified staff member must independently confirm reviewer identity and authenticator ownership.']);
        }
        $institution = $this->institutions->require();
        $this->mfa->hitAttempt($checker);

        return DB::transaction(function () use ($checker, $reviewer, $institution, $data): PaymentReviewerEnrollment {
            Organization::query()->whereKey($institution->id)->lockForUpdate()->firstOrFail();
            /** @var Collection<int, User> $users */
            $users = User::query()->whereIn('id', [$checker->id, $reviewer->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            /** @var User $actor */
            $actor = $users->get($checker->id);
            /** @var User $target */
            $target = $users->get($reviewer->id);
            Gate::forUser($actor)->authorize('create', PaymentReviewerEnrollment::class);
            abort_unless($actor->hasVerifiedEmail() && $actor->hasAnyRole(['admin', 'super_admin']), 403);
            if ($actor->isImpersonated() || ! $target->hasVerifiedEmail() || ! $target->hasAnyRole(['finance_officer', 'admin', 'super_admin'])
                || ! $target->checkPermissionTo('AuthorizePayment:PaymentIntent', 'web') || ! $target->hasDirectPermission('AuthorizePayment:PaymentIntent')) {
                throw ValidationException::withMessages(['reviewer' => 'Real checker and verified staff reviewer with explicitly assigned payment permission are required.']);
            }
            $fingerprint = PaymentReviewMfa::factorFingerprint($target, $data['reviewer_mfa_method']);
            /** @var PaymentReviewerEnrollment|null $existing */
            $existing = PaymentReviewerEnrollment::query()->where('reviewer_id', $target->id)->orWhere('request_key', $data['request_key'])->first();
            if ($existing !== null) {
                if ($existing->organization_id !== $institution->id || $existing->request_key !== $data['request_key']
                    || $existing->reviewer_id !== $target->id || $existing->approved_by !== $actor->id
                    || $existing->mfa_method !== $data['reviewer_mfa_method'] || ! hash_equals($existing->factor_fingerprint, $fingerprint)
                    || ($existing->snapshot['verification_reference'] ?? null) !== $data['verification_reference'] || ! $existing->hasValidEvidence()) {
                    throw ValidationException::withMessages(['reviewer' => 'Reviewer enrollment already exists or changed; independent factor recovery is required.']);
                }

                return $existing;
            }
            $proof = $this->mfa->verify($actor, $data['password'], $data['mfa_code'], $data['mfa_method']);
            if (hash_equals($fingerprint, $proof['factor_fingerprint'])) {
                throw ValidationException::withMessages(['reviewer' => 'Checker and reviewer must not share an authenticator secret.']);
            }
            $snapshot = ['schema_version' => 1, 'purpose' => 'payment_reviewer_enrollment', 'request_key' => $data['request_key'],
                'institution_id' => $institution->id, 'reviewer_id' => $target->id, 'approved_by' => $actor->id,
                'mfa_method' => $data['reviewer_mfa_method'], 'factor_fingerprint' => $fingerprint,
                'verification_reference' => $data['verification_reference'], 'checker_mfa' => $proof];
            /** @var PaymentReviewerEnrollment $enrollment */
            $enrollment = PaymentReviewerEnrollment::query()->create(['request_key' => $data['request_key'], 'organization_id' => $institution->id,
                'reviewer_id' => $target->id, 'approved_by' => $actor->id, 'mfa_method' => $data['reviewer_mfa_method'], 'factor_fingerprint' => $fingerprint,
                'checker_factor_fingerprint' => $proof['factor_fingerprint'], 'checker_timestep' => $proof['timestep'],
                'snapshot' => $snapshot, 'snapshot_digest' => PaymentIntent::digest($snapshot)]);
            activity('finance')->causedBy($actor)->performedOn($enrollment)->event('payment_reviewer_enrolled')
                ->withProperties(['reviewer_id' => $target->id, 'mfa_method' => $data['reviewer_mfa_method'], 'snapshot_digest' => $enrollment->snapshot_digest])
                ->log('Reviewer authenticator independently pinned; no payment approved or executed');

            return $enrollment;
        }, 3);
    }
}
