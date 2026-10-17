<?php

declare(strict_types=1);

use App\DTOs\StudentWalletSnapshot;
use App\DTOs\WalletReadState;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Filament\Resources\Transactions\Tables\TransactionsTable;
use App\Filament\Widgets\LeptonNetworkWidget;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\ArcNetworkGateway as AppArcNetworkGateway;
use App\Services\LeptonTreasuryService;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\AuthGateway;
use Yukazakiri\Lepton\Contracts\JsonTransport;
use Yukazakiri\Lepton\DTOs\HttpResponse;

beforeEach(function (): void {
    config([
        'lepton.default' => 'fake',
        'lepton.arc.chain' => 'ARC-TESTNET',
        'lepton.arc.chain_id' => 5042002,
        'lepton.arc.treasury' => '0x7d41e0AA33A4850E20ECa993dF6F0403d6527C6E',
    ]);

    $this->org = Organization::create([
        'name' => 'Chain Truth Academy',
        'currency' => 'USDC',
        'minimum_reserve' => 10000.00,
        'max_auto_payment' => 1000.00,
        'max_daily_disbursement' => 5000.00,
        'human_approval_threshold' => 1000.00,
    ]);

    $this->wallet = Wallet::create([
        'organization_id' => $this->org->id,
        'provider' => 'circle',
        'network' => 'arc',
        'address' => '0x7d41e0AA33A4850E20ECa993dF6F0403d6527C6E',
        'balance' => 25420.00,
        'status' => 'active',
    ]);
});

test('status reports chain identity and explorer link for the configured treasury', function (): void {
    $status = app(LeptonTreasuryService::class)->status($this->wallet);

    expect($status['chain'])->toBe('ARC-TESTNET')
        ->and($status['chain_id'])->toBe(5042002)
        ->and($status['treasury_address'])->toBe('0x7d41e0AA33A4850E20ECa993dF6F0403d6527C6E')
        ->and($status['address_url'])->toBe('https://testnet.arcscan.app/address/0x7d41e0AA33A4850E20ECa993dF6F0403d6527C6E');
});

test('status never throws when the chain is unreachable and explains why', function (): void {
    $this->app->instance(ArcNetworkGateway::class, new class implements ArcNetworkGateway
    {
        public function rpcUrl(): string
        {
            throw new RuntimeException('no rpc');
        }

        public function chainCode(): string
        {
            return 'ARC-TESTNET';
        }

        public function chainId(): int
        {
            return 5042002;
        }

        public function blockNumber(): string
        {
            throw new RuntimeException('offline');
        }

        public function treasuryAddress(): string
        {
            return '0x7d41e0AA33A4850E20ECa993dF6F0403d6527C6E';
        }

        public function explorerUrl(string $txHash): string
        {
            return 'https://testnet.arcscan.app/tx/'.$txHash;
        }

        public function addressExplorerUrl(string $address): string
        {
            return 'https://testnet.arcscan.app/address/'.$address;
        }

        public function rpc(string $method, array $params = []): mixed
        {
            throw new RuntimeException('offline');
        }
    });

    $status = app(LeptonTreasuryService::class)->status($this->wallet);

    expect($status['live_available'])->toBeFalse()
        ->and($status['onchain_balance'])->toBeNull()
        ->and($status['block'])->toBeNull()
        ->and($status['error'])->toContain('HTTP JSON-RPC')
        ->and($status['error'])->toContain('No CLI login is required.');
});

