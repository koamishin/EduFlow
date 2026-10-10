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
        Schema::create('payment_reviewer_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_key')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('reviewer_id')->unique()->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->string('mfa_method', 32);
            $table->char('factor_fingerprint', 64)->unique();
            $table->char('checker_factor_fingerprint', 64);
            $table->unsignedBigInteger('checker_timestep');
            $table->json('snapshot');
            $table->char('snapshot_digest', 64);
            $table->timestamps();
            $table->unique(['approved_by', 'checker_factor_fingerprint', 'checker_timestep'], 'reviewer_enrollment_checker_replay_unique');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('payment_authorizations') && DB::table('payment_authorizations')->exists()) {
            throw new RuntimeException('Payment authorizations depend on reviewer enrollment; preserve evidence with a forward migration.');
        }
        if (Schema::hasTable('payment_reviewer_enrollments') && DB::table('payment_reviewer_enrollments')->exists()) {
            throw new RuntimeException('Payment reviewer enrollment evidence exists; preserve history with a forward migration.');
        }
        Schema::dropIfExists('payment_reviewer_enrollments');
    }
};
