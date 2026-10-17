<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            $table->uuid('circle_user_id')->nullable()->unique();
        });
        Schema::table('student_wallets', function (Blueprint $table): void {
            $table->timestamp('ownership_verified_at')->nullable();
            $table->uuid('circle_user_id')->nullable()->unique();
            $table->uuid('circle_wallet_id')->nullable()->unique();
        });
        Schema::create('student_controlled_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_wallet_id')->constrained()->restrictOnDelete();
            $table->foreignId('tuition_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('student_bill_id')->nullable()->constrained()->restrictOnDelete();
            $table->json('snapshot');
            $table->string('snapshot_digest', 64);
            $table->string('tx_hash', 66)->nullable()->unique();
            $table->uuid('circle_challenge_id')->nullable();
            $table->json('evidence')->nullable();
            $table->unsignedBigInteger('applied_base_units')->default(0);
            $table->unsignedBigInteger('overpayment_base_units')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('approval_started_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_controlled_payments');
        Schema::table('students', function (Blueprint $table): void {
            $table->dropColumn('circle_user_id');
        });
        Schema::table('student_wallets', function (Blueprint $table): void {
            $table->dropColumn(['ownership_verified_at', 'circle_user_id', 'circle_wallet_id']);
        });
    }
};