test('status surfaces ledger drift instead of hiding it', function (): void {
    $this->app->instance(ArcNetworkGateway::class, new class implements ArcNetworkGateway
    {
        public function rpcUrl(): string
        {
            return 'https://rpc.testnet.arc-node.thecanteenapp.com/v1/abc';
        }

        public function chainCode(): string
        {
            return 'ARC-TESTNET';
        }

        public function chainId(): int
        {
            return 5042002;
        }

        public function blockNumber(): string
        {
            return '0x3d667a7';
        }

        public function treasuryAddress(): string
        {
            return '0x7d41e0AA33A4850E20ECa993dF6F0403d6527C6E';
        }

        public function explorerUrl(string $txHash): string
        {
            return 'https://testnet.arcscan.app/tx/'.$txHash;
        }

        public function addressExplorerUrl(string $address): string
        {
            return 'https://testnet.arcscan.app/address/'.$address;
        }

        public function rpc(string $method, array $params = []): mixed
        {
            // 5 native USDC at 18 decimals.
            return $method === 'eth_getBalance' ? '0x4563918244f40000' : null;
        }
    });

    $status = app(LeptonTreasuryService::class)->status($this->wallet);

    // Raw numerics, not pre-formatted strings: (float) "24,470.00" parses as 24.0.
    expect($status['live_available'])->toBeTrue()
        ->and($status['onchain_balance'])->toBe(5.0)
        ->and($status['ledger_balance'])->toBe(25420.0)
        ->and($status['block'])->toBe((int) hexdec('3d667a7'))
        ->and($status['rpc_host'])->toBe('rpc.testnet.arc-node.thecanteenapp.com')
        ->and($status['in_sync'])->toBeFalse()
        ->and($status['drift'])->toBe(25415.0);
});

test('syncBalance writes the live chain figure over the ledger', function (): void {
    $this->app->instance(ArcNetworkGateway::class, new class implements ArcNetworkGateway
    {
        public function rpcUrl(): string
        {
            return 'https://rpc.testnet.arc-node.thecanteenapp.com/v1/abc';
        }

        public function chainCode(): string
        {
            return 'ARC-TESTNET';
        }

        public function chainId(): int
        {
            return 5042002;
        }

        public function blockNumber(): string
        {
            return '0x1';
        }

        public function treasuryAddress(): string
        {
            return '0x7d41e0AA33A4850E20ECa993dF6F0403d6527C6E';
        }

        public function explorerUrl(string $txHash): string
        {
            return 'https://testnet.arcscan.app/tx/'.$txHash;
        }

        public function addressExplorerUrl(string $address): string
        {
            return 'https://testnet.arcscan.app/address/'.$address;
        }

        public function rpc(string $method, array $params = []): mixed
        {
            return $method === 'eth_getBalance' ? '0x4563918244f40000' : null;
        }
    });

    $live = app(LeptonTreasuryService::class)->syncBalance($this->wallet);

    expect($live)->toBe(5.0)
        ->and($this->wallet->fresh()->balance)->toBe(5.0);
});

test('syncBalance is a no-op when the chain cannot be read', function (): void {
    // A node that answers nothing usable for a balance read.
    $this->app->instance(ArcNetworkGateway::class, new class implements ArcNetworkGateway
    {
        public function rpcUrl(): string
        {
            throw new RuntimeException('offline');
        }

        public function chainCode(): string
        {
            return 'ARC-TESTNET';
        }

        public function chainId(): int
        {
            return 5042002;
        }

        public function blockNumber(): string
        {
            throw new RuntimeException('offline');
        }

        public function treasuryAddress(): ?string
        {
            return null;
        }

        public function explorerUrl(string $txHash): string
        {
            return 'https://testnet.arcscan.app/tx/'.$txHash;
        }

        public function addressExplorerUrl(string $address): string
        {
            return 'https://testnet.arcscan.app/address/'.$address;
        }

        public function rpc(string $method, array $params = []): mixed
        {
            return null;
        }
    });

    expect(app(LeptonTreasuryService::class)->syncBalance($this->wallet))->toBeNull()
        ->and($this->wallet->fresh()->balance)->toBe(25420.00);
});

