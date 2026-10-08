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
        Schema::create('invoice_versions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('capture_key')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('budget_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->string('source_currency', 10);
            $table->bigInteger('source_minor_units');
            $table->bigInteger('valuation_base_units');
            $table->string('status', 32)->default('captured');

            $table->json('snapshot');
            $table->char('snapshot_digest', 64);
            $table->timestamps();
            $table->index(['organization_id', 'budget_id']);
        });

        Schema::create('invoice_version_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_version_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->string('decision', 32);
            $table->string('reason', 1000);
            $table->char('bill_digest', 64);
            $table->char('review_digest', 64);
            $table->timestamps();
        });

        if (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                DB::unprepared("CREATE TRIGGER invoice_versions_bounds_{$operation} BEFORE {$operation} ON invoice_versions
                    WHEN typeof(NEW.source_minor_units) != 'integer' OR typeof(NEW.valuation_base_units) != 'integer'
                        OR NEW.source_minor_units <= 0 OR NEW.valuation_base_units <= 0
                        OR NEW.source_currency NOT IN ('USDC', 'USD', 'PHP', 'EUR', 'GBP', 'CAD', 'SGD', 'INR')
                        OR NEW.status != 'captured'
                    BEGIN SELECT RAISE(ABORT, 'Invalid invoice version bounds or state'); END");
                DB::unprepared("CREATE TRIGGER invoice_review_decision_{$operation} BEFORE {$operation} ON invoice_version_reviews
                    WHEN NEW.decision NOT IN ('approve_evidence', 'reject', 'hold')
                    BEGIN SELECT RAISE(ABORT, 'Invalid invoice evidence review decision'); END");
            }

            return;
        }

        DB::statement("ALTER TABLE invoice_versions ADD CONSTRAINT invoice_versions_bounds_check CHECK
            (source_minor_units > 0 AND valuation_base_units > 0 AND source_currency IN ('USDC', 'USD', 'PHP', 'EUR', 'GBP', 'CAD', 'SGD', 'INR')
                AND status = 'captured')");
        DB::statement("ALTER TABLE invoice_version_reviews ADD CONSTRAINT invoice_review_decision_check
            CHECK (decision IN ('approve_evidence', 'reject', 'hold'))");
    }

    public function down(): void
    {
        foreach (['invoice_versions', 'invoice_version_reviews'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Versioned invoice evidence exists; use reviewed archival and a forward migration.');
            }
        }
        Schema::dropIfExists('invoice_version_reviews');
        Schema::dropIfExists('invoice_versions');
    }
};
