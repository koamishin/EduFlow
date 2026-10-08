<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Models\Budget;
use App\Models\Invoice;
use App\Models\InvoiceVersion;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use App\Services\CurrencyValuationCalculator;
use App\Services\InstallationInstitution;
use Brick\Math\Exception\MathException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final readonly class CaptureInvoiceVersion
{
    public function __construct(private InstallationInstitution $institutions, private CurrencyValuationCalculator $calculator) {}

    /**
     * @param array{capture_key: string, source_amount: string, source_currency: string, source_evidence: string,
     * business_approval_reference: string, department: string, period_start: string, period_end: string,
     * source_per_usdc: string, rate_source: string, rate_observed_at: string, rounding: string} $data
     */
    public function handle(User $actor, Invoice $invoice, array $data): InvoiceVersion
    {
        Gate::forUser($actor)->authorize('create', InvoiceVersion::class);
        Validator::make($data, [
            'capture_key' => ['required', 'uuid'], 'source_amount' => ['required', 'string', 'max:30'],
            'source_currency' => ['required', Rule::in(CurrencyCode::values())],
            'source_evidence' => ['required', 'string', 'min:3', 'max:255'],
            'business_approval_reference' => ['required', 'string', 'min:3', 'max:255'],
            'department' => ['required', 'string', 'min:2', 'max:120'],
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'source_per_usdc' => ['required', 'string', 'max:31'], 'rate_source' => ['required', 'string', 'min:3', 'max:255'],
            'rate_observed_at' => ['required', 'date_format:Y-m-d\TH:i:sP', 'before_or_equal:now'],
            'rounding' => ['required', Rule::in(['down', 'half_up', 'up'])],
        ])->validate();
        $currency = CurrencyCode::from($data['source_currency']);
        try {
            $mapping = $this->calculator->calculate($data['source_amount'], $currency, $data['source_per_usdc'], $data['rounding']);
        } catch (\InvalidArgumentException|MathException|\OverflowException) {
            throw ValidationException::withMessages(['source_amount' => 'Exact positive source amount, supported reference rate and bounded valuation units are required.']);
        }
        $institution = $this->institutions->require();

        return DB::transaction(function () use ($actor, $invoice, $data, $currency, $mapping, $institution): InvoiceVersion {
            Organization::query()->whereKey($institution->id)->lockForUpdate()->firstOrFail();
            /** @var Invoice $stored */
            $stored = Invoice::query()->where('organization_id', $institution->id)->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            /** @var Budget|null $budget */
            $budget = Budget::query()->where('organization_id', $institution->id)->whereKey($stored->budget_id)->lockForUpdate()->first();
            /** @var Vendor|null $vendor */
            $vendor = Vendor::query()->where('organization_id', $institution->id)->whereKey($stored->vendor_id)->lockForUpdate()->first();
            if ($budget === null || $budget->status !== 'active' || $vendor === null
                || ! in_array($stored->status, ['pending', 'held', 'escalated'], true)
                || $stored->due_date->toDateString() < $data['period_start'] || $stored->due_date->toDateString() > $data['period_end']) {
                throw ValidationException::withMessages(['invoice' => 'Open institution bill, active budget and due date within the department period are required.']);
            }
            if (PaymentIntent::query()->where('invoice_id', $stored->id)->exists()
                || Transaction::query()->where('reference_type', Invoice::class)->where('reference_id', $stored->id)->exists()) {
                throw ValidationException::withMessages(['invoice' => 'Bill already has payment evidence; it cannot become a versioned source until legacy payment migration.']);
            }

            $snapshot = [
                'schema_version' => 1, 'purpose' => 'invoice_source_evidence', 'capture_key' => Str::lower($data['capture_key']),
                'institution_id' => $institution->id, 'prepared_by' => $actor->id,
                'document' => self::documentContext($stored),
                'department' => ['name' => $data['department'], 'period_start' => $data['period_start'], 'period_end' => $data['period_end']],
                'source' => ['amount' => (new Money((int) $mapping['source_minor_units'], $currency))->decimal(), 'currency' => $currency->value,
                    'minor_units' => $mapping['source_minor_units'], 'evidence_reference' => $data['source_evidence'],
                    'business_approval_reference' => $data['business_approval_reference']],
                'mapping' => ['source_per_usdc' => $mapping['source_per_usdc'], 'rounding' => $mapping['rounding'],
                    'rate_source' => $data['rate_source'], 'rate_observed_at' => $data['rate_observed_at'],
                    'valuation_base_units' => $mapping['valuation_base_units'], 'settlement_currency' => 'USDC'],
            ];
            /** @var InvoiceVersion|null $existing */
            $existing = InvoiceVersion::query()->where('invoice_id', $stored->id)->first();
            if ($existing !== null) {
                $snapshot['prepared_by'] = $existing->prepared_by;
                if (! $existing->hasValidSnapshot() || ! hash_equals($existing->snapshot_digest, PaymentIntent::digest($snapshot))) {
                    throw ValidationException::withMessages(['invoice' => 'Existing invoice version evidence conflicts; reviewed successor work is required.']);
                }

                return $existing;
            }
            if (InvoiceVersion::query()->where('capture_key', $snapshot['capture_key'])->exists()) {
                throw ValidationException::withMessages(['capture_key' => 'Capture identity belongs to another bill.']);
            }
            /** @var InvoiceVersion $bill */
            $bill = InvoiceVersion::query()->create([
                'capture_key' => $snapshot['capture_key'], 'organization_id' => $institution->id, 'invoice_id' => $stored->id,
                'budget_id' => $budget->id, 'vendor_id' => $vendor->id, 'prepared_by' => $actor->id,
                'source_currency' => $currency->value, 'source_minor_units' => (int) $mapping['source_minor_units'],
                'valuation_base_units' => (int) $mapping['valuation_base_units'], 'snapshot' => $snapshot, 'snapshot_digest' => PaymentIntent::digest($snapshot),
            ]);
            activity('finance')->causedBy($actor)->performedOn($bill)->event('invoice_version_captured')
                ->withProperties(['invoice_id' => $stored->id, 'snapshot_digest' => $bill->snapshot_digest, 'can_execute' => false])
                ->log('Local source and exact USDC reference mapping captured; no local or chain payment');

            return $bill;
        }, 3);
    }

    /** @return array<string, int|string|null> */
    public static function documentContext(Invoice $invoice): array
    {
        return ['invoice_id' => $invoice->id, 'budget_id' => $invoice->budget_id, 'vendor_id' => $invoice->vendor_id,
            'reference' => $invoice->reference, 'due_date' => $invoice->due_date->toDateString(), 'category' => $invoice->category,
            // Detect legacy edits without interpreting float storage as an exact source amount.
            'legacy_amount_fingerprint' => hash('sha256', json_encode($invoice->getRawOriginal('amount'), JSON_THROW_ON_ERROR))];
    }
}
