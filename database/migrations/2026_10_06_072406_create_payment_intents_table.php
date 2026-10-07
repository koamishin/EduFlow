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
        Schema::create('payment_intents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('intent_key')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->foreignId('budget_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->string('portion', 32)->default('full');
            $table->string('status', 32)->default('draft');
            $table->string('currency', 10)->default('USDC');
            $table->bigInteger('amount_base_units');
            $table->bigInteger('max_fee_base_units');
            $table->string('chain', 16);
            $table->unsignedBigInteger('chain_id');
            $table->string('source_address', 42);
            $table->string('recipient_address', 42);
            $table->string('provider_idempotency_key', 80)->unique();
            $table->char('snapshot_digest', 64);
            $table->json('snapshot');
            $table->timestamps();
            $table->unique(['organization_id', 'invoice_id', 'portion'], 'payment_intents_document_portion_unique');
            $table->index(['organization_id', 'status']);
        });

        if (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $operation) {
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

            return;
        }

        DB::statement("ALTER TABLE payment_intents ADD CONSTRAINT payment_intents_bounds_check
            CHECK (amount_base_units > 0 AND max_fee_base_units >= 0
                AND amount_base_units <= 9223372036854775807 - max_fee_base_units
                AND status = 'draft' AND currency = 'USDC' AND portion = 'full'
                AND ((chain = 'ARC' AND chain_id = 5042) OR (chain = 'ARC-TESTNET' AND chain_id = 5042002)))");
    }

    public function down(): void
    {
        if (Schema::hasTable('payment_intents') && DB::table('payment_intents')->exists()) {
            throw new RuntimeException('Payment intent evidence exists; rollback requires reviewed archival and a forward migration.');
        }

        Schema::dropIfExists('payment_intents');
    }
};
