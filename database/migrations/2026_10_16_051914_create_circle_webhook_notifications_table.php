<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('circle_webhook_notifications', function (Blueprint $table): void {
            $table->id();
            $table->uuid('notification_id')->unique();
            $table->uuid('subscription_id');
            $table->string('notification_type', 100);
            $table->char('payload_digest', 64);
            $table->timestamp('provider_timestamp');
            $table->timestamp('received_at');
            $table->uuid('provider_transaction_id')->nullable()->index();
            $table->string('chain', 32)->nullable();
            $table->boolean('requires_observation')->default(false);
            $table->timestamp('observed_at')->nullable();
            $table->index(['requires_observation', 'observed_at'], 'circle_notification_recovery');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('circle_webhook_notifications')->exists()) {
            throw new RuntimeException('Retain notification replay identities; archive before rollback.');
        }
        Schema::dropIfExists('circle_webhook_notifications');
    }
};