test('treasury sync uses HTTP RPC without wallet authentication', function (string $quantity, float $expected): void {
    $auth = Mockery::mock(AuthGateway::class);
    $auth->shouldNotReceive('authStatus', 'isAuthenticatedFor', 'beginLogin', 'completeLogin');
    $this->app->instance(AuthGateway::class, $auth);

    $transport = Mockery::mock(JsonTransport::class);
    mockExpectation($transport, 'request')->times(3)->andReturnUsing(
        function (string $method, string $url, array $headers, ?array $body) use ($quantity): HttpResponse {
            expect($method)->toBe('POST')
                ->and($url)->toBe('https://configured.example.com/rpc');

            if ($body['method'] === 'eth_getBalance') {
                expect($body['params'])->toBe([$this->wallet->address, 'latest']);
            }

            return new HttpResponse(200, ['result' => match ($body['method']) {
                'eth_chainId' => '0x13b2',
                'eth_getBalance' => $quantity,
                'eth_blockNumber' => '0x10',
            }]);
        },
    );
    $this->app->instance(JsonTransport::class, $transport);
    config([
        'lepton.default' => 'developer',
        'lepton.arc.rpc_url' => null,
        'lepton.rpc.testnet' => 'https://configured.example.com/rpc',
    ]);
    $this->app->forgetInstance(ArcNetworkGateway::class);

    expect(app(LeptonTreasuryService::class)->syncBalance($this->wallet))->toBe($expected)
        ->and($this->wallet->fresh()->balance)->toBe($expected);
})->with([
    'funded wallet' => ['0x1158e460913d00000', 20.0],
    'empty wallet' => ['0x0', 0.0],
]);

test('failed HTTP RPC reads preserve the ledger and return safe diagnostics', function (HttpResponse|Throwable $response, string $error): void {
    $transport = Mockery::mock(JsonTransport::class);
    mockExpectation($transport, 'request')->andReturnUsing(
        static function (string $method, string $url, array $headers, ?array $body) use ($response): HttpResponse {
            if ($body['method'] === 'eth_chainId') {
                return new HttpResponse(200, ['result' => '0x13b2']);
            }

            if ($response instanceof Throwable) {
                throw $response;
            }

            return $response;
        },
    );
    $service = new LeptonTreasuryService(new AppArcNetworkGateway(
        chainCode: 'ARC-TESTNET',
        chainId: 5042,
        transport: $transport,
    ));

    expect($service->syncBalance($this->wallet))->toBeNull()
        ->and($this->wallet->fresh()->balance)->toBe(25420.0)
        ->and($service->status($this->wallet)['error'])->toContain($error)
        ->and($service->status($this->wallet)['error'])->not->toContain('private-endpoint-token');
})->with([
    'HTTP rejection' => [new HttpResponse(403), 'HTTP 403'],
    'RPC rejection' => [new HttpResponse(200, ['error' => ['message' => 'private-endpoint-token']]), 'RPC rejected'],
    'connection failure' => [new RuntimeException('https://example.com/private-endpoint-token'), 'endpoint connectivity'],
    'HTTPS policy' => [new RuntimeException('SDK HTTP endpoints must use HTTPS without embedded credentials.'), 'must use HTTPS'],
    'SDK connection failure' => [new RuntimeException('SDK HTTP transport failed; mutation outcome may be unknown.'), 'HTTPS connection failed or timed out'],
    'invalid JSON' => [new RuntimeException('SDK endpoint returned invalid JSON; mutation outcome may be unknown.'), 'invalid JSON response'],
    'unexpected JSON' => [new RuntimeException('SDK endpoint returned an unexpected JSON shape.'), 'invalid JSON response'],
    'missing result' => [new HttpResponse(200, []), 'no usable balance'],
    'malformed result' => [new HttpResponse(200, ['result' => ['private-endpoint-token']]), 'no usable balance'],
]);

test('arc gateway honors network-specific RPC configuration', function (string $chain, int $chainId, string $expected): void {
    config([
        'lepton.rpc.testnet' => 'https://testnet.example.com/rpc',
        'lepton.rpc.mainnet' => 'https://mainnet.example.com/rpc',
    ]);

    $gateway = new AppArcNetworkGateway(chainCode: $chain, chainId: $chainId);
    expect($gateway->rpcUrl())->toBe($expected);

    $override = new AppArcNetworkGateway(
        chainCode: $chain,
        chainId: $chainId,
        configuredRpcUrl: 'https://override.example.com/rpc',
    );
    expect($override->rpcUrl())->toBe('https://override.example.com/rpc');
})->with([
    'testnet' => ['ARC-TESTNET', 5042002, 'https://testnet.example.com/rpc'],
    'mainnet' => ['ARC-MAINNET', 5040, 'https://mainnet.example.com/rpc'],
]);

