<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Models\TuitionAccount;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

final readonly class AdminTuitionAccountsTool implements Tool
{
    public function description(): string
    {
        return 'Summarize tuition accounts: total billed, total collected, remaining outstanding balance, and list accounts with balances.';
    }

    public function handle(Request $request): string
    {
        try {
            $limit = min(20, max(1, (int) ($request['limit'] ?? 10)));
            $onlyOutstanding = (bool) ($request['only_outstanding'] ?? true);

            $totalBilled = (int) TuitionAccount::sum('total_amount');
            $totalPaid = (int) TuitionAccount::sum('paid_amount');
            $totalRemaining = $totalBilled - $totalPaid;

            $builder = TuitionAccount::query()->with(['student.user', 'academicTerm']);

            if ($onlyOutstanding) {
                $builder->whereRaw('total_amount > paid_amount');
            }

            $accounts = $builder->latest('id')->limit($limit)->get()->map(fn (TuitionAccount $ta): array => [
                'id' => $ta->id,
                'student_name' => $ta->student?->user?->name ?? 'Unknown',
                'student_number' => $ta->student?->student_number ?? 'N/A',
                'term' => $ta->academicTerm?->name ?? 'Current',
                'total_amount' => $ta->total_amount,
                'paid_amount' => $ta->paid_amount,
                'remaining_balance' => $ta->remainingAmount(),
            ])->all();

            return json_encode([
                'total_billed' => $totalBilled,
                'total_paid' => $totalPaid,
                'total_remaining' => $totalRemaining,
                'accounts' => $accounts,
            ], JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            report($e);

            return 'Error retrieving tuition accounts: '.$e->getMessage();
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'only_outstanding' => $schema->boolean()->description('Filter to only accounts that have an unpaid balance (default true)'),
            'limit' => $schema->integer()->description('Number of account records to return (1-20, default 10)'),
        ];
    }
}
