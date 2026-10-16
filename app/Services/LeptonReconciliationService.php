<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Throwable;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\WalletGateway;
use Yukazakiri\Lepton\DTOs\TransactionRecord;

/**
 * Proves whether a recorded transaction actually settled on Arc.
 *
 * The database is EduFlow's own record and cannot vouch for itself. Every
 * claim of on-chain settlement is re-checked against the chain (or against
 * the Circle agent wallet's own history when RPC access is unavailable), so
 * invented hashes cannot masquerade as receipts.
 *
 * What this service will not do is the opposite error: a lookup that finds
 * nothing is *absence of evidence*, not evidence of absence. A transaction
 * that was submitted moments ago, sits behind a provider's propagation delay,
 * or was recorded against a different network is indistinguishable from one
 * that never existed — until it does not matter any more. So the only verdicts
 * that write to a financial record are `verified` (a successful on-chain
 * receipt) and `reverted` (a receipt that reports failure). Everything else is
 * an open question for a human, and `unresolved` rows are never auto-failed.
 */
class LeptonReconciliationService
{
    /**
     * Verdicts this service can reach, and what each one is allowed to prove.
     *
     * @var array<string, string>
     */
    private const VERDICTS = [
        'verified' => 'A successful on-chain receipt exists for this hash.',
        'pending' => 'The hash is known to the chain but has no receipt yet.',
        'reverted' => 'The chain accepted the transaction and its receipt reports failure.',
        'unresolved' => 'No transaction with this hash was found. Absence is not fabrication.',
        'observed' => 'The provider reports it complete, but no chain receipt could be read.',
        'unverifiable' => 'Neither the chain nor the provider could be read.',
        'ledger_only' => 'A local ledger row that never claimed a chain receipt.',
    ];

    public function __construct(
        private readonly ArcNetworkGateway $arc,
        private readonly WalletGateway $wallets,
    ) {}

    /**
     * @return array{
     *     verdict:string, checked:string, reason:string, block:?int, on_chain:?array<string,mixed>, chain_source:string
     * }
     */
    public function verify(Transaction $transaction): array
    {
        $hash = $transaction->provider_tx_hash;

        // A ledger credit never claims a receipt, so there is nothing to prove.
        if ($hash === null || $hash === '') {
            return $this->result(
                $transaction,
                'ledger_only',
                'No tx hash: recorded as a local ledger entry, not a chain transfer.',
            );
        }

        $chain = $this->viaChain($hash);

        if ($chain['reachable']) {
            return match ($chain['state']) {
                'settled' => $this->result(
                    $transaction,
                    'verified',
                    'Successful receipt in block '.($chain['block'] ?? '?').' on '.strtoupper($this->arc->chainCode())
                        .'. This proves the receipt succeeded, not that it moved the intended amount.',
                    $chain['block'],
                    $chain['tx'],
                    'rpc',
                ),
                'reverted' => $this->result(
                    $transaction,
                    'reverted',
                    'The chain reverted this transaction in block '.($chain['block'] ?? '?')
                        .'. A receipt exists and reports failure, which is direct evidence.',
                    $chain['block'],
                    $chain['tx'],
                    'rpc',
                ),
                'pending' => $this->result(
                    $transaction,
                    'pending',
                    'Known to the chain with no receipt yet, so it is still in flight. Nothing is concluded.',
                    null,
                    null,
                    'rpc',
                ),
                default => $this->result(
                    $transaction,
                    'unresolved',
                    'Hash is well-formed but absent from '.strtoupper($this->arc->chainCode())
                        .'. Absence is not fabrication: propagation delay, a reorganisation, and a hash from another'
                        .' network all look exactly like this, so the row stays open for investigation.',
                    null,
                    null,
                    'rpc',
                ),
            };
        }

        // Chain reads unavailable: fall back to the Circle agent wallet's own history.
        $circle = $this->viaCircle($transaction->wallet?->address, $hash);

        if ($circle === null) {
            return $this->result(
                $transaction,
                'unverifiable',
                'Chain reads unavailable and no agent session to cross-check, so this receipt cannot be confirmed.',
                null,
                null,
                'none',
            );
        }

        // The wallet history is bounded, so "not in the page we read" is not
        // evidence of absence either — it stays unresolved, never fabricated.
        return $circle
            ? $this->result($transaction, 'observed', 'Reported complete by the Circle agent wallet history, but no chain receipt was readable, so this is provider observation and not proof.', null, null, 'circle')
            : $this->result($transaction, 'unresolved', 'Absent from the Circle agent wallet history that could be read. A bounded history is not proof of absence; investigate before acting.', null, null, 'circle');
    }

