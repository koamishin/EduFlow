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
        Schema::create('finance_policy_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('version', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('currency', 10)->default('USDC');
            $table->bigInteger('minimum_reserve_base_units');
            $table->bigInteger('max_auto_payment_base_units')->default(0);
            $table->bigInteger('max_daily_disbursement_base_units')->default(0);
            $table->bigInteger('max_fee_base_units')->default(0);
            $table->char('content_digest', 64);
            $table->timestamps();
            $table->unique(['organization_id', 'version']);
        });

        Schema::create('finance_policy_activations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('finance_policy_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('previous_activation_id')->nullable()->constrained('finance_policy_activations')->restrictOnDelete();
            $table->char('previous_activation_digest', 64)->nullable();
            $table->char('policy_digest', 64);
            $table->char('activation_digest', 64);
            $table->timestamps();
            $table->unique(['organization_id', 'finance_policy_version_id'], 'finance_policy_activation_version_unique');
            $table->unique(['organization_id', 'previous_activation_id'], 'finance_policy_activation_successor_unique');
            $table->index(['organization_id', 'id']);
        });

        if (in_array(DB::getDriverName(), ['sqlite', 'pgsql'], true)) {
            DB::statement('CREATE UNIQUE INDEX finance_policy_activation_root_unique ON finance_policy_activations (organization_id) WHERE previous_activation_id IS NULL');
        }

        if (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                DB::unprepared("CREATE TRIGGER finance_policy_bounds_{$operation}
                    BEFORE {$operation} ON finance_policy_versions
                    WHEN typeof(NEW.minimum_reserve_base_units) != 'integer'
                        OR typeof(NEW.max_auto_payment_base_units) != 'integer'
                        OR typeof(NEW.max_daily_disbursement_base_units) != 'integer'
                        OR typeof(NEW.max_fee_base_units) != 'integer'
                        OR NEW.minimum_reserve_base_units < 0 OR NEW.max_auto_payment_base_units < 0
                        OR NEW.max_daily_disbursement_base_units < 0 OR NEW.max_fee_base_units < 0
                        OR NEW.max_auto_payment_base_units > NEW.max_daily_disbursement_base_units
                        OR NEW.currency != 'USDC'
                    BEGIN SELECT RAISE(ABORT, 'Invalid finance policy bounds'); END");
            }

            return;
        }

        DB::statement("ALTER TABLE finance_policy_versions ADD CONSTRAINT finance_policy_bounds_check
            CHECK (minimum_reserve_base_units >= 0 AND max_auto_payment_base_units >= 0
                AND max_daily_disbursement_base_units >= 0 AND max_fee_base_units >= 0
                AND max_auto_payment_base_units <= max_daily_disbursement_base_units AND currency = 'USDC')");
    }

    public function down(): void
    {
        foreach (['finance_policy_versions', 'finance_policy_activations'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Finance policy evidence exists; rollback requires reviewed archival and a forward migration.');
            }
        }
        Schema::dropIfExists('finance_policy_activations');
        Schema::dropIfExists('finance_policy_versions');
    }
};
