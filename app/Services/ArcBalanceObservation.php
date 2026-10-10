<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Wallet;
use Brick\Math\BigInteger;
use Illuminate\Validation\ValidationException;
use Throwable;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Gateways\FakeLeptonGateway;
use Yukazakiri\Lepton\Support\Amounts;

/** Exact block-bound read; never updates the legacy wallet balance. */
final readonly class ArcBalanceObservation
{
    public function __construct(private ArcNetworkGateway $arc) {}

    /** @return array<string, mixed> */
    public function capture(Wallet $wallet): array
    {
        try {
            $chain = config('lepton.arc.chain');
            $chainId = config('lepton.arc.chain_id');
            if (! in_array([$chain, $chainId], [['ARC', 5042], ['ARC-TESTNET', 5042002]], true)
                || $this->arc->chainCode() !== $chain || $this->arc->chainId() !== $chainId
                || $this->quantity($this->arc->rpc('eth_chainId')) !== (string) $chainId) {
                $this->refuse();
            }
            $block = $this->arc->rpc('eth_getBlockByNumber', ['latest', false]);
            if (! is_array($block) || ! is_string($block['number'] ?? null) || ! is_string($block['hash'] ?? null)
                || preg_match('/^0x[0-9a-fA-F]{64}$/D', $block['hash']) !== 1) {
                $this->refuse();
            }
            $number = $this->quantity($block['number']);
            $timestamp = BigInteger::of($this->quantity($block['timestamp'] ?? null));
            if ($timestamp->isGreaterThan(now()->timestamp + 5) || $timestamp->isLessThan(now()->timestamp - 120)) {
                $this->refuse();
            }
            $native = $this->quantity($this->arc->rpc('eth_getBalance', [strtolower($wallet->address), $block['number']]));
            $confirm = $this->arc->rpc('eth_getBlockByNumber', [$block['number'], false]);
            if (! is_array($confirm) || ($confirm['hash'] ?? null) !== $block['hash'] || ($confirm['number'] ?? null) !== $block['number']) {
                $this->refuse();
            }
            $units = BigInteger::of($native);

            return ['chain' => $chain, 'chain_id' => $chainId, 'address' => strtolower($wallet->address),
                'block_number' => $number, 'block_hash' => strtolower($block['hash']), 'block_timestamp' => (string) $timestamp,
                'native_units' => $native, 'usdc_base_units' => (string) $units->quotient('1000000000000'),
                'native_residual_units' => (string) $units->remainder('1000000000000'),
                'observed_at' => now()->toIso8601String(), 'is_fake' => $this->arc instanceof FakeLeptonGateway || config('lepton.default') === 'fake'];
        } catch (Throwable $exception) {
            if ($exception instanceof ValidationException) {
                throw $exception;
            }
            throw ValidationException::withMessages(['funding' => 'Exact Arc balance observation unavailable; funding remains unverified.']);
        }
    }

    private function quantity(mixed $value): string
    {
        if (! is_string($value) || preg_match('/^0x(?:0|[1-9a-fA-F][0-9a-fA-F]{0,63})$/D', $value) !== 1) {
            $this->refuse();
        }

        return Amounts::fromHexQuantity($value, 0);
    }

    private function refuse(): never
    {
        throw ValidationException::withMessages(['funding' => 'Arc chain, fresh committed block and exact balance evidence must agree.']);
    }
}
