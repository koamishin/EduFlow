<?php

declare(strict_types=1);

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Throwable;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Support\Amounts;

/**
 * The strict predicate that decides whether a submitted payment actually settled.
 *
 * Finding a transaction is not proof. This service requires **execution success**
 * in a committed block **and** matching economic movement — chain, sender,
 * recipient and amount — before it will say `verified`. A hash that is merely
 * present proves nothing, and a hash that is absent proves nothing either.
 *
 * Every non-`verified` outcome is deliberately non-committal about fault:
 * `pending`, `dropped`, `not_found` and `unreadable` all mean "unresolved,
 * investigate", never "fabricated". Declaring fabrication from a null lookup is
 * the specific defect this replaces — a provider or RPC that cannot answer is
 * not evidence of wrongdoing.
 *
 * Arc exposes the same USDC through a native 18-decimal balance and a 6-decimal
 * ERC-20 interface, so a transfer may carry its amount either in `value` or in a
 * canonical Transfer log. Both are accepted; if neither matches, the verdict is
 * `mismatched` rather than a guess.
 */
final readonly class ArcSettlementVerifier
{
    /** Canonical ERC-20 Transfer event topic. */
    private const string TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    public function __construct(private ArcNetworkGateway $arc) {}

    /**
     * @param  array{chain:string, chain_id:int, sender:string, recipient:string, amount_base_units:int, max_fee_base_units:int}  $expected
     * @return array<string, mixed>
     */
    public function verify(array $expected, string $txHash): array
    {
        if (preg_match('/^0x[0-9a-fA-F]{64}$/D', $txHash) !== 1) {
            return $this->verdict('not_found', 'Provider reference is not a transaction hash.', null);
        }

        $receipt = $this->call('eth_getTransactionReceipt', [$txHash]);

        if ($receipt === 'unreadable') {
            return $this->verdict('unreadable', 'Chain reads failed. The outcome is unknown and requires investigation.', null);
        }

        if ($receipt === null) {
            return $this->classifyWithoutReceipt($txHash, $expected);
        }

        return $this->classifyReceipt($receipt, $expected, $txHash);
    }

    /**
     * @param  array<string, mixed>  $expected
     * @return array<string, mixed>
     */
    private function classifyWithoutReceipt(string $txHash, array $expected): array
    {
        $transaction = $this->call('eth_getTransactionByHash', [$txHash]);

        if ($transaction === 'unreadable') {
            return $this->verdict('unreadable', 'Chain reads failed while locating the submission. Outcome unknown.', null);
        }

        if ($transaction === null) {
            // Not being found is not proof of fabrication. A dropped or
            // never-propagated transaction looks exactly like this.
            return $this->verdict('not_found', 'Hash is well-formed but absent from the chain. Absence is not evidence of fabrication; reconcile against the provider before concluding anything.', null);
        }

        if (! is_array($transaction) || ! is_string($transaction['blockNumber'] ?? null)) {
            return $this->verdict('pending', 'Transaction is known but not yet included in a block. It may still settle.', null);
        }

        return $this->verdict('dropped', 'Transaction claims a block but has no receipt. Treated as unresolved, not settled and not fraudulent.', null);
    }

    /**
     * @param  array<string, mixed>  $receipt
     * @param  array<string, mixed>  $expected
     * @return array<string, mixed>
     */
    private function classifyReceipt(array $receipt, array $expected, string $txHash): array
    {
        $blockNumber = $receipt['blockNumber'] ?? null;

        if (! is_string($blockNumber) || preg_match('/^0x[0-9a-fA-F]+$/D', $blockNumber) !== 1) {
            return $this->verdict('pending', 'Receipt carries no committed block number yet.', $receipt);
        }

        // Inclusion in a block is not finality on its own: the block must be
        // committed, and execution must not have reverted.
        $block = $this->call('eth_getBlockByNumber', [$blockNumber, false]);
        if ($block === 'unreadable' || $block === null || ! is_array($block)) {
            return $this->verdict('unreadable', 'Included block could not be confirmed as committed. Outcome unresolved.', $receipt);
        }

        $status = is_string($receipt['status'] ?? null) ? strtolower($receipt['status']) : null;
        if ($status !== '0x1') {
            return $this->verdict('reverted', 'Execution did not succeed in the committed block. An included transaction can still revert.', $receipt);
        }

        $transaction = $this->call('eth_getTransactionByHash', [$txHash]);
        if ($transaction === 'unreadable' || ! is_array($transaction)) {
            return $this->verdict('unreadable', 'Receipt exists but its transaction could not be read for economic matching.', $receipt);
        }

        $mismatch = $this->economicMismatch($transaction, $receipt, $expected);
        if ($mismatch !== null) {
            return $this->verdict('mismatched', $mismatch, $receipt);
        }

        $feeBaseUnits = $this->actualFeeBaseUnits($receipt);
        if ($feeBaseUnits !== null && $feeBaseUnits > $expected['max_fee_base_units']) {
            return $this->verdict('fee_exceeded', sprintf(
                'Actual fee %s USDC exceeded the approved ceiling of %s USDC.',
                Amounts::toDecimalString($feeBaseUnits, 6),
                Amounts::toDecimalString($expected['max_fee_base_units'], 6),
            ), $receipt);
        }

        $explorer = $this->explorerUrl($txHash);

        return $this->verdict('verified', sprintf(
            'Execution succeeded in committed block %s with matching sender, recipient and amount. Fee %s USDC.',
            Amounts::fromHexQuantity($blockNumber, 0),
            $feeBaseUnits === null ? 'unreadable' : Amounts::toDecimalString($feeBaseUnits, 6),
        ), $receipt, [
            'block_hash' => $block['hash'] ?? null,
            'block_timestamp' => $block['timestamp'] ?? null,
            'actual_fee_base_units' => $feeBaseUnits,
            'explorer_url' => $explorer,
        ]);
    }

    /**
     * Compare the movement actually recorded against what was authorized.
     *
     * @param  array<string, mixed>  $transaction
     * @param  array<string, mixed>  $receipt
     * @param  array<string, mixed>  $expected
     */
    private function economicMismatch(array $transaction, array $receipt, array $expected): ?string
    {
        $from = is_string($transaction['from'] ?? null) ? strtolower($transaction['from']) : null;
        $to = is_string($transaction['to'] ?? null) ? strtolower($transaction['to']) : null;

        if ($from !== strtolower($expected['sender'])) {
            return 'Sender on chain does not match the authorized treasury.';
        }

        $native = $this->nativeValueMatches($transaction, $expected);
        $logged = $this->transferLogMatches($receipt, $expected);

        if ($native && $logged) {
            return 'Amount is present twice, as native value and as a Transfer log. Refusing to double-count.';
        }

        if (! $native && ! $logged) {
            return 'No native value or canonical Transfer log matches the authorized amount, recipient and asset.';
        }

        return null;
    }

    /**
     * Interpret 6-decimal USDC base units as their exact decimal value.
     *
     * Comparing an 18-decimal native reading against a raw base-unit integer
     * is an easy way to be off by a factor of a million, so both interfaces
     * convert through here instead.
     */
    private function authorizedAmount(int $baseUnits): BigDecimal
    {
        return BigDecimal::of($baseUnits)->withPointMovedLeft(6);
    }

    /**
     * Read a 0x-prefixed hex quantity as an exact decimal.
     *
     * Returns null for anything that is not a hex quantity. This is the one
     * place untrusted provider data is parsed defensively: a malformed reading
     * is "cannot confirm", never an error worth aborting a reconciliation run
     * over, and never something to be guessed at.
     */
    private function hexQuantity(string $quantity, int $decimals): ?BigDecimal
    {
        if (preg_match('/^0x[0-9a-fA-F]+$/D', $quantity) !== 1) {
            return null;
        }

        try {
            return BigDecimal::of(Amounts::fromHexQuantity($quantity, $decimals));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Native Arc USDC is 18 decimals; the authorized amount is 6. Compare as
     * exact decimals rather than strings so `25.000001` can never be rounded
     * down onto `25.000000`.
     *
     * @param  array<string, mixed>  $expected
     */
    private function nativeValueMatches(array $transaction, array $expected): bool
    {
        $value = $transaction['value'] ?? null;
        $to = $transaction['to'] ?? null;

        if (! is_string($value) || ! is_string($to)
            || strtolower($to) !== strtolower($expected['recipient'])) {
            return false;
        }

        $moved = $this->hexQuantity($value, 18);

        return $moved !== null && $moved->isEqualTo($this->authorizedAmount($expected['amount_base_units']));
    }

    /**
     * @param  array<string, mixed>  $receipt
     * @param  array<string, mixed>  $expected
     */
    private function transferLogMatches(array $receipt, array $expected): bool
    {
        $logs = $receipt['logs'] ?? null;

        if (! is_array($logs)) {
            return false;
        }

        foreach ($logs as $log) {
            // `topics` is a list; `data` is a single 32-byte hex payload, not
            // a list of its own.
            if (! is_array($log) || ! is_array($log['topics'] ?? null) || ! is_string($log['data'] ?? null)) {
                continue;
            }

            if (strtolower((string) ($log['topics'][0] ?? '')) !== self::TRANSFER_TOPIC || count($log['topics']) < 3) {
                continue;
            }

            $logFrom = '0x'.substr(strtolower((string) $log['topics'][1]), 26);
            $logTo = '0x'.substr(strtolower((string) $log['topics'][2]), 26);

            if ($logFrom !== strtolower($expected['sender']) || $logTo !== strtolower($expected['recipient'])) {
                continue;
            }

            $moved = $this->hexQuantity((string) $log['data'], 6);

            if ($moved !== null && $moved->isEqualTo($this->authorizedAmount($expected['amount_base_units']))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Gas fees are not transfer events and are paid in the native asset.
     *
     * @param  array<string, mixed>  $receipt
     */
    private function actualFeeBaseUnits(array $receipt): ?int
    {
        $gasUsed = $receipt['gasUsed'] ?? null;
        $price = $receipt['effectiveGasPrice'] ?? null;

        if (! is_string($gasUsed) || ! is_string($price)) {
            return null;
        }

        try {
            $gas = $this->hexQuantity($gasUsed, 0);
            $pricePerGas = $this->hexQuantity($price, 0);

            if ($gas === null || $pricePerGas === null) {
                return null;
            }

            // 18-decimal native value expressed in 6-decimal USDC base units,
            // with any sub-unit remainder dropped rather than rounded up.
            // `toInt()` is required: casting the quotient object directly
            // silently yields a wrong number, which would make every fee
            // ceiling comparison pass.
            return $gas->multipliedBy($pricePerGas)
                ->dividedBy(BigInteger::of('1000000000000'), 0, RoundingMode::Floor)
                ->toInt();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>|'unreadable'
     */
    private function call(string $method, array $params): array|null|string
    {
        try {
            $result = $this->arc->rpc($method, $params);
        } catch (Throwable) {
            return 'unreadable';
        }

        if (! is_array($result)) {
            return null;
        }

        return $result === [] ? null : $result;
    }

    private function explorerUrl(string $txHash): ?string
    {
        try {
            return $this->arc->explorerUrl($txHash);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function verdict(string $verdict, string $reason, ?array $evidence, array $extra = []): array
    {
        return [
            'verdict' => $verdict,
            // Only this one means money moved.
            'settled' => $verdict === 'verified',
            'reason' => $reason,
            'fabricated' => false,
            'requires_investigation' => ! in_array($verdict, ['verified', 'not_found'], true)
                || $verdict === 'not_found',
            'receipt' => $evidence,
            ...$extra,
        ];
    }
}
