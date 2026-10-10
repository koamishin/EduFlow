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
        Schema::create('finance_workflow_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('budget_snapshot_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('collection_batch_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('kind', 32);
            $table->string('trigger_key', 160)->unique();
            $table->char('source_digest', 64);
            $table->string('state', 32)->default('queued');
            $table->unsignedInteger('attempts')->default(0);
            $table->json('result')->nullable();
            $table->char('result_digest', 64)->nullable();
            $table->string('last_error', 1000)->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'state']);
        });
        Schema::create('finance_plan_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('finance_workflow_run_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->string('decision', 32);
            $table->string('reason', 1000);
            $table->char('plan_digest', 64);
            $table->char('review_digest', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('finance_workflow_runs')->exists() || DB::table('finance_plan_reviews')->exists()) {
            throw new RuntimeException('Finance workflow evidence exists; use reviewed archival and a forward migration.');
        }
        Schema::dropIfExists('finance_plan_reviews');
        Schema::dropIfExists('finance_workflow_runs');
    }
};
