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
        Schema::create('payment_intent_changes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_key')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_intent_id')->constrained()->restrictOnDelete();
            $table->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
            $table->string('kind', 16);
            $table->string('reason', 1000);
            $table->char('source_digest', 64);
            $table->uuid('replacement_intent_key')->nullable()->unique();
            $table->json('replacement_snapshot')->nullable();
            $table->char('content_digest', 64);
            $table->timestamps();
            $table->index(['organization_id', 'invoice_id']);
        });
        Schema::create('payment_intent_change_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_intent_change_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('payment_intent_id')->constrained()->restrictOnDelete();
            $table->foreignId('retired_payment_intent_id')->nullable()->unique()->constrained('payment_intents')->restrictOnDelete();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->string('decision', 32);
            $table->string('reason', 1000);
            $table->char('proposal_digest', 64);
            $table->char('review_digest', 64);
            $table->timestamps();
            $table->index(['organization_id', 'invoice_id']);
        });
        Schema::table('payment_intents', function (Blueprint $table): void {
            $table->unsignedInteger('revision')->default(0);
            $table->foreignId('predecessor_id')->nullable()->unique()->constrained('payment_intents')->restrictOnDelete();
            $table->foreignId('change_review_id')->nullable()->unique()->constrained('payment_intent_change_reviews')->restrictOnDelete();
            $table->unique(['organization_id', 'invoice_id', 'portion', 'revision'], 'payment_intents_document_revision_unique');
            $table->dropUnique('payment_intents_document_portion_unique');
        });
        $this->restoreSqliteBounds();
        $this->addRecoveryBounds();
    }

    public function down(): void
    {
        if (DB::table('payment_intent_changes')->exists() || DB::table('payment_intent_change_reviews')->exists()
            || DB::table('payment_intents')->where('revision', '!=', 0)->orWhereNotNull('predecessor_id')->orWhereNotNull('change_review_id')->exists()) {
            throw new RuntimeException('Payment recovery evidence exists; use reviewed archival and a forward migration.');
        }
        if (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                DB::unprepared("DROP TRIGGER IF EXISTS payment_intents_recovery_{$operation}");
            }
        } else {
            $drop = DB::getDriverName() === 'mysql' ? 'DROP CHECK' : 'DROP CONSTRAINT';
            DB::statement("ALTER TABLE payment_intents {$drop} payment_intents_recovery_check");
        }
        Schema::table('payment_intents', function (Blueprint $table): void {
            $table->dropUnique('payment_intents_document_revision_unique');
            $table->dropForeign(['predecessor_id']);
            $table->dropForeign(['change_review_id']);
            $table->dropUnique(['predecessor_id']);
            $table->dropUnique(['change_review_id']);
            $table->dropColumn(['revision', 'predecessor_id', 'change_review_id']);
            $table->unique(['organization_id', 'invoice_id', 'portion'], 'payment_intents_document_portion_unique');
        });
        Schema::drop('payment_intent_change_reviews');
        Schema::drop('payment_intent_changes');
        $this->restoreSqliteBounds();
    }

    private function addRecoveryBounds(): void
    {
        $checks = [
            'payment_intents' => 'revision >= 0 AND ((revision = 0 AND predecessor_id IS NULL AND change_review_id IS NULL)
                OR (revision > 0 AND predecessor_id IS NOT NULL AND change_review_id IS NOT NULL))',
            'payment_intent_changes' => "(kind = 'cancel' AND replacement_intent_key IS NULL AND replacement_snapshot IS NULL)
                OR (kind = 'replace' AND replacement_intent_key IS NOT NULL AND replacement_snapshot IS NOT NULL)",
            'payment_intent_change_reviews' => "(decision = 'approve_change' AND retired_payment_intent_id IS NOT NULL AND retired_payment_intent_id = payment_intent_id)
                OR (decision = 'reject' AND retired_payment_intent_id IS NULL)",
        ];
        foreach ($checks as $table => $check) {
            if (DB::getDriverName() !== 'sqlite') {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_recovery_check CHECK ({$check})");

                continue;
            }
            $newCheck = preg_replace('/\b(revision|predecessor_id|change_review_id|kind|replacement_intent_key|replacement_snapshot|decision|retired_payment_intent_id|payment_intent_id)\b/', 'NEW.$1', $check);
            if ($table === 'payment_intents') {
                $newCheck = "typeof(NEW.revision) = 'integer' AND ({$newCheck})";
            }
            foreach (['INSERT', 'UPDATE'] as $operation) {
                DB::unprepared("CREATE TRIGGER {$table}_recovery_{$operation} BEFORE {$operation} ON {$table}
                    WHEN NOT ({$newCheck}) BEGIN SELECT RAISE(ABORT, 'Invalid payment recovery state'); END");
            }
        }
    }

    private function restoreSqliteBounds(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }
        foreach (['INSERT', 'UPDATE'] as $operation) {
            DB::unprepared("DROP TRIGGER IF EXISTS payment_intents_bounds_{$operation}");
            DB::unprepared("CREATE TRIGGER payment_intents_bounds_{$operation} BEFORE {$operation} ON payment_intents
                WHEN typeof(NEW.amount_base_units) != 'integer' OR typeof(NEW.max_fee_base_units) != 'integer'
                    OR typeof(NEW.chain_id) != 'integer' OR NEW.amount_base_units <= 0 OR NEW.max_fee_base_units < 0
                    OR NEW.amount_base_units > 9223372036854775807 - NEW.max_fee_base_units
                    OR NEW.status != 'draft' OR NEW.currency != 'USDC' OR NEW.portion != 'full'
                    OR NOT ((NEW.chain = 'ARC' AND NEW.chain_id = 5042) OR (NEW.chain = 'ARC-TESTNET' AND NEW.chain_id = 5042002))
                BEGIN SELECT RAISE(ABORT, 'Invalid payment draft bounds or state'); END");
        }
    }
};
