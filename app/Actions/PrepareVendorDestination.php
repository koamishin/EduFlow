<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorDestinationVersion;
use App\Services\InstallationInstitution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final readonly class PrepareVendorDestination
{
    public function __construct(private InstallationInstitution $institutions) {}

    public function handle(User $actor, Vendor $vendor, string $version, string $address, string $chain, string $controlEvidence): VendorDestinationVersion
    {
        Gate::forUser($actor)->authorize('create', VendorDestinationVersion::class);
        $institution = $this->institutions->require();

        return DB::transaction(function () use ($actor, $vendor, $version, $address, $chain, $controlEvidence, $institution): VendorDestinationVersion {
            Organization::query()->whereKey($institution->id)->lockForUpdate()->firstOrFail();
            /** @var Vendor $stored */
            $stored = Vendor::query()->where('organization_id', $institution->id)->whereKey($vendor->id)->lockForUpdate()->firstOrFail();
            if (! $stored->isVerified()) {
                throw ValidationException::withMessages(['vendor' => 'A verified institution vendor is required.']);
            }
            $destination = new VendorDestinationVersion(['organization_id' => $institution->id, 'vendor_id' => $stored->id,
                'version' => $version, 'prepared_by' => $actor->id, 'address' => strtolower($address), 'chain' => $chain,
                'chain_id' => match ($chain) {
                    'ARC' => 5042, 'ARC-TESTNET' => 5042002, default => 0
                }, 'control_evidence' => $controlEvidence]);
            $destination->content_digest = PaymentIntent::digest($destination->content());
            if (! $destination->hasValidContent()) {
                throw ValidationException::withMessages(['destination' => 'Stable version, nonzero recorded address, supported network and control evidence are required.']);
            }
            /** @var VendorDestinationVersion|null $existing */
            $existing = VendorDestinationVersion::query()->where('organization_id', $institution->id)->where('vendor_id', $stored->id)->where('version', $version)->first();
            if ($existing !== null) {
                if (! $existing->hasValidContent() || ! hash_equals($existing->content_digest, $destination->content_digest)) {
                    throw ValidationException::withMessages(['version' => 'Destination version already has different evidence or preparer.']);
                }

                return $existing;
            }
            $destination->save();
            activity('finance')->causedBy($actor)->performedOn($destination)->event('vendor_destination_prepared')
                ->withProperties(['vendor_id' => $stored->id, 'content_digest' => $destination->content_digest, 'can_execute' => false])
                ->log('Vendor destination version prepared; independent verification required');

            return $destination;
        }, 3);
    }
}
