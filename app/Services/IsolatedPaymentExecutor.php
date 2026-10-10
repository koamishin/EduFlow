<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PaymentSubmissionOutbox;
use Illuminate\Validation\ValidationException;
use Throwable;
use Yukazakiri\Lepton\Contracts\WalletGateway;

/**
 * The only component permitted to ask a rail to move money.
 *
 * It exists as a separate service so that everything above it — the planner,
 * the reservations, the authorizations, the outbox — can be written and
 * reviewed without any of them holding a path to signing. It receives an
 * already-authorized durable entry and does exactly four things, in order:
 * re-check authority, preflight the fee, submit once with a stable provider
 * identity, and report the outcome honestly.
 *
 * What it deliberately does not do: decide which bill to pay, choose an
 * amount, resolve a destination, retry an uncertain submission, or mark
 * anything settled. Settlement is the verifier's verdict, never this class's
 * opinion.
 */
final readonly class IsolatedPaymentExecutor
{
    public function __construct(private WalletGateway $wallets, private InstallationInstitution $institutions) {}

    /**
     * Preflight without broadcasting.
     *
     * Estimates the fee so an unaffordable or oversized transfer is refused
     * before anything is sent, and never reaches the rail as a write.
     *
     * @return array<string, mixed>
     */
    public function estimate(PaymentSubmissionOutbox $entry): array
    {
        $this->assertRail($entry);

        return $this->wallets->transfer(
            $entry->snapshot['source_address'],
            $entry->snapshot['recipient_address'],
            (int) $entry->snapshot['amount_base_units'],
            [
                'chain' => $entry->snapshot['chain'],
                'idempotencyKey' => $entry->provider_idempotency_key,
                'estimate' => true,
            ],
        )->raw;
    }

    /**
     * Submit exactly once, under an identity the provider will recognise on a
     * retry.
     *
     * A timeout or malformed response is reported as unknown, never as a
     * failure: the payment may still have been accepted, and the honest
     * response is to reconcile the existing attempt rather than send again.
     *
     * @return array{outcome:string, provider_reference:?string, detail:array<string, mixed>}
     */
    public function submit(PaymentSubmissionOutbox $entry): array
    {
        $this->assertRail($entry);

        if (config('eduflow.submission.stop_switch', false) === true) {
            throw ValidationException::withMessages(['payment' => 'Submission stop switch is engaged; no transfer was attempted.']);
        }

        try {
            $result = $this->wallets->transfer(
                $entry->snapshot['source_address'],
                $entry->snapshot['recipient_address'],
                (int) $entry->snapshot['amount_base_units'],
                [
                    'chain' => $entry->snapshot['chain'],
                    'idempotencyKey' => $entry->provider_idempotency_key,
                ],
            );
        } catch (Throwable $exception) {
            report($exception);

            // Uncertain, not failed. Capacity stays held until reconciled.
            return ['outcome' => 'unknown', 'provider_reference' => null,
                'detail' => ['error' => $exception->getMessage(), 'provider_exception' => true]];
        }

        if ($result->isFake || config('lepton.default') === 'fake') {
            return ['outcome' => 'blocked', 'provider_reference' => null,
                'detail' => ['reason' => 'Fake driver. Simulation is never submitted as a network transfer.', 'is_fake' => true]];
        }

        return [
            'outcome' => 'submitted',
            'provider_reference' => $result->txHash,
            'detail' => [
                'from' => $result->fromAddress,
                'to' => $result->toAddress,
                'amount_base_units' => (string) $result->amountBaseUnits,
                'chain' => $result->chain,
                'explorer_url' => $result->explorerUrl,
            ],
        ];
    }

    /**
     * The rail must be the one this installation was explicitly configured
     * for. There is no fallback, and a mismatch is refused rather than
     * resolved in favour of whatever happens to be reachable.
     */
    private function assertRail(PaymentSubmissionOutbox $entry): void
    {
        if (! config('eduflow.submission.enabled', false)) {
            throw ValidationException::withMessages(['payment' => 'Payment submission runtime is disabled.']);
        }

        $institution = $this->institutions->current();

        if ($institution === null) {
            throw ValidationException::withMessages(['payment' => 'Institution context unavailable; no transfer attempted.']);
        }

        $chain = $entry->snapshot['chain'] ?? null;
        $chainId = $entry->snapshot['chain_id'] ?? null;

        if ($chain !== config('lepton.arc.chain') || $chainId !== config('lepton.arc.chain_id')
            || $chain !== 'ARC-TESTNET' || $chainId !== 5042002) {
            throw ValidationException::withMessages(['payment' => 'Only explicitly configured Arc testnet execution is permitted; mainnet remains blocked.']);
        }

        foreach (['source_address', 'recipient_address', 'amount_base_units'] as $required) {
            if (($entry->snapshot[$required] ?? null) === null || $entry->snapshot[$required] === '') {
                throw ValidationException::withMessages(['payment' => 'Submission lacks bound treasury, recipient or amount evidence.']);
            }
        }

        if (! is_string($entry->snapshot['recipient_address'])
            || preg_match('/^0x[0-9a-f]{40}$/D', strtolower($entry->snapshot['recipient_address'])) !== 1) {
            throw ValidationException::withMessages(['payment' => 'Bound recipient is not a usable address; a rejected address is recoverable, one the recipient does not control is not.']);
        }
    }
}
