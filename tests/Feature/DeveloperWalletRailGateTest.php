<?php

declare(strict_types=1);

use App\Services\CircleAgentWalletCli;
use App\Services\Payments\CircleAgentWalletTransport;
use App\Services\Payments\FeeGuarantee;
use Yukazakiri\Lepton\Contracts\WalletGateway;
use Yukazakiri\Lepton\Gateways\CircleRestGateway;
use Yukazakiri\Lepton\Support\CliRunner;

/**
 * The developer REST rail is new, so it is gated rather than trusted.
 *
 * The agent relay was measured: Circle sponsored the gas, so the payer's debit was
 * the principal alone. A Developer-Controlled Wallet transfer is a different rail
 * with a different payer, and nothing in this repository has measured it. Carrying
 * the `RelayerSponsored` finding across would be an assumption about somebody
 * else's money, so execution refuses until payer fee controls are reviewed.
 */
beforeEach(function (): void {
    config([
        'lepton.default' => 'developer',
        'lepton.developer.api_key' => 'TEST_API_KEY:'.str_repeat('a', 36).':'.str_repeat('b', 40),
        'lepton.developer.entity_secret' => str_repeat('c', 64),
        'lepton.developer.wallet_set_id' => 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11',
        'lepton.arc.chain' => 'ARC-TESTNET',
    ]);
});

it('binds the developer driver to the REST gateway rather than the CLI', function (): void {
    expect(app(WalletGateway::class))->toBeInstanceOf(CircleRestGateway::class);
});

it('refuses to submit on a rail whose payer fee behaviour is unreviewed', function (): void {
    $transport = CircleAgentWalletTransport::fromConfig();

    expect($transport->feeGuarantee())->toBe(FeeGuarantee::Unbounded)
        ->and($transport->supportsFeeCeiling())->toBeFalse();

    // No network call may happen on the way to this refusal: nothing about the
    // amount, recipient or key is even read.
    expect(fn () => $transport->submit([
        'from' => '0x'.str_repeat('1', 40),
        'to' => '0x'.str_repeat('2', 40),
        'amount_base_units' => 1_000000,
        'chain' => 'ARC-TESTNET',
        'idempotency_key' => '01899cf2-d415-7052-a207-f9862157e546',
    ]))->toThrow(RuntimeException::class, 'remains gated until payer fee controls are reviewed');
});

it('still speaks the SDK wallet API after the package moved its gateways', function (): void {
    // Lepton v3 moved these classes from Lepton\Drivers\* to Lepton\Gateways\*
    // and renamed listWallets/createAgentWallet/fundTestnetWallet/estimateFee.
    // An `instanceof` against a class that no longer exists is silently false,
    // so a stale import does not fail loudly: it quietly routes these calls
    // down the CLI path instead. Exercise the REST branch for real.
    $gateway = new CircleRestGateway(
        apiKey: 'TEST_API_KEY:'.str_repeat('a', 36).':'.str_repeat('b', 40),
        walletSetId: 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11',
        httpClient: static function (string $method, string $url, array $query = [], ?array $body = null): array {
            if ($method === 'GET' && str_ends_with($url, '/wallets')) {
                return ['data' => ['wallets' => [[
                    'id' => '4f2b0c1a-1111-4222-8333-944455556666',
                    'address' => '0xABCDEF0123456789abcdef0123456789ABCDEF01',
                    'blockchain' => 'ARC-TESTNET',
                    'custodyType' => 'DEVELOPER',
                    'accountType' => 'EOA',
                    'walletSetId' => 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11',
                    'description' => 'fixture',
                    'creationDate' => '2026-10-16T00:00:00Z',
                    'updateDate' => '2026-10-16T00:00:00Z',
                ]]]];
            }

            return ['data' => ['low' => ['networkFee' => '29452', 'gasLimit' => '21000', 'maxFee' => '29452']]];
        },
    );

    $cli = new CircleAgentWalletCli(
        runner: new CliRunner('/nonexistent/circle'),
        wallets: $gateway,
    );

    expect($cli->agentWalletAddresses('ARC-TESTNET'))
        ->toBe(['0xabcdef0123456789abcdef0123456789abcdef01'])
        ->and($cli->estimateTransfer('0xabcdef0123456789abcdef0123456789abcdef01', '0x'.str_repeat('2', 40), 1_000000, 'ARC-TESTNET'))
        ->toMatchArray(['data' => ['estimatedFee' => ['networkFee' => '29452', 'gasLimit' => '21000', 'maxFee' => '29452']]]);
});
