<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Models\CollectionBatch;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Services\FinanceWorkflows;
use App\Services\InstallationInstitution;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use OverflowException;

final readonly class CaptureCollectionBatch
{
    public function __construct(private InstallationInstitution $institutions, private FinanceWorkflows $workflows) {}

    /** @param array<string, mixed> $data */
    public function handle(User $actor, array $data): CollectionBatch
    {
        Gate::forUser($actor)->authorize('create', CollectionBatch::class);
        $data = Validator::make($data, self::inputRules())->validate();
        $currency = CurrencyCode::from($data['currency']);
        try {
            $received = Money::fromDecimal($data['received_amount'], $currency)->minorUnits;
            $restricted = Money::fromDecimal($data['restricted_amount'], $currency)->minorUnits;
        } catch (InvalidArgumentException|OverflowException) {
            throw ValidationException::withMessages(['received_amount' => 'Exact bounded amounts within currency precision required.']);
        }
        if ($received <= 0 || $restricted < 0 || $restricted > $received) {
            throw ValidationException::withMessages(['restricted_amount' => 'Received funds must be positive; restrictions must be within received funds.']);
        }
        $institution = $this->institutions->require();
        $from = Carbon::parse($data['collected_from'])->utc();
        $until = Carbon::parse($data['collected_until'])->utc();
        $snapshot = ['schema_version' => 1, 'purpose' => 'aggregate_realized_fee_receipts', 'capture_key' => Str::lower($data['capture_key']),
            'institution_id' => $institution->id, 'prepared_by' => $actor->id, 'source_stream' => $data['source_stream'],
            'source_reference' => Str::lower(trim($data['source_reference'])), 'source_document_digest' => $data['source_document_digest'],
            'currency' => $currency->value, 'received_minor_units' => (string) $received, 'restricted_minor_units' => (string) $restricted,
            'collected_from' => $from->toIso8601String(), 'collected_until' => $until->toIso8601String(),
            'cash_evidence_reference' => $data['cash_evidence_reference'], 'source_stream_disjoint_attested' => true, 'received_not_forecast_attested' => true];

        return DB::transaction(function () use ($actor, $institution, $snapshot, $from, $until, $received, $restricted): CollectionBatch {
            Organization::query()->whereKey($institution->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('create', CollectionBatch::class);
            /** @var CollectionBatch|null $existing */
            $existing = CollectionBatch::query()->where('capture_key', $snapshot['capture_key'])->first();
            if ($existing !== null) {
                if (! $existing->hasValidSnapshot() || ! hash_equals($existing->snapshot_digest, PaymentIntent::digest($snapshot))) {
                    throw ValidationException::withMessages(['capture_key' => 'Collection capture identity already binds different source or amounts.']);
                }

                return $existing;
            }
            $duplicate = CollectionBatch::query()->where('organization_id', $institution->id)
                ->where(function (Builder $query) use ($snapshot, $from, $until): void {
                    $query->where('source_document_digest', $snapshot['source_document_digest'])
                        ->orWhere(function (Builder $stream) use ($snapshot, $from, $until): void {
                            $stream->where('source_stream', $snapshot['source_stream'])
                                ->where(function (Builder $interval) use ($snapshot, $from, $until): void {
                                    $interval->where('source_reference', $snapshot['source_reference'])
                                        ->orWhere(function (Builder $overlap) use ($from, $until): void {
                                            $overlap->where('collected_from', '<', $until)->where('collected_until', '>', $from);
                                        });
                                });
                        });
                })->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['source_reference' => 'Source document, reference or collection interval is already represented; new keys cannot count it twice.']);
            }
            /** @var CollectionBatch $batch */
            $batch = CollectionBatch::query()->create(['capture_key' => $snapshot['capture_key'], 'organization_id' => $institution->id,
                'prepared_by' => $actor->id, 'source_stream' => $snapshot['source_stream'], 'source_reference' => $snapshot['source_reference'],
                'source_document_digest' => $snapshot['source_document_digest'], 'currency' => $snapshot['currency'],
                'received_minor_units' => $received, 'restricted_minor_units' => $restricted, 'collected_from' => $from, 'collected_until' => $until,
                'snapshot' => $snapshot, 'snapshot_digest' => PaymentIntent::digest($snapshot)]);
            $this->workflows->collectionCaptured($batch);
            activity('finance')->causedBy($actor)->performedOn($batch)->event('collection_batch_captured')
                ->withProperties(['snapshot_digest' => $batch->snapshot_digest, 'can_execute' => false])
                ->log('Aggregate realized fee evidence captured; no cash posting, student allocation or transfer');

            return $batch;
        }, 3);
    }

    /** @return array<string, mixed> */
    public static function inputRules(): array
    {
        return ['capture_key' => ['required', 'string', 'uuid'], 'source_stream' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9._-]{0,63}$/D'],
            'source_reference' => ['required', 'string', 'min:3', 'max:120'], 'source_document_digest' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/D'],
            'currency' => ['required', Rule::in(CurrencyCode::values())], 'received_amount' => ['required', 'string', 'max:30'],
            'restricted_amount' => ['required', 'string', 'max:30'], 'collected_from' => ['required', 'date_format:Y-m-d\TH:i:sP', 'before:collected_until'],
            'collected_until' => ['required', 'date_format:Y-m-d\TH:i:sP', 'before_or_equal:now'],
            'cash_evidence_reference' => ['required', 'string', 'min:3', 'max:255'], 'source_stream_disjoint' => ['required', 'accepted'],
            'received_not_forecast' => ['required', 'accepted']];
    }
}
