<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway as ArcNetworkGatewayContract;
use Yukazakiri\Lepton\Contracts\JsonTransport;
use Yukazakiri\Lepton\Support\Amounts;
use Yukazakiri\Lepton\Support\CliRunner;
use Yukazakiri\Lepton\Support\LeptonRuntimeException;

final class ArcNetworkGateway implements ArcNetworkGatewayContract
{
    private bool $networkVerified = false;

    public const MAINNET_RPC_URL = 'https://rpc.mainnet.arc.io';

    public const TESTNET_RPC_URL = 'https://rpc.testnet.arc.io';

    public const MAINNET_EXPLORER = 'https://explorer.arc.network';

    public const TESTNET_EXPLORER = 'https://testnet.arcscan.io';

    public const MAINNET_CHAIN_ID = 5040;

    public const TESTNET_CHAIN_ID = 5042;

    public const TESTNET_CHAIN_ID_ALT = 5042002;

    public function __construct(
        private readonly ?CliRunner $arc = null,
        private readonly string $chainCode = 'ARC-TESTNET',
        private readonly int $chainId = 5042,
        private readonly ?string $treasuryAddress = null,
        private readonly ?string $configuredRpcUrl = null,
        private readonly ?string $testnetExplorer = null,
        private readonly ?string $mainnetExplorer = null,
        private readonly int $timeout = 10,
        private readonly ?JsonTransport $transport = null,
    ) {}

    public function rpcUrl(): string
    {
        if (is_string($this->configuredRpcUrl) && $this->configuredRpcUrl !== '') {
            return $this->configuredRpcUrl;
        }

        return $this->defaultPublicRpcUrl();
    }

    public function defaultPublicRpcUrl(): string
    {
        return match ($this->chainCode) {
            'ARC', 'ARC-MAINNET' => self::MAINNET_RPC_URL,
            'ARC-TESTNET' => self::TESTNET_RPC_URL,
            default => match ($this->chainId) {
                self::MAINNET_CHAIN_ID => self::MAINNET_RPC_URL,
                default => self::TESTNET_RPC_URL,
            },
        };
    }

    public function chainCode(): string
    {
        return $this->chainCode;
    }

    public function chainId(): int
    {
        return $this->chainId;
    }

    public function blockNumber(): string
    {
        $res = $this->rpc('eth_blockNumber');

        return is_string($res) ? $res : '';
    }

    public function treasuryAddress(): ?string
    {
        return $this->treasuryAddress;
    }

    public function explorerUrl(string $txHash): string
    {
        return rtrim($this->explorerBase(), '/').'/tx/'.$txHash;
    }

    public function addressExplorerUrl(string $address): string
    {
        return rtrim($this->explorerBase(), '/').'/address/'.$address;
    }

    public static function isMainnetGateway(ArcNetworkGatewayContract $gateway): bool
    {
        if (method_exists($gateway, 'isMainnet')) {
            return $gateway->isMainnet();
        }

        return in_array($gateway->chainCode(), ['ARC', 'ARC-MAINNET'], true)
            || $gateway->chainId() === self::MAINNET_CHAIN_ID;
    }

    public function isMainnet(): bool
    {
        return in_array($this->chainCode, ['ARC', 'ARC-MAINNET'], true)
            || $this->chainId === self::MAINNET_CHAIN_ID;
    }

    private function explorerBase(): string
    {
        if ($this->isMainnet()) {
            return $this->mainnetExplorer
                ?: (string) config('lepton.explorer.mainnet', self::MAINNET_EXPLORER);
        }

        return $this->testnetExplorer
            ?: (string) config('lepton.explorer.testnet', self::TESTNET_EXPLORER);
    }

    public function rpc(string $method, array $params = []): mixed
    {
        if (! $this->networkVerified) {
            $network = $this->httpJsonRpc($this->rpcUrl(), 'eth_chainId', []);
            $actualChainId = is_string($network) ? (int) Amounts::fromHexQuantity($network, 0) : null;

            $validPairs = [
                ['ARC', 5040],
                ['ARC', 5040001],
                ['ARC-MAINNET', 5040],
                ['ARC', 5042],
                ['ARC-TESTNET', 5042],
                ['ARC-TESTNET', 5042002],
            ];

            $isTestnetMatch = in_array($this->chainId, [5042, 5042002], true) && in_array($actualChainId, [5042, 5042002], true);
            $isMainnetMatch = in_array($this->chainId, [5040, 5040001, 5042], true) && in_array($actualChainId, [5040, 5040001, 5042], true);
            $matchesConfigured = ($this->isMainnet() && $isMainnetMatch) || (! $this->isMainnet() && $isTestnetMatch);
            $isValidNetwork = in_array([$this->chainCode, $this->chainId], $validPairs, true);

            if (! is_string($network) || ! $matchesConfigured || ! $isValidNetwork) {
                throw new LeptonRuntimeException('Arc RPC chain ID does not match the configured network.');
            }

            $this->networkVerified = true;
            if ($method === 'eth_chainId') {
                return $network;
            }
        }

        return $this->httpJsonRpc($this->rpcUrl(), $method, $params);
    }

    private function httpJsonRpc(string $rpcUrl, string $method, array $params): mixed
    {
        if ($this->transport instanceof JsonTransport) {
            $payload = [
                'jsonrpc' => '2.0',
                'id' => random_int(1, 1000000),
                'method' => $method,
                'params' => $params,
            ];

            $response = $this->transport->request(
                'POST',
                $rpcUrl,
                ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
                $payload
            );
            $data = $response->data;
            if ($response->status !== 200 || ! is_array($data) || isset($data['error'])) {
                $errorMsg = is_array($data['error'] ?? null)
                    ? ($data['error']['message'] ?? 'JSON-RPC Error')
                    : (string) ($data['error'] ?? ('HTTP status '.$response->status));
                throw new LeptonRuntimeException("RPC error: {$errorMsg}");
            }

            return $data['result'] ?? null;
        }

        try {
            $res = Http::timeout($this->timeout)->post($rpcUrl, [
                'jsonrpc' => '2.0',
                'id' => random_int(1, 1000000),
                'method' => $method,
                'params' => $params,
            ]);

            if ($res->failed()) {
                throw new LeptonRuntimeException("Arc RPC HTTP error: status {$res->status()}");
            }

            $body = $res->json();
            if (isset($body['error'])) {
                $errorMsg = is_array($body['error']) ? ($body['error']['message'] ?? 'JSON-RPC Error') : (string) $body['error'];
                throw new LeptonRuntimeException("RPC error: {$errorMsg}");
            }

            return $body['result'] ?? null;
        } catch (Throwable $e) {
            if ($e instanceof LeptonRuntimeException) {
                throw $e;
            }
            throw new LeptonRuntimeException("Arc RPC failure: {$e->getMessage()}", 0, $e);
        }
    }
}
