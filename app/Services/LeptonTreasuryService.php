<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Wallet;
use Illuminate\Support\Facades\Cache;
use Throwable;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway as ArcNetworkGatewayContract;
use Yukazakiri\Lepton\Gateways\FakeLeptonGateway;

/**
 * Live on-chain truth for the organization treasury.
 *
 * The database balance is EduFlow's own ledger. This service reads the chain
 * directly so operations screens never present a stale number as if it were
 * settled, and so the treasury row can be re-pointed at the real agent wallet.
 */
class LeptonTreasuryService
{
    public function __construct(
        private readonly ArcNetworkGatewayContract $arc,
    ) {}

    /**
     * Raw numeric values only. Formatting belongs to the presentation layer;
     * returning pre-formatted strings here caused `(float) "24,470.00"` to
     * silently parse as 24.0.
     *
     * @return array{
     *     chain:string, chain_id:int, block:?int, rpc_host:?string, driver:string,
     *     live_available:bool, onchain_balance:?float, ledger_balance:float,
     *     drift:?float, in_sync:?bool, treasury_address:?string,
     *     address_url:?string, is_fake:bool, error:?string
     * }
     */
    public function status(?Wallet $wallet = null, bool $fresh = false): array
    {
        $address = $wallet instanceof Wallet ? $wallet->address : $this->arc->treasuryAddress();
        $cacheKey = 'lepton_treasury_status_'.md5((string) $address);

        if (! $fresh && Cache::has($cacheKey)) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                if (isset($cached['address_url']) && is_string($cached['address_url'])) {
                    $cached['address_url'] = ArcNetworkGateway::normalizeExplorerUrl($cached['address_url']);
                }

                /** @var array{chain:string, chain_id:int, block:?int, rpc_host:?string, driver:string, live_available:bool, onchain_balance:?float, ledger_balance:float, drift:?float, in_sync:?bool, treasury_address:?string, address_url:?string, is_fake:bool, error:?string} $cached */
                return $cached;
            }
        }

        $result = $this->resolveStatus($wallet, $address);
        $result['address_url'] = ArcNetworkGateway::normalizeExplorerUrl($result['address_url']);

        Cache::put($cacheKey, $result, now()->addSeconds(30));

        return $result;
    }

    /**
     * @return array{
     *     chain:string, chain_id:int, block:?int, rpc_host:?string, driver:string,
     *     live_available:bool, onchain_balance:?float, ledger_balance:float,
     *     drift:?float, in_sync:?bool, treasury_address:?string,
     *     address_url:?string, is_fake:bool, error:?string
     * }
     */
    private function resolveStatus(?Wallet $wallet, ?string $address): array
    {
        $driver = (string) config('lepton.default', 'circle');
        $isFake = $this->arc instanceof FakeLeptonGateway;

        $ledger = $wallet ? (float) $wallet->balance : 0.0;

        $base = [
            'chain' => $this->arc->chainCode(),
            'chain_id' => $this->arc->chainId(),
            'block' => null,
            'rpc_host' => null,
            'driver' => $driver,
            'live_available' => false,
            'onchain_balance' => null,
            'ledger_balance' => $ledger,
            'drift' => null,
            'in_sync' => null,
            'treasury_address' => $address,
            'address_url' => null,
            'is_fake' => $isFake,
            'error' => null,
        ];

        if (! $address) {
            $base['error'] = 'No treasury address configured. Set LEPTON_TREASURY_ADDRESS.';

            return $base;
        }

        $base['address_url'] = $this->safe(fn (): string => $this->arc->addressExplorerUrl($address));

        $rpc = $this->safe(fn (): string => $this->arc->rpcUrl());
        if (is_string($rpc) && $rpc !== '') {
            $base['rpc_host'] = parse_url($rpc, PHP_URL_HOST) ?: $rpc;
        }

        try {
            $wei = $this->arc->rpc('eth_getBalance', [$address, 'latest']);
        } catch (Throwable $exception) {
            $base['error'] = $this->balanceReadError($exception);

            return $base;
        }

        $base['block'] = ChainQuantity::toInt($this->safe(fn (): string => $this->arc->blockNumber()));

        // Native Arc USDC is 18 decimals, unlike the 6-decimal Circle USDC.
        // String arithmetic: 20 USDC is 2e19 wei, which overflows a 64-bit int.
        $native = ChainQuantity::toDecimalString($wei, 18);

        if ($native === null) {
            // A `0x` we could see but not parse is a formatting fault; its
            // absence means the RPC never gave us a balance at all.
            $base['error'] = is_string($wei) && str_contains(strtolower($wei), '0x')
                ? 'Unreadable balance quantity from the RPC endpoint.'
                : 'RPC endpoint returned no usable balance. Check LEPTON_RPC_URL and the configured Arc network. No CLI login is required.';

            return $base;
        }

        $balance = (float) $native;

        $base['live_available'] = true;
        $base['onchain_balance'] = $balance;
        $base['drift'] = $ledger - $balance;
        $base['in_sync'] = abs($ledger - $balance) < 0.01;

        return $base;
    }

    private function balanceReadError(Throwable $exception): string
    {
        $message = $exception->getMessage();

        if ($message === 'Arc RPC chain ID does not match the configured network.') {
            return $message.' Check LEPTON_RPC_URL, LEPTON_CHAIN and LEPTON_CHAIN_ID.';
        }

        if (preg_match('/(?:HTTP error: status |HTTP status )(\d{3})/', $message, $matches) === 1) {
            return 'Arc RPC returned HTTP '.$matches[1].'. Check endpoint access and availability. No CLI login is required.';
        }

        if ($message === 'SDK HTTP endpoints must use HTTPS without embedded credentials.') {
            return 'Arc RPC URL must use HTTPS without embedded credentials. Check LEPTON_RPC_URL or ARC_TESTNET_RPC / ARC_MAINNET_RPC.';
        }

        if (str_contains($message, 'invalid JSON') || str_contains($message, 'unexpected JSON shape')) {
            return 'Arc RPC returned an invalid JSON response. Configure a JSON-RPC endpoint, not a REST API or explorer URL.';
        }

        if (str_contains($message, 'SDK HTTP transport failed')) {
            return 'Arc RPC HTTPS connection failed or timed out. Check DNS, TLS certificates and outbound connectivity from the PHP server.';
        }

        if (str_starts_with($message, 'RPC error:')) {
            return 'Arc RPC rejected the balance request. Check endpoint permissions and the treasury address. No CLI login is required.';
        }

        return 'Could not read the balance over HTTP JSON-RPC. Check LEPTON_RPC_URL and endpoint connectivity. No CLI login is required.';
    }

    public function forgetStatus(?Wallet $wallet = null): void
    {
        $address = $wallet instanceof Wallet ? $wallet->address : $this->arc->treasuryAddress();
        Cache::forget('lepton_treasury_status_'.md5((string) $address));
    }

    /**
     * Re-point the organization treasury at the configured Lepton agent wallet.
     */
    public function adoptConfiguredWallet(Wallet $wallet): bool
    {
        $configured = $this->arc->treasuryAddress();

        if (! $configured || strcasecmp($configured, $wallet->address) === 0) {
            return false;
        }

        $wallet->update(['address' => $configured, 'provider' => 'circle', 'network' => 'arc']);
        $this->forgetStatus($wallet);

        return true;
    }

    /**
     * Overwrite the ledger balance with the live on-chain figure.
     */
    public function syncBalance(Wallet $wallet): ?float
    {
        $status = $this->status($wallet, fresh: true);

        if (! $status['live_available'] || $status['onchain_balance'] === null) {
            return null;
        }

        $live = (float) $status['onchain_balance'];
        $wallet->update(['balance' => $live]);
        $this->forgetStatus($wallet);

        return $live;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|null
     */
    private function safe(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable) {
            return null;
        }
    }
}
