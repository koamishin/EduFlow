<?php

declare(strict_types=1);

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Filament\Resources\Transactions\Tables\TransactionsTable;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Models\Wallet;
use App\Services\LeptonReconciliationService;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\WalletGateway;
use Yukazakiri\Lepton\DTOs\BalanceResult;
use Yukazakiri\Lepton\DTOs\TransactionRecord;
use Yukazakiri\Lepton\DTOs\TransferResolution;
use Yukazakiri\Lepton\DTOs\TransferResult;

/**
 * Stubs a chain that knows about exactly one hash. Every other hash is absent,
 * which is the situation that let invented receipts pass unnoticed.
 *
 * `$receiptStatus` chooses the receipt the chain reports for the known hash:
 * 1 succeeded, 0 reverted, null still in flight with no receipt at all.
 */
function chainKnowing(string $knownHash, bool $reachable = true, ?int $receiptStatus = 1): ArcNetworkGateway
{
    return new class($knownHash, $reachable, $receiptStatus) implements ArcNetworkGateway
    {
        public function __construct(
            private readonly string $knownHash,
            private readonly bool $reachable,
            private readonly ?int $receiptStatus,
        ) {}

        public function rpcUrl(): string
        {
            return 'https://rpc.testnet.arc-node.thecanteenapp.com/v1/test';
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
            if (! $this->reachable) {
                throw new RuntimeException('offline');
            }

            if ($method === 'eth_getTransactionByHash') {
                return $params[0] === $this->knownHash
                    ? ['blockNumber' => '0x3d667a7', 'hash' => $this->knownHash]
                    : null;
            }

            if ($method === 'eth_getTransactionReceipt') {
                if ($params[0] !== $this->knownHash || $this->receiptStatus === null) {
                    return null;
                }

                return [
                    'blockNumber' => '0x3d667a7',
                    'status' => '0x'.dechex($this->receiptStatus),
                ];
            }

            return null;
        }
    };
}

/**
 * Agent wallet history containing only the hashes it is told about.
 *
 * @param  list<string>  $knownHashes
 */
function agentWalletKnowing(array $knownHashes = []): WalletGateway
{
    return new class($knownHashes) implements WalletGateway
    {
        /** @param list<string> $knownHashes */
        public function __construct(private readonly array $knownHashes) {}

        public function transfer(string $fromAddress, string $toAddress, int $amountBaseUnits, array $options = []): TransferResult
        {
            throw new RuntimeException('not used in reconciliation tests');
        }

        public function balance(string $address, array $options = []): BalanceResult
        {
            return new BalanceResult($address, 0, 'ARC-TESTNET', true, []);
        }

        public function transactions(string $address, array $filters = []): array
        {
            return array_map(
                fn (string $hash): TransactionRecord => new TransactionRecord('id-'.$hash, $hash, 'COMPLETE', 'TRANSFER', []),
                $this->knownHashes
            );
        }

        public function limits(string $address, array $options = []): array
        {
            return [];
        }

        public function resolve(string $handle, array $options = []): TransferResolution
        {
            throw new RuntimeException('not used in reconciliation tests');
        }

        /** @return array<int,array<string,mixed>> */
        public function wallets(array $options = []): array
        {
            throw new RuntimeException('not used in reconciliation tests');
        }

        /** @return array<string,mixed> */
        public function createWallet(array $options = []): array
        {
            throw new RuntimeException('not used in reconciliation tests');
        }

        /** @return array<string,mixed> */
        public function fundWallet(string $address, array $options = []): array
        {
            throw new RuntimeException('not used in reconciliation tests');
        }
    };
}

beforeEach(function (): void {
    config(['lepton.default' => 'fake', 'lepton.arc.chain' => 'ARC-TESTNET']);

    $this->org = Organization::create([
        'name' => 'Reconcile Academy',
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
        'address' => '0xabc',
        'balance' => 1000.00,
        'status' => 'active',
    ]);

    $this->realHash = '0x'.str_repeat('a', 64);
    $this->fakeHash = '0x'.str_repeat('b', 64);
});

function settleAgainst(ArcNetworkGateway $chain, WalletGateway $wallets): LeptonReconciliationService
{
    return new LeptonReconciliationService($chain, $wallets);
}

