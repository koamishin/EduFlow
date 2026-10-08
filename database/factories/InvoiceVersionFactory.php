<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Actions\CaptureInvoiceVersion;
use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Models\Invoice;
use App\Models\InvoiceVersion;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Services\CurrencyValuationCalculator;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use LogicException;

/** @extends Factory<InvoiceVersion> */
class InvoiceVersionFactory extends Factory
{
    protected $model = InvoiceVersion::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['capture_key' => (string) Str::uuid(), 'prepared_by' => User::factory()];
    }

    public function forSource(Invoice $invoice, User $preparer): static
    {
        return $this->state(['invoice_id' => $invoice->id, 'prepared_by' => $preparer->id]);
    }

    public function configure(): static
    {
        return $this->afterMaking(function (InvoiceVersion $bill): void {
            if (! isset($bill->getAttributes()['invoice_id'])) {
                throw new LogicException('Invoice version factory needs an existing source invoice via forSource().');
            }
            /** @var Invoice $invoice */
            $invoice = Invoice::query()->findOrFail($bill->invoice_id);
            if ($invoice->budget_id === null) {
                throw new LogicException('Invoice version factory needs a department budget.');
            }
            $mapping = (new CurrencyValuationCalculator)->calculate('57.50', CurrencyCode::PHP, '57.5', 'half_up');
            $snapshot = [
                'schema_version' => 1, 'purpose' => 'invoice_source_evidence', 'capture_key' => $bill->capture_key,
                'institution_id' => $invoice->organization_id, 'prepared_by' => $bill->prepared_by,
                'document' => CaptureInvoiceVersion::documentContext($invoice),
                'department' => ['name' => 'Teaching services', 'period_start' => now()->toDateString(), 'period_end' => now()->addDays(14)->toDateString()],
                'source' => ['amount' => (new Money(5750, CurrencyCode::PHP))->decimal(), 'currency' => 'PHP',
                    'minor_units' => '5750', 'evidence_reference' => 'synthetic-source', 'business_approval_reference' => 'synthetic-approval'],
                'mapping' => ['source_per_usdc' => $mapping['source_per_usdc'], 'rounding' => 'half_up',
                    'rate_source' => 'synthetic-reference', 'rate_observed_at' => now()->toIso8601String(),
                    'valuation_base_units' => $mapping['valuation_base_units'], 'settlement_currency' => 'USDC'],
            ];
            $bill->fill(['organization_id' => $invoice->organization_id, 'budget_id' => $invoice->budget_id,
                'vendor_id' => $invoice->vendor_id, 'source_currency' => 'PHP', 'source_minor_units' => 5750,
                'valuation_base_units' => 1_000000, 'snapshot' => $snapshot, 'snapshot_digest' => PaymentIntent::digest($snapshot)]);
        });
    }
}
