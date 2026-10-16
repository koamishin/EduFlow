<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Services\LeptonReconciliationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Audits stored receipts against Arc.
 *
 * `--fix` marks a row failed only when the chain itself reports failure. Rows
 * the chain cannot find are reported and left alone: a missing lookup is an
 * open question, not a verdict, and writing FAILED on it would replace one
 * unproven claim with another.
 */
#[Signature('lepton:reconcile {--limit=200 : Maximum transactions to check} {--json : Machine-readable output} {--fix : Mark chain-reverted receipts as failed}')]
#[Description('Prove which recorded transactions actually settled on Arc.')]
class LeptonReconcileCommand extends Command
{
    public function handle(LeptonReconciliationService $reconciler): int
    {
        $report = $reconciler->verifyAll(limit: (int) $this->option('limit'));

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'verdict' => $report['verdict'],
                'total' => $report['total'],
                'glossary' => LeptonReconciliationService::verdictGlossary(),
                ...array_intersect_key($report, array_flip(['verified', 'pending', 'reverted', 'unresolved', 'observed', 'unverifiable', 'ledger_only'])),
                'items' => $report['items']->map(fn (array $i): array => [
                    'id' => $i['transaction']->id,
                    'type' => $i['transaction']->type instanceof TransactionType ? $i['transaction']->type->value : (string) $i['transaction']->type,
                    'amount' => $this->exactAmount($i['transaction']),
                    'hash' => $i['checked'],
                    'verdict' => $i['verdict'],
                    'block' => $i['block'],
                    'reason' => $i['reason'],
                ])->all(),
            ], JSON_PRETTY_PRINT));

            return $report['verdict'] === 'clean' ? self::SUCCESS : self::FAILURE;
        }

        $this->newLine();
        $this->line('  Arc settlement reconciliation');
        $this->line('  '.str_repeat('─', 72));

        $symbols = [
            'verified' => '<fg=green>✓</>',
            'pending' => '<fg=cyan>~</>',
            'reverted' => '<fg=red>✗</>',
            'unresolved' => '<fg=yellow>?</>',
            'observed' => '<fg=yellow>◐</>',
            'unverifiable' => '<fg=yellow>?</>',
            'ledger_only' => '<fg=gray>-</</>',
        ];

        foreach ($report['items'] as $item) {
            /** @var Transaction $transaction */
            $transaction = $item['transaction'];
            $type = $transaction->type instanceof TransactionType ? $transaction->type->value : (string) $transaction->type;

            $this->line(sprintf(
                '  %s  #%-4d %-19s %16s  %-12s %s',
                $symbols[$item['verdict']] ?? '?',
                $transaction->id,
                $type,
                $this->exactAmount($transaction),
                $item['verdict'],
                substr((string) $item['checked'], 0, 22),
            ));
        }

        $this->newLine();
        $this->line(sprintf(
            '  %d verified · %d reverted · %d unresolved · %d pending · %d provider-only · %d unverifiable · %d ledger-only',
            $report['verified'],
            $report['reverted'],
            $report['unresolved'],
            $report['pending'],
            $report['observed'],
            $report['unverifiable'],
            $report['ledger_only'],
        ));
        $this->line('  '.str_repeat('─', 72));

        $reverted = $report['items']->where('verdict', 'reverted');
        $unfixed = $reverted->reject(fn (array $item): bool => $item['transaction']->isReconciledFailed());
        $alreadyFixed = $reverted->count() - $unfixed->count();

        if ($unfixed->isNotEmpty() && ! $this->option('fix')) {
            $this->newLine();
            $this->line('  <fg=red>The chain accepted these transactions and their receipts report failure.</>');
            $this->line('  <fg=gray>Mark them failed with:</>');
            $this->line('  <fg=gray>php artisan lepton:reconcile --fix</>');
        }

        if ($report['unresolved'] > 0) {
            $this->newLine();
            $this->line('  <fg=yellow>'.$report['unresolved'].' receipt(s) were not found on the chain. That is not proof they never settled:</>');
            $this->line('  <fg=yellow>propagation delay, reorganisation and a hash from another network look identical.</>');
            $this->line('  <fg=yellow>Investigate each one. This command deliberately leaves them untouched.</>');
        }

        if ($report['unverifiable'] > 0) {
            $this->newLine();
            $this->line('  <fg=yellow>Some receipts could not be checked. Ensure the Circle agent session is active.</>');
        }

        if ($this->option('fix') && $unfixed->isNotEmpty()) {
            $fixed = 0;

            foreach ($unfixed as $item) {
                /** @var Transaction $transaction */
                $transaction = $item['transaction'];

                $transaction->update([
                    'status' => TransactionStatus::FAILED,
                    'metadata' => array_merge($transaction->metadata ?? [], [
                        'reconciliation' => 'reverted',
                        'reconciled_at' => now()->toIso8601String(),
                        'reconciliation_note' => $item['reason'],
                    ]),
                ]);

                $fixed++;
            }

            $this->line(sprintf('  <fg=yellow>Marked %d reverted transaction(s) as failed.</>', $fixed));
        } elseif ($alreadyFixed > 0) {
            $this->line('  <fg=green>✓</> nothing left to fix.');
        }

        $this->newLine();

        return $report['verdict'] === 'clean' ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Render the amount as it is actually stored.
     *
     * `transactions.amount` is a two-decimal legacy column, so casting it to a
     * float here would print a rounded number that looks like an exact one.
     * Reading the raw decimal keeps the output honest about the precision the
     * column really has — the migration to six-decimal base units is tracked
     * separately and must not be faked at the display layer.
     */
    private function exactAmount(Transaction $transaction): string
    {
        $stored = trim((string) ($transaction->getRawOriginal('amount') ?? $transaction->amount));

        if ($stored === '') {
            return '—';
        }

        [$whole, $decimals] = array_pad(explode('.', $stored, 2), 2, '');

        return $whole.'.'.str_pad(substr($decimals, 0, 6), 6, '0');
    }
}
