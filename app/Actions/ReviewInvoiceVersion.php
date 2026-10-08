<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Budget;
use App\Models\Invoice;
use App\Models\InvoiceVersion;
use App\Models\InvoiceVersionReview;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Services\InstallationInstitution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final readonly class ReviewInvoiceVersion
{
    public function __construct(private InstallationInstitution $institutions) {}

    public function handle(User $reviewer, InvoiceVersion $bill, string $expectedDigest, string $decision, string $reason): InvoiceVersionReview
    {
        Gate::forUser($reviewer)->authorize('review', $bill);
        if (! in_array($decision, ['approve_evidence', 'reject', 'hold'], true) || trim($reason) === '' || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['decision' => 'Explicit evidence review decision and reason are required.']);
        }
        $institution = $this->institutions->require();

        return DB::transaction(function () use ($reviewer, $bill, $expectedDigest, $decision, $reason, $institution): InvoiceVersionReview {
            Organization::query()->whereKey($institution->id)->lockForUpdate()->firstOrFail();
            /** @var InvoiceVersion $stored */
            $stored = InvoiceVersion::query()->where('organization_id', $institution->id)->whereKey($bill->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($reviewer)->authorize('review', $stored);
            /** @var Invoice $invoice */
            $invoice = Invoice::query()->where('organization_id', $institution->id)->whereKey($stored->invoice_id)->lockForUpdate()->firstOrFail();
            /** @var Budget|null $budget */
            $budget = Budget::query()->where('organization_id', $institution->id)->whereKey($invoice->budget_id)->first();
            if (! $stored->hasValidSnapshot() || ! hash_equals($stored->snapshot_digest, $expectedDigest)
                || ! hash_equals(PaymentIntent::digest($stored->snapshot['document']), PaymentIntent::digest(CaptureInvoiceVersion::documentContext($invoice)))
                || $budget === null || $budget->status !== 'active' || ! in_array($invoice->status, ['pending', 'held', 'escalated'], true)) {
                throw ValidationException::withMessages(['expected_digest' => 'Source evidence is stale or invalid; review requires the intact captured document.']);
            }
            /** @var InvoiceVersionReview|null $existing */
            $existing = InvoiceVersionReview::query()->where('invoice_version_id', $stored->id)->first();
            if ($existing !== null) {
                if (! $existing->hasValidEvidence($stored) || $existing->reviewed_by !== $reviewer->id
                    || $existing->decision !== $decision || $existing->reason !== $reason) {
                    throw ValidationException::withMessages(['decision' => 'Review already recorded with different evidence or feedback.']);
                }

                return $existing;
            }
            $review = new InvoiceVersionReview(['organization_id' => $institution->id, 'invoice_version_id' => $stored->id,
                'reviewed_by' => $reviewer->id, 'decision' => $decision, 'reason' => $reason, 'bill_digest' => $stored->snapshot_digest]);
            $review->review_digest = PaymentIntent::digest($review->content());
            $review->save();
            activity('finance')->causedBy($reviewer)->performedOn($review)->event('invoice_evidence_reviewed')
                ->withProperties(['invoice_version_id' => $stored->id, 'decision' => $decision, 'review_digest' => $review->review_digest, 'can_execute' => false])
                ->log('Source and USDC reference evidence reviewed; no payment approval or transfer');

            return $review;
        }, 3);
    }
}
