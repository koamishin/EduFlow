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
        Schema::create('funding_windows', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_key')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('budget_snapshot_id')->constrained()->restrictOnDelete();
            $table->foreignId('budget_id')->constrained()->restrictOnDelete();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->foreignId('finance_policy_activation_id')->constrained()->restrictOnDelete();
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->json('snapshot');
            $table->char('snapshot_digest', 64);
            $table->timestamps();
            $table->index(['organization_id', 'budget_id']);
        });
        Schema::create('funding_window_approvals', function (Blueprint $table): void {
            $table->id();
            // One institution-wide window until reviewed rollover/allocation semantics exist.
            $table->foreignId('organization_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('funding_window_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->string('verification_reference', 255);
            $table->char('window_digest', 64);
            $table->char('approval_digest', 64);
            $table->timestamps();
        });
        Schema::create('payment_reservations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('reservation_key')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_intent_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('funding_window_approval_id')->constrained()->restrictOnDelete();
            $table->foreignId('reserved_by')->constrained('users')->restrictOnDelete();
            $table->bigInteger('amount_base_units');
            $table->bigInteger('max_fee_base_units');
            $table->json('snapshot');
            $table->char('snapshot_digest', 64);
            $table->timestamps();
            $table->index(['organization_id', 'funding_window_approval_id'], 'reservations_window_index');
        });
        if (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                DB::unprepared("CREATE TRIGGER payment_reservations_bounds_{$operation} BEFORE {$operation} ON payment_reservations
                    WHEN typeof(NEW.amount_base_units) != 'integer' OR typeof(NEW.max_fee_base_units) != 'integer'
                        OR NEW.amount_base_units <= 0 OR NEW.max_fee_base_units < 0
                        OR NEW.amount_base_units > 9223372036854775807 - NEW.max_fee_base_units
                    BEGIN SELECT RAISE(ABORT, 'Invalid exact reservation bounds'); END");
            }
        } else {
            DB::statement('ALTER TABLE payment_reservations ADD CONSTRAINT payment_reservations_bounds_check
                CHECK (amount_base_units > 0 AND max_fee_base_units >= 0 AND amount_base_units <= 9223372036854775807 - max_fee_base_units)');
        }
    }

    public function down(): void
    {
        foreach (['payment_reservations', 'funding_window_approvals', 'funding_windows'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Funding or reservation evidence exists; use reviewed archival and a forward migration.');
            }
        }
        Schema::dropIfExists('payment_reservations');
        Schema::dropIfExists('funding_window_approvals');
        Schema::dropIfExists('funding_windows');
    }
};