test('treasury sync rejects a balance from the wrong network', function (): void {
    $transport = Mockery::mock(JsonTransport::class);
    mockExpectation($transport, 'request')->once()->andReturn(new HttpResponse(200, ['result' => '0x13b0']));
    $service = new LeptonTreasuryService(new AppArcNetworkGateway(
        chainCode: 'ARC-TESTNET',
        chainId: 5042,
        transport: $transport,
    ));

    expect($service->syncBalance($this->wallet))->toBeNull()
        ->and($this->wallet->fresh()->balance)->toBe(25420.0)
        ->and($service->status($this->wallet)['error'])->toContain('chain ID does not match');
});

test('adoptConfiguredWallet repoints a placeholder treasury at the agent wallet', function (): void {
    $placeholder = Wallet::create([
        'organization_id' => $this->org->id,
        'provider' => 'circle',
        'network' => 'arc',
        'address' => '0x3a9B97F3dF02B418E97E1C7D6B9c7E67eB3682cA',
        'balance' => 1000.00,
        'status' => 'active',
    ]);

    $changed = app(LeptonTreasuryService::class)->adoptConfiguredWallet($placeholder);

    expect($changed)->toBeTrue()
        ->and($placeholder->fresh()->address)->toBe('0x7d41e0AA33A4850E20ECa993dF6F0403d6527C6E')
        ->and($placeholder->fresh()->balance)->toBe(1000.00);

    // Idempotent: already on the agent wallet.
    expect(app(LeptonTreasuryService::class)->adoptConfiguredWallet($placeholder->fresh()))->toBeFalse();
});

test('primaryWallet prefers the configured lepton treasury over stale rows', function (): void {
    Wallet::create([
        'organization_id' => $this->org->id,
        'provider' => 'circle',
        'network' => 'arc',
        'address' => '0x0000000000000000000000000000000000000001',
        'balance' => 5.00,
        'status' => 'active',
    ]);

    expect($this->org->primaryWallet()?->address)->toBe('0x7d41e0AA33A4850E20ECa993dF6F0403d6527C6E');
});

test('primaryWallet ignores inactive wallets', function (): void {
    $this->wallet->update(['status' => 'suspended']);
    config(['lepton.arc.treasury' => null]);

    expect($this->org->primaryWallet())->toBeNull();
});

test('a stored hash is labelled unverified until proven, never on-chain', function (): void {
    $tx = Transaction::create([
        'organization_id' => $this->org->id,
        'wallet_id' => $this->wallet->id,
        'type' => TransactionType::VENDOR_PAYMENT,
        'recipient_address' => '0xvendor',
        'amount' => 450.00,
        'status' => TransactionStatus::CONFIRMED,
        'provider_tx_hash' => '0x'.str_repeat('a', 64),
        'metadata' => ['gateway' => 'circle-cli', 'is_fake' => false],
    ]);

    // The database cannot vouch for itself: a hash alone must not read as settled.
    expect(TransactionsTable::settlementLabel($tx))->toBe('Unverified')
        ->and(TransactionsTable::settlementTooltip($tx))->toContain('a claim, not proof');

    $tx->update(['metadata' => array_merge($tx->metadata, [
        'reconciliation' => 'reverted',
        'reconciliation_note' => 'The chain reverted this transaction.',
    ])]);

    expect(TransactionsTable::settlementLabel($tx->fresh()))->toBe('Reverted on chain')
        ->and(TransactionsTable::settlementColor($tx->fresh()))->toBe('danger')
        ->and($tx->fresh()->isReconciledFailed())->toBeTrue();

    // A hash the chain could not find is a different claim, and it must never
    // read as a failed payment: absence of evidence is not evidence of absence.
    $tx->update(['metadata' => array_merge($tx->metadata, [
        'reconciliation' => 'unresolved',
        'reconciliation_note' => 'Hash absent from chain.',
    ])]);

    expect($tx->fresh()->isReconciledFailed())->toBeFalse()
        ->and(TransactionsTable::settlementLabel($tx->fresh()))->toBe('Unresolved')
        ->and(TransactionsTable::settlementColor($tx->fresh()))->toBe('warning')
        ->and(TransactionsTable::settlementTooltip($tx->fresh()))->toContain('not proof it never settled');

    $tx->update(['metadata' => array_merge($tx->metadata, ['reconciliation' => 'verified'])]);

    expect($tx->fresh()->isReconciledFailed())->toBeFalse();
});