function payment(Organization $org, Wallet $wallet, ?string $hash, array $metadata = [], ?Invoice $for = null): Transaction
{
    return Transaction::create([
        'organization_id' => $org->id,
        'wallet_id' => $wallet->id,
        'type' => TransactionType::VENDOR_PAYMENT,
        'recipient_address' => '0xvendor',
        'amount' => 450.00,
        'status' => TransactionStatus::CONFIRMED,
        'provider_tx_hash' => $hash,
        'reference_type' => $for instanceof Invoice ? Invoice::class : null,
        'reference_id' => $for?->id,
        'metadata' => $metadata,
    ]);
}

function reconInvoice(Organization $org, string $reference, string $status = 'auto_paid'): Invoice
{
    $vendor = Vendor::create([
        'organization_id' => $org->id,
        'name' => 'Cloud Co',
        'wallet_address' => '0xvendor',
        'status' => 'verified',
        'risk_level' => 'low',
    ]);

    return Invoice::create([
        'organization_id' => $org->id,
        'vendor_id' => $vendor->id,
        'reference' => $reference,
        'amount' => 450.00,
        'due_date' => today(),
        'status' => $status,
    ]);
}

test('a hash with a successful on-chain receipt verifies with its block', function (): void {
    $tx = payment($this->org, $this->wallet, $this->realHash, ['is_fake' => false]);

    $result = settleAgainst(chainKnowing($this->realHash), agentWalletKnowing())->verify($tx);

    expect($result['verdict'])->toBe('verified')
        ->and($result['block'])->toBe((int) hexdec('3d667a7'))
        ->and($result['chain_source'])->toBe('rpc');
});

test('a hash the chain knows but has no receipt for is in flight, not settled', function (): void {
    $tx = payment($this->org, $this->wallet, $this->realHash, ['is_fake' => false]);

    $result = settleAgainst(chainKnowing($this->realHash, receiptStatus: null), agentWalletKnowing())->verify($tx);

    expect($result['verdict'])->toBe('pending')
        ->and($result['reason'])->toContain('still in flight');
});

test('a receipt the chain reports as failed is a proven discrepancy', function (): void {
    $tx = payment($this->org, $this->wallet, $this->realHash, ['is_fake' => false]);

    $result = settleAgainst(chainKnowing($this->realHash, receiptStatus: 0), agentWalletKnowing())->verify($tx);

    expect($result['verdict'])->toBe('reverted')
        ->and($result['reason'])->toContain('reverted');
});

test('a hash absent from a reachable chain is unresolved, never fabrication', function (): void {
    $tx = payment($this->org, $this->wallet, $this->fakeHash, ['is_fake' => true]);

    $result = settleAgainst(chainKnowing($this->realHash), agentWalletKnowing())->verify($tx);

    expect($result['verdict'])->toBe('unresolved')
        ->and($result['reason'])->toContain('Absence is not fabrication')
        ->and($result['reason'])->toContain('other network');
});

test('a transaction with no hash is a ledger entry, not a failure', function (): void {
    $tx = payment($this->org, $this->wallet, null, ['simulated_inbound' => true]);

    $result = settleAgainst(chainKnowing($this->realHash), agentWalletKnowing())->verify($tx);

    expect($result['verdict'])->toBe('ledger_only')
        ->and($result['reason'])->toContain('not a chain transfer');
});

test('when rpc is down the circle wallet history is provider observation only', function (): void {
    $tx = payment($this->org, $this->wallet, $this->fakeHash);

    $result = settleAgainst(chainKnowing('', reachable: false), agentWalletKnowing([$this->fakeHash]))->verify($tx);

    expect($result['verdict'])->toBe('observed')
        ->and($result['chain_source'])->toBe('circle')
        ->and($result['reason'])->toContain('not proof');
});

test('when rpc is down and circle does not know the hash it stays unresolved', function (): void {
    $tx = payment($this->org, $this->wallet, $this->fakeHash);

    $result = settleAgainst(chainKnowing('', reachable: false), agentWalletKnowing())->verify($tx);

    expect($result['verdict'])->toBe('unresolved')
        ->and($result['chain_source'])->toBe('circle');
});

