<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PaymentIntent;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorDestinationVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use LogicException;

/** @extends Factory<VendorDestinationVersion> */
class VendorDestinationVersionFactory extends Factory
{
    protected $model = VendorDestinationVersion::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['version' => fake()->uuid(), 'prepared_by' => User::factory(), 'chain' => 'ARC-TESTNET', 'chain_id' => 5042002,
            'control_evidence' => 'synthetic-control-evidence'];
    }

    public function forVendor(Vendor $vendor, User $preparer): static
    {
        return $this->state(['organization_id' => $vendor->organization_id, 'vendor_id' => $vendor->id,
            'prepared_by' => $preparer->id, 'address' => strtolower($vendor->wallet_address)]);
    }

    public function configure(): static
    {
        return $this->afterMaking(function (VendorDestinationVersion $destination): void {
            if (! isset($destination->getAttributes()['vendor_id'], $destination->getAttributes()['address'])) {
                throw new LogicException('Destination factory needs an existing vendor and recorded address via forVendor().');
            }
            $destination->content_digest = PaymentIntent::digest($destination->content());
        });
    }
}