test('transaction provenance distinguishes on-chain, simulated and ledger-only', function (): void {
    $onchain = Transaction::create([
        'organization_id' => $this->org->id,
        'wallet_id' => $this->wallet->id,
        'type' => TransactionType::VENDOR_PAYMENT,
        'recipient_address' => '0xvendor',
        'amount' => 450.00,
        'status' => TransactionStatus::CONFIRMED,
        'provider_tx_hash' => '0x'.str_repeat('a', 64),
        'metadata' => ['gateway' => 'circle-cli', 'is_fake' => false, 'explorer_url' => 'https://testnet.arcscan.app/tx/0xabc'],
    ]);

    $simulated = Transaction::create([
        'organization_id' => $this->org->id,
        'wallet_id' => $this->wallet->id,
        'type' => TransactionType::VENDOR_PAYMENT,
        'recipient_address' => '0xvendor',
        'amount' => 10.00,
        'status' => TransactionStatus::CONFIRMED,
        'provider_tx_hash' => '0x'.str_repeat('b', 64),
        'metadata' => ['gateway' => 'fake', 'is_fake' => true],
    ]);

    $ledger = Transaction::create([
        'organization_id' => $this->org->id,
        'wallet_id' => $this->wallet->id,
        'type' => TransactionType::TUITION_REVENUE,
        'recipient_address' => $this->wallet->address,
        'amount' => 10000.00,
        'status' => TransactionStatus::CONFIRMED,
        'provider_tx_hash' => null,
        'metadata' => ['simulated_inbound' => true],
    ]);

    expect(TransactionsTable::settlementLabel($onchain))->toBe('Unverified')
        ->and(TransactionsTable::settlementColor($onchain))->toBe('info')
        ->and(TransactionsTable::explorerUrl($onchain))->toBe('https://testnet.arcscan.app/tx/0xabc');

    expect(TransactionsTable::settlementLabel($simulated))->toBe('Simulated')
        ->and(TransactionsTable::settlementColor($simulated))->toBe('warning')
        ->and(TransactionsTable::settlementTooltip($simulated))->toContain('fake Lepton driver');

    expect(TransactionsTable::settlementLabel($ledger))->toBe('Ledger only')
        ->and(TransactionsTable::settlementColor($ledger))->toBe('gray')
        ->and(TransactionsTable::explorerUrl($ledger))->toBeNull();
});

test('explorer url falls back to the live gateway when metadata is absent', function (): void {
    $tx = Transaction::create([
        'organization_id' => $this->org->id,
        'wallet_id' => $this->wallet->id,
        'type' => TransactionType::VENDOR_PAYMENT,
        'recipient_address' => '0xvendor',
        'amount' => 1.00,
        'status' => TransactionStatus::CONFIRMED,
        'provider_tx_hash' => '0x'.str_repeat('c', 64),
        'metadata' => null,
    ]);

    expect(TransactionsTable::explorerUrl($tx))
        ->toBe('https://testnet.arcscan.app/tx/0x'.str_repeat('c', 64));
});