test('verifyAll summarises the ledger honestly', function (): void {
    payment($this->org, $this->wallet, $this->realHash);
    payment($this->org, $this->wallet, $this->fakeHash);
    payment($this->org, $this->wallet, null, ['simulated_inbound' => true]);

    $report = settleAgainst(chainKnowing($this->realHash), agentWalletKnowing())->verifyAll();

    expect($report['total'])->toBe(3)
        ->and($report['verified'])->toBe(1)
        ->and($report['unresolved'])->toBe(1)
        ->and($report['ledger_only'])->toBe(1)
        ->and($report['reverted'])->toBe(0)
        ->and($report['verdict'])->toBe('unresolved');
});

test('verifyAll reports clean only when every receipt is proven', function (): void {
    payment($this->org, $this->wallet, $this->realHash);

    $report = settleAgainst(chainKnowing($this->realHash), agentWalletKnowing())->verifyAll();

    expect($report['verdict'])->toBe('clean')
        ->and($report['unresolved'])->toBe(0);
});

test('verifyAll reports a discrepancy only when the chain reverted a receipt', function (): void {
    payment($this->org, $this->wallet, $this->realHash);

    $report = settleAgainst(chainKnowing($this->realHash, receiptStatus: 0), agentWalletKnowing())->verifyAll();

    expect($report['verdict'])->toBe('discrepancies')
        ->and($report['reverted'])->toBe(1);
});

test('invoice settlement is unsupported when its payment never happened', function (): void {
    $invoice = reconInvoice($this->org, 'INV-RECON-450');

    // The invoice is marked auto_paid, but its payment never settled on chain.
    payment($this->org, $this->wallet, $this->fakeHash, [], $invoice);

    $result = settleAgainst(chainKnowing($this->realHash), agentWalletKnowing())
        ->verifyInvoiceSettlement($invoice);

    expect($result['settlement_verdict'])->toBe('unsupported')
        ->and($result['evidence'])->toHaveCount(1);
});

test('invoice settlement is verified once the payment has a successful receipt', function (): void {
    $invoice = reconInvoice($this->org, 'INV-RECON-OK');

    payment($this->org, $this->wallet, $this->realHash, ['is_fake' => false], $invoice);

    $result = settleAgainst(chainKnowing($this->realHash), agentWalletKnowing())->verifyInvoiceSettlement($invoice);

    expect($result['settlement_verdict'])->toBe('verified');
});

test('a provider-reported payment with no readable receipt does not settle an invoice', function (): void {
    $invoice = reconInvoice($this->org, 'INV-RECON-OBSERVED');

    payment($this->org, $this->wallet, $this->realHash, ['is_fake' => false], $invoice);

    // Chain unreachable, so all that exists is the provider's own claim.
    $result = settleAgainst(chainKnowing('', reachable: false), agentWalletKnowing([$this->realHash]))
        ->verifyInvoiceSettlement($invoice);

    expect($result['settlement_verdict'])->toBe('unsupported');
});

test('an invoice with no payment at all is unsettled', function (): void {
    $invoice = reconInvoice($this->org, 'INV-RECON-NONE', 'pending');

    $result = settleAgainst(chainKnowing($this->realHash), agentWalletKnowing())->verifyInvoiceSettlement($invoice);

    expect($result['settlement_verdict'])->toBe('unsettled');
});

test('--fix never fails a receipt merely because the chain cannot find it', function (): void {
    $this->app->instance(ArcNetworkGateway::class, chainKnowing($this->realHash));
    $this->app->instance(WalletGateway::class, agentWalletKnowing());

    $tx = payment($this->org, $this->wallet, $this->fakeHash, ['is_fake' => true]);

    $this->artisan('lepton:reconcile --fix')
        ->expectsOutputToContain('not proof they never settled')
        ->assertFailed();

    // An absent hash is an open question. Marking it FAILED would replace one
    // unproven claim with another and destroy the evidence of the gap.
    expect($tx->refresh()->status)->toBe(TransactionStatus::CONFIRMED)
        ->and($tx->isReconciledFailed())->toBeFalse()
        ->and($tx->metadata)->not->toHaveKey('reconciliation');
});

