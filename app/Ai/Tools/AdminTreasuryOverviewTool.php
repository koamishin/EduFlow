<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Models\Organization;
use App\Models\Transaction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

final readonly class AdminTreasuryOverviewTool implements Tool
{
    public function description(): string
    {
        return 'Read the institution treasury position, primary Circle wallet balance (USDC), reserve requirement, and recent payment settlements on Arc.';
    }

    public function handle(Request $request): string
    {
        try {
            $org = Organization::first();
            $wallet = $org?->primaryWallet();

            $recentTransactions = Transaction::query()->latest('id')->limit(5)->get()->map(fn (Transaction $tx): array => [
                'id' => $tx->id,
                'tx_hash' => $tx->provider_tx_hash,
                'type' => (string) ($tx->type?->value ?? $tx->type),
                'status' => (string) ($tx->status?->value ?? $tx->status),
                'amount_usdc' => $tx->amount,
                'recipient' => $tx->recipient_address,
                'created_at' => $tx->created_at?->toDateTimeString(),
            ])->all();

            return json_encode([
                'organization' => $org?->name ?? 'Default Organization',
                'wallet_address' => $wallet?->wallet_address ?? 'Not configured',
                'wallet_balance_usdc' => $wallet?->balance ?? '0.00',
                'minimum_reserve_usdc' => $org?->minimum_reserve ?? '0.00',
                'recent_transactions' => $recentTransactions,
            ], JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            report($e);

            return 'Error retrieving treasury overview: '.$e->getMessage();
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
