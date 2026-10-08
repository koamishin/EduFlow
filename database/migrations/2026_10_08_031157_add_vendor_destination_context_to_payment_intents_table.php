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
        Schema::table('payment_intents', function (Blueprint $table): void {
            $table->foreignId('vendor_destination_version_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('vendor_destination_approval_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('invoice_version_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('invoice_version_review_id')->nullable()->constrained()->restrictOnDelete();
        });
        $this->restoreSqliteBounds();
    }

    public function down(): void
    {
        if (DB::table('payment_intents')->whereNotNull('vendor_destination_version_id')->orWhereNotNull('vendor_destination_approval_id')
            ->orWhereNotNull('invoice_version_id')->orWhereNotNull('invoice_version_review_id')->exists()) {
            throw new RuntimeException('Payment destination or invoice evidence exists; use reviewed archival and a forward migration.');
        }
        Schema::table('payment_intents', function (Blueprint $table): void {
            foreach (['vendor_destination_version_id', 'vendor_destination_approval_id', 'invoice_version_id', 'invoice_version_review_id'] as $column) {
                $table->dropForeign([$column]);
            }
            $table->dropColumn(['vendor_destination_version_id', 'vendor_destination_approval_id', 'invoice_version_id', 'invoice_version_review_id']);
        });
        $this->restoreSqliteBounds();
    }

    private function restoreSqliteBounds(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }
        foreach (['INSERT', 'UPDATE'] as $operation) {
            DB::unprepared("DROP TRIGGER IF EXISTS payment_intents_bounds_{$operation}");
            DB::unprepared("CREATE TRIGGER payment_intents_bounds_{$operation} BEFORE {$operation} ON payment_intents
                WHEN typeof(NEW.amount_base_units) != 'integer' OR typeof(NEW.max_fee_base_units) != 'integer'
                    OR typeof(NEW.chain_id) != 'integer' OR NEW.amount_base_units <= 0 OR NEW.max_fee_base_units < 0
                    OR NEW.amount_base_units > 9223372036854775807 - NEW.max_fee_base_units
                    OR NEW.status != 'draft' OR NEW.currency != 'USDC' OR NEW.portion != 'full'
                    OR NOT ((NEW.chain = 'ARC' AND NEW.chain_id = 5042) OR (NEW.chain = 'ARC-TESTNET' AND NEW.chain_id = 5042002))
                BEGIN SELECT RAISE(ABORT, 'Invalid payment draft bounds or state'); END");
        }
    }
};
