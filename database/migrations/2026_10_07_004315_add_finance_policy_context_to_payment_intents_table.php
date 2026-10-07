<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_intents', function (Blueprint $table): void {
            // Existing drafts remain unapproved; never infer reviewed policy context from current settings.
            $table->foreignId('finance_policy_version_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('finance_policy_activation_id')->nullable()->constrained()->restrictOnDelete();
            $table->index(['organization_id', 'finance_policy_activation_id'], 'payment_intent_policy_context_index');
        });

        $this->restoreSqliteBounds();
    }

    public function down(): void
    {
        if (DB::table('payment_intents')->whereNotNull('finance_policy_version_id')->orWhereNotNull('finance_policy_activation_id')->exists()) {
            throw new RuntimeException('Payment policy evidence exists; rollback requires reviewed archival and a forward migration.');
        }

        Schema::table('payment_intents', function (Blueprint $table): void {
            $table->dropIndex('payment_intent_policy_context_index');
            $table->dropForeign(['finance_policy_version_id']);
            $table->dropForeign(['finance_policy_activation_id']);
            $table->dropColumn(['finance_policy_version_id', 'finance_policy_activation_id']);
        });

        $this->restoreSqliteBounds();
    }

    private function restoreSqliteBounds(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        // SQLite rebuilds tables to alter foreign keys and does not preserve custom triggers.
        foreach (['INSERT', 'UPDATE'] as $operation) {
            DB::unprepared("DROP TRIGGER IF EXISTS payment_intents_bounds_{$operation}");
            DB::unprepared("CREATE TRIGGER payment_intents_bounds_{$operation}
                BEFORE {$operation} ON payment_intents
                WHEN typeof(NEW.amount_base_units) != 'integer' OR typeof(NEW.max_fee_base_units) != 'integer'
                    OR typeof(NEW.chain_id) != 'integer'
                    OR NEW.amount_base_units <= 0 OR NEW.max_fee_base_units < 0
                    OR NEW.amount_base_units > 9223372036854775807 - NEW.max_fee_base_units
                    OR NEW.status != 'draft' OR NEW.currency != 'USDC' OR NEW.portion != 'full'
                    OR NOT ((NEW.chain = 'ARC' AND NEW.chain_id = 5042)
                        OR (NEW.chain = 'ARC-TESTNET' AND NEW.chain_id = 5042002))
                BEGIN SELECT RAISE(ABORT, 'Invalid payment draft bounds or state'); END");
        }
    }
};
