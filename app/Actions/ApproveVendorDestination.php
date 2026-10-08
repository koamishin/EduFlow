<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorDestinationApproval;
use App\Models\VendorDestinationVersion;
use App\Services\InstallationInstitution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final readonly class ApproveVendorDestination
{
    public function __construct(private InstallationInstitution $institutions) {}

    public function handle(User $reviewer, VendorDestinationVersion $destination, string $expectedDigest, ?int $expectedApprovalId, string $verificationReference): VendorDestinationApproval
    {
        $institution = $this->institutions->require();

        return DB::transaction(function () use ($reviewer, $destination, $expectedDigest, $expectedApprovalId, $verificationReference, $institution): VendorDestinationApproval {
            Organization::query()->whereKey($institution->id)->lockForUpdate()->firstOrFail();
            /** @var VendorDestinationVersion $stored */
            $stored = VendorDestinationVersion::query()->where('organization_id', $institution->id)->whereKey($destination->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($reviewer)->authorize('approve', $stored);
            /** @var Vendor $vendor */
            $vendor = Vendor::query()->where('organization_id', $institution->id)->whereKey($stored->vendor_id)->lockForUpdate()->firstOrFail();
            if (! $vendor->isVerified() || ! $stored->hasValidContent() || ! hash_equals($stored->content_digest, $expectedDigest)
                || trim($verificationReference) === '' || mb_strlen($verificationReference) > 255) {
                throw ValidationException::withMessages(['destination' => 'Intact reviewed vendor evidence and independent verification reference are required.']);
            }
            $current = VendorDestinationApproval::current($institution->id, $stored->vendor_id);
            if ($current instanceof VendorDestinationApproval && ! $current->hasValidHistory()) {
                throw ValidationException::withMessages(['destination' => 'Destination approval history failed integrity verification.']);
            }
            if ($current instanceof VendorDestinationApproval && $current->vendor_destination_version_id === $stored->id) {
                if ($current->approved_by !== $reviewer->id || $current->previous_approval_id !== $expectedApprovalId
                    || $current->verification_reference !== $verificationReference) {
                    throw ValidationException::withMessages(['destination' => 'Approval retry conflicts with original verification.']);
                }

                return $current;
            }
            if ($current?->id !== $expectedApprovalId) {
                throw ValidationException::withMessages(['expected_approval' => 'Current vendor destination changed; review latest evidence explicitly.']);
            }
            if (VendorDestinationApproval::query()->where('vendor_destination_version_id', $stored->id)->exists()) {
                throw ValidationException::withMessages(['destination' => 'Superseded destination cannot be reactivated; prepare a new version.']);
            }
            $approval = new VendorDestinationApproval(['organization_id' => $institution->id, 'vendor_id' => $stored->vendor_id,
                'vendor_destination_version_id' => $stored->id, 'approved_by' => $reviewer->id,
                'previous_approval_id' => $current?->id, 'previous_approval_digest' => $current?->approval_digest,
                'verification_reference' => $verificationReference, 'destination_digest' => $stored->content_digest]);
            $approval->approval_digest = PaymentIntent::digest($approval->content());
            $approval->save();
            activity('finance')->causedBy($reviewer)->performedOn($approval)->event('vendor_destination_approved')
                ->withProperties(['vendor_id' => $stored->vendor_id, 'destination_version_id' => $stored->id, 'approval_digest' => $approval->approval_digest, 'can_execute' => false])
                ->log('Vendor destination independently reviewed; no payment authority granted');

            return $approval;
        }, 3);
    }
}