test('a chain-reverted receipt is only marked failed once, however often --fix runs', function (): void {
    $this->app->instance(ArcNetworkGateway::class, chainKnowing($this->realHash, receiptStatus: 0));
    $this->app->instance(WalletGateway::class, agentWalletKnowing());

    $tx = payment($this->org, $this->wallet, $this->realHash, ['is_fake' => false]);

    $this->artisan('lepton:reconcile --fix')->assertFailed();

    expect($tx->refresh()->status)->toBe(TransactionStatus::FAILED)
        ->and($tx->isReconciledFailed())->toBeTrue()
        ->and($tx->metadata['reconciliation'])->toBe('reverted');

    $firstStamp = $tx->metadata['reconciled_at'];

    // Re-running must not re-stamp or double-report the same row.
    $this->artisan('lepton:reconcile --fix')->assertFailed();

    expect($tx->refresh()->metadata['reconciled_at'])->toBe($firstStamp);
});

test('reconcile does not tell you to run --fix when you already did', function (): void {
    $this->app->instance(ArcNetworkGateway::class, chainKnowing($this->realHash, receiptStatus: 0));
    $this->app->instance(WalletGateway::class, agentWalletKnowing());

    $tx = payment($this->org, $this->wallet, $this->realHash, ['is_fake' => false]);
    $tx->update([
        'status' => TransactionStatus::FAILED,
        'metadata' => ['reconciliation' => 'reverted', 'reconciled_at' => '2026-01-01T00:00:00+00:00'],
    ]);

    $this->artisan('lepton:reconcile')
        ->expectsOutputToContain('nothing left to fix')
        ->assertFailed();

    $this->artisan('lepton:reconcile')
        ->doesntExpectOutputToContain('php artisan lepton:reconcile --fix')
        ->assertFailed();
});

test('reconcile suggests --fix while reverted rows are still unfixed', function (): void {
    $this->app->instance(ArcNetworkGateway::class, chainKnowing($this->realHash, receiptStatus: 0));
    $this->app->instance(WalletGateway::class, agentWalletKnowing());

    payment($this->org, $this->wallet, $this->realHash, ['is_fake' => false]);

    $this->artisan('lepton:reconcile')
        ->expectsOutputToContain('php artisan lepton:reconcile --fix')
        ->doesntExpectOutputToContain('nothing left to fix')
        ->assertFailed();
});

test('reconcile prints the stored amount without rounding it through a float', function (): void {
    $this->app->instance(ArcNetworkGateway::class, chainKnowing($this->realHash));
    $this->app->instance(WalletGateway::class, agentWalletKnowing());

    payment($this->org, $this->wallet, $this->realHash, ['is_fake' => false]);

    // The JSON report is one write, so exactly one substring can be asserted
    // against it. Success already proves the verdict was clean; this pins that
    // the amount is rendered at full USDC precision rather than rounded through
    // a float.
    $this->artisan('lepton:reconcile --json')
        ->expectsOutputToContain('450.000000')
        ->assertSuccessful();

    expect(LeptonReconciliationService::verdictGlossary())
        ->toHaveKeys(['verified', 'pending', 'reverted', 'unresolved', 'observed', 'unverifiable', 'ledger_only'])
        ->and(LeptonReconciliationService::verdictGlossary()['unresolved'])->toContain('Absence is not fabrication');
});

test('a reverted row is badged as chain-proven, an unresolved one as still open', function (): void {
    $reverted = payment($this->org, $this->wallet, $this->realHash, ['reconciliation' => 'reverted']);
    $unresolved = payment($this->org, $this->wallet, $this->fakeHash, ['reconciliation' => 'unresolved']);

    expect($reverted->isReconciledFailed())->toBeTrue()
        ->and(TransactionsTable::settlementLabel($reverted))->toBe('Reverted on chain')
        ->and(TransactionsTable::settlementColor($reverted))->toBe('danger')
        ->and(TransactionsTable::settlementTooltip($reverted))->toContain('reports failure')
        ->and(TransactionsTable::settlementLabel($unresolved))->toBe('Unresolved')
        ->and(TransactionsTable::settlementColor($unresolved))->toBe('warning')
        ->and(TransactionsTable::settlementTooltip($unresolved))->toContain('not proof it never settled');
});
