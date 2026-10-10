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
        Schema::create('payment_authorizations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_key')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_intent_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('payment_reservation_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->string('decision', 32);
            $table->string('mfa_method', 32);
            $table->char('factor_fingerprint', 64);
            $table->unsignedBigInteger('mfa_timestep');
            $table->json('snapshot');
            $table->char('snapshot_digest', 64);
            $table->timestamps();
            $table->unique(['reviewed_by', 'factor_fingerprint', 'mfa_timestep'], 'payment_authorizations_mfa_replay_unique');
            $table->index(['organization_id', 'decision']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('payment_authorizations') && DB::table('payment_authorizations')->exists()) {
            throw new RuntimeException('Payment authorization evidence exists; preserve history with a forward migration.');
        }
        Schema::dropIfExists('payment_authorizations');
    }
};