test('lepton network widget renders chain stats for the treasury', function (): void {
    $widget = new class extends LeptonNetworkWidget
    {
        /**
         * @return array<int, Stat>
         */
        public function exposedStats(): array
        {
            return $this->getStats();
        }
    };

    $labels = array_map(fn (Stat $stat): string => (string) $stat->getLabel(), $widget->exposedStats());

    expect($labels)->toContain('Arc Settlement Network')
        ->and($labels)->toContain('On-Chain Treasury')
        ->and($labels)->toContain('Agent Wallet')
        ->and($labels)->toContain('Lepton Driver');
});

test('arc network gateway and config default to resolving arcscan.app and explorer.arc.io domains', function (): void {
    expect(config('lepton.explorer_url'))->toBe('https://testnet.arcscan.app')
        ->and(config('lepton.explorer.testnet'))->toBe('https://testnet.arcscan.app')
        ->and(config('lepton.explorer.mainnet'))->toBe('https://explorer.arc.io');

    $testnetGateway = new AppArcNetworkGateway(chainCode: 'ARC-TESTNET', chainId: 5042);
    expect($testnetGateway->explorerUrl('0xabc'))->toBe('https://testnet.arcscan.app/tx/0xabc')
        ->and($testnetGateway->addressExplorerUrl('0xdef'))->toBe('https://testnet.arcscan.app/address/0xdef');

    $mainnetGateway = new AppArcNetworkGateway(chainCode: 'ARC-MAINNET', chainId: 5040);
    expect($mainnetGateway->explorerUrl('0xabc'))->toBe('https://explorer.arc.io/tx/0xabc')
        ->and($mainnetGateway->addressExplorerUrl('0xdef'))->toBe('https://explorer.arc.io/address/0xdef');

    // Legacy env/config overrides and stored metadata are normalized to live explorer domains
    config([
        'lepton.explorer_url' => 'https://testnet.arcscan.io',
        'lepton.explorer.testnet' => 'https://explorer.testnet.arc.network',
        'lepton.explorer.mainnet' => 'https://explorer.arc.network',
    ]);

    $legacyTestnetGateway = new AppArcNetworkGateway(chainCode: 'ARC-TESTNET', chainId: 5042002);
    expect($legacyTestnetGateway->explorerUrl('0x123'))->toBe('https://testnet.arcscan.app/tx/0x123')
        ->and($legacyTestnetGateway->addressExplorerUrl('0x456'))->toBe('https://testnet.arcscan.app/address/0x456');

    $legacyMainnetGateway = new AppArcNetworkGateway(chainCode: 'ARC-MAINNET', chainId: 5040);
    expect($legacyMainnetGateway->explorerUrl('0x123'))->toBe('https://explorer.arc.io/tx/0x123');

    $legacyTx = Transaction::create([
        'organization_id' => $this->org->id,
        'wallet_id' => $this->wallet->id,
        'type' => TransactionType::VENDOR_PAYMENT,
        'recipient_address' => '0xvendor',
        'amount' => 25.00,
        'status' => TransactionStatus::CONFIRMED,
        'provider_tx_hash' => '0x'.str_repeat('d', 64),
        'metadata' => ['gateway' => 'circle-cli', 'is_fake' => false, 'explorer_url' => 'https://testnet.arcscan.io/tx/0xlegacy'],
    ]);

    expect(TransactionsTable::explorerUrl($legacyTx))->toBe('https://testnet.arcscan.app/tx/0xlegacy');

    $snapshot = new StudentWalletSnapshot(
        state: WalletReadState::OK,
        address: '0x7d41e0AA33A4850E20ECa993dF6F0403d6527C6E',
        balance: '10.00',
        chain: 'ARC-TESTNET',
        explorerUrl: 'https://testnet.arcscan.io/address/0x7d41e0AA33A4850E20ECa993dF6F0403d6527C6E',
        isFake: false,
        reason: null,
        cachedAt: null,
    );

    expect($snapshot->toArray()['explorer_url'])->toBe('https://testnet.arcscan.app/address/0x7d41e0AA33A4850E20ECa993dF6F0403d6527C6E');
});
