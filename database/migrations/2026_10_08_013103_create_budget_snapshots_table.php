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
        Schema::create('budget_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->uuid('capture_key')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('budget_id')->constrained()->restrictOnDelete();
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->string('currency', 10);
            $table->json('snapshot');
            $table->char('snapshot_digest', 64);
            $table->timestamps();
            $table->index(['organization_id', 'budget_id']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('budget_snapshots') && DB::table('budget_snapshots')->exists()) {
            throw new RuntimeException('Budget evidence exists; use reviewed archival and a forward migration.');
        }
        Schema::dropIfExists('budget_snapshots');
    }
};
