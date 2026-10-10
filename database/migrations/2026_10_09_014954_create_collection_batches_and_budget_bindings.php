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
        Schema::create('collection_batches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('capture_key')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->string('source_stream', 64);
            $table->string('source_reference', 120);
            $table->char('source_document_digest', 64);
            $table->string('currency', 10);
            $table->bigInteger('received_minor_units');
            $table->bigInteger('restricted_minor_units');
            $table->timestamp('collected_from');
            $table->timestamp('collected_until');
            $table->json('snapshot');
            $table->char('snapshot_digest', 64);
            $table->timestamps();
            $table->unique(['organization_id', 'source_stream', 'source_reference'], 'collections_source_reference_unique');
            $table->unique(['organization_id', 'source_document_digest'], 'collections_source_document_unique');
            $table->index(['organization_id', 'source_stream', 'collected_from', 'collected_until'], 'collections_source_interval_index');
        });
        Schema::create('collection_batch_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('collection_batch_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->string('decision', 32);
            $table->string('verification_reference', 255);
            $table->string('reason', 1000);
            $table->char('batch_digest', 64);
            $table->char('review_digest', 64);
            $table->timestamps();
            $table->index(['organization_id', 'decision']);
        });
        Schema::create('budget_snapshot_collection_review', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('budget_snapshot_id')->constrained()->restrictOnDelete();
            $table->foreignId('collection_batch_review_id')->constrained()->restrictOnDelete();
            $table->unique(['budget_snapshot_id', 'collection_batch_review_id'], 'budget_collection_binding_unique');
        });
        $bounds = "received_minor_units > 0 AND restricted_minor_units >= 0 AND restricted_minor_units <= received_minor_units
            AND collected_from < collected_until AND currency IN ('USDC', 'USD', 'PHP', 'EUR', 'GBP', 'CAD', 'SGD', 'INR')";
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE collection_batches ADD CONSTRAINT collection_batches_bounds_check CHECK ({$bounds})");
            DB::statement("ALTER TABLE collection_batch_reviews ADD CONSTRAINT collection_reviews_decision_check CHECK (decision IN ('approve_receipts', 'reject', 'hold'))");

            return;
        }
        foreach (['INSERT', 'UPDATE'] as $operation) {
            DB::unprepared("CREATE TRIGGER collection_batches_bounds_{$operation} BEFORE {$operation} ON collection_batches
                WHEN typeof(NEW.received_minor_units) != 'integer' OR typeof(NEW.restricted_minor_units) != 'integer'
                    OR NEW.received_minor_units <= 0 OR NEW.restricted_minor_units < 0 OR NEW.restricted_minor_units > NEW.received_minor_units
                    OR NEW.collected_from >= NEW.collected_until OR NEW.currency NOT IN ('USDC', 'USD', 'PHP', 'EUR', 'GBP', 'CAD', 'SGD', 'INR')
                BEGIN SELECT RAISE(ABORT, 'Invalid exact collection bounds'); END");
            DB::unprepared("CREATE TRIGGER collection_reviews_decision_{$operation} BEFORE {$operation} ON collection_batch_reviews
                WHEN NEW.decision NOT IN ('approve_receipts', 'reject', 'hold')
                BEGIN SELECT RAISE(ABORT, 'Invalid collection review decision'); END");
        }
    }

    public function down(): void
    {
        foreach (['budget_snapshot_collection_review', 'collection_batch_reviews', 'collection_batches'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Collection evidence exists; use reviewed archival and a forward migration.');
            }
        }
        Schema::dropIfExists('budget_snapshot_collection_review');
        Schema::dropIfExists('collection_batch_reviews');
        Schema::dropIfExists('collection_batches');
    }
};
