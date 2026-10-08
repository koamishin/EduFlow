<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Models\AssistanceFund;
use App\Models\AssistanceRequest;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

final readonly class AdminAssistanceRequestsTool implements Tool
{
    public function description(): string
    {
        return 'Summarize financial assistance requests by status (pending, escalated, approved, disbursed, rejected), total assistance funds, and list recent requests.';
    }

    public function handle(Request $request): string
    {
        try {
            $status = $request['status'] ?? null;
            $limit = min(20, max(1, (int) ($request['limit'] ?? 10)));

            $builder = AssistanceRequest::query()->with(['user', 'student']);

            if (filled($status)) {
                $builder->where('status', (string) $status);
            }

            $countsByStatus = AssistanceRequest::query()
                ->selectRaw('status, count(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status')
                ->all();

            $funds = AssistanceFund::query()->where('status', 'active')->get()->map(fn (AssistanceFund $fund): array => [
                'name' => $fund->name,
                'balance_usdc' => $fund->balance_base_units / 1_000_000,
                'reserve_threshold_usdc' => $fund->reserve_threshold_base_units / 1_000_000,
            ])->all();

            $requests = $builder->latest('id')->limit($limit)->get()->map(fn (AssistanceRequest $ar): array => [
                'id' => $ar->id,
                'ticket_number' => $ar->ticket_number,
                'student_name' => $ar->user?->name ?? 'Unknown',
                'category' => (string) ($ar->category?->value ?? $ar->category),
                'priority' => (string) ($ar->priority?->value ?? $ar->priority),
                'status' => (string) ($ar->status?->value ?? $ar->status),
                'requested_amount' => $ar->requested_amount,
                'reason' => $ar->reason ?? $ar->description,
                'created_at' => $ar->created_at?->toDateTimeString(),
            ])->all();

            return json_encode([
                'counts_by_status' => $countsByStatus,
                'active_funds' => $funds,
                'requests' => $requests,
            ], JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            report($e);

            return 'Error retrieving assistance requests: '.$e->getMessage();
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->description('Optional filter by status, e.g. pending, escalated, approved, disbursed, rejected'),
            'limit' => $schema->integer()->description('Number of recent requests to return (1-20, default 10)'),
        ];
    }
}
