<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FinancePolicyVersion;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<FinancePolicyVersion> */
class FinancePolicyVersionFactory extends Factory
{
    protected $model = FinancePolicyVersion::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'created_by' => User::factory(),
            'version' => 'v-'.Str::uuid(),
            'currency' => 'USDC',
            'minimum_reserve_base_units' => 0,
            'max_auto_payment_base_units' => 0,
            'max_daily_disbursement_base_units' => 0,
            'max_fee_base_units' => 0,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (FinancePolicyVersion $policy): void {
            $policy->content_digest = PaymentIntent::digest($policy->content());
        });
    }
}
