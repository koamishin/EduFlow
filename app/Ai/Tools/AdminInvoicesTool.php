<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Models\Invoice;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

final readonly class AdminInvoicesTool implements Tool
{
    public function description(): string
    {
        return 'Summarize vendor invoices, pending payables, due dates, and payment statuses.';
    }

    public function handle(Request $request): string
    {
        try {
            $status = $request['status'] ?? null;
            $limit = min(20, max(1, (int) ($request['limit'] ?? 10)));

            $builder = Invoice::query()->with('vendor');

            if (filled($status)) {
                $builder->where('status', (string) $status);
            }

            $invoices = $builder->latest('due_date')->limit($limit)->get()->map(fn (Invoice $inv): array => [
                'id' => $inv->id,
                'reference' => $inv->reference,
                'vendor' => $inv->vendor?->name ?? 'Unknown',
                'amount_usdc' => $inv->amount,
                'status' => (string) ($inv->status?->value ?? $inv->status),
                'due_date' => $inv->due_date?->toDateString(),
            ])->all();

            $pendingCount = Invoice::where('status', 'pending')->count();
            $totalPendingAmount = Invoice::where('status', 'pending')->sum('amount');

            return json_encode([
                'pending_invoices_count' => $pendingCount,
                'total_pending_usdc' => $totalPendingAmount,
                'invoices' => $invoices,
            ], JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            report($e);

            return 'Error retrieving invoices: '.$e->getMessage();
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->description('Optional status filter, e.g. pending, paid, rejected'),
            'limit' => $schema->integer()->description('Number of records to return (1-20, default 10)'),
        ];
    }
}