    /**
     * @return array{verdict:string, total:int, verified:int, pending:int, reverted:int, unresolved:int, observed:int, unverifiable:int, ledger_only:int, items:Collection<int, array<string,mixed>>}
     */
    public function verifyAll(?int $organizationId = null, int $limit = 200): array
    {
        $query = Transaction::query()->with('wallet')->latest('id');

        if ($organizationId !== null) {
            $query->where('organization_id', $organizationId);
        }

        $items = $query->limit($limit)->get()
            ->map(fn (Transaction $t): array => ['transaction' => $t, ...$this->verify($t)])
            ->values();

        $counts = ['total' => $items->count()];
        foreach (array_keys(self::VERDICTS) as $verdict) {
            $counts[$verdict] = $items->where('verdict', $verdict)->count();
        }

        // A reverted receipt is a proven discrepancy. Everything short of proof
        // is not clean either — it is unresolved, which still needs a human.
        return [
            'verdict' => $counts['reverted'] > 0 ? 'discrepancies' : ($counts['unresolved'] > 0 ? 'unresolved' : 'clean'),
            ...$counts,
            'items' => $items,
        ];
    }

    /**
     * Invoices are business documents, not chain data. What can be proven is
     * whether the payment that settled them actually happened — and only a
     * successful on-chain receipt proves that. A provider ID or a stored hash
     * is a claim, so `observed`, `pending` and `unresolved` cannot settle one.
     *
     * @return array{invoice: Invoice, settlement_verdict: string, evidence: Collection<int, Transaction>}
     */
    public function verifyInvoiceSettlement(Invoice $invoice): array
    {
        $evidence = Transaction::query()
            ->where('reference_type', Invoice::class)
            ->where('reference_id', $invoice->id)
            ->get();

        if ($evidence->isEmpty()) {
            return ['invoice' => $invoice, 'settlement_verdict' => 'unsettled', 'evidence' => $evidence];
        }

        $verified = $evidence->filter(fn (Transaction $t): bool => $this->verify($t)['verdict'] === 'verified');

        if ($verified->isNotEmpty()) {
            return ['invoice' => $invoice, 'settlement_verdict' => 'verified', 'evidence' => $evidence];
        }

        return ['invoice' => $invoice, 'settlement_verdict' => 'unsupported', 'evidence' => $evidence];
    }

    /**
     * @return array<string, string>
     */
    public static function verdictGlossary(): array
    {
        return self::VERDICTS;
    }

    /**
     * @return array{state:string, reachable:bool, block:?int, tx:?array<string,mixed>}
     */
    private function viaChain(string $hash): array
    {
        try {
            $tx = $this->arc->rpc('eth_getTransactionByHash', [$hash]);
            $receipt = is_array($tx) && $tx !== []
                ? $this->arc->rpc('eth_getTransactionReceipt', [$hash])
                : null;
        } catch (Throwable) {
            return ['state' => 'unknown', 'reachable' => false, 'block' => null, 'tx' => null];
        }

        if (! is_array($tx) || $tx === []) {
            return ['state' => 'unknown', 'reachable' => true, 'block' => null, 'tx' => null];
        }

        $block = ChainQuantity::toInt($tx['blockNumber'] ?? ($receipt['blockNumber'] ?? null));

        // No receipt yet means the transaction is still in flight. Inclusion in
        // a block is not settlement, so this never reads as verified.
        if (! is_array($receipt) || $receipt === []) {
            return ['state' => 'pending', 'reachable' => true, 'block' => $block, 'tx' => $tx];
        }

        $succeeded = ChainQuantity::toInt($receipt['status'] ?? null) === 1;

        return [
            'state' => $succeeded ? 'settled' : 'reverted',
            'reachable' => true,
            'block' => ChainQuantity::toInt($receipt['blockNumber'] ?? null) ?? $block,
            'tx' => $receipt,
        ];
    }

    private function viaCircle(?string $address, string $hash): ?bool
    {
        if ($address === null) {
            return null;
        }

        try {
            $records = $this->wallets->transactions($address, ['limit' => 200]);
        } catch (Throwable) {
            return null;
        }

        return array_any($records, fn (TransactionRecord $record): bool => $record->txHash !== null && strcasecmp($record->txHash, $hash) === 0);
    }

    /**
     * @param  array<string,mixed>  $onChain
     * @return array{verdict:string, checked:string, reason:string, block:?int, on_chain:?array<string,mixed>, chain_source:string}
     */
    private function result(Transaction $transaction, string $verdict, string $reason, ?int $block = null, ?array $onChain = null, string $source = 'rpc'): array
    {
        return [
            'verdict' => $verdict,
            'checked' => $transaction->provider_tx_hash ?? '(none)',
            'reason' => $reason,
            'block' => $block,
            'on_chain' => $onChain,
            'chain_source' => $source,
        ];
    }
}
