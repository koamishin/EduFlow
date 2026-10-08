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
        Schema::create('vendor_destination_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->string('version', 64);
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->string('address', 42);
            $table->string('chain', 16);
            $table->unsignedBigInteger('chain_id');
            $table->string('control_evidence', 255);
            $table->char('content_digest', 64);
            $table->timestamps();
            $table->unique(['organization_id', 'vendor_id', 'version'], 'vendor_destination_version_unique');
        });
        Schema::create('vendor_destination_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_destination_version_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('previous_approval_id')->nullable()->constrained('vendor_destination_approvals')->restrictOnDelete();
            $table->char('previous_approval_digest', 64)->nullable();
            $table->string('verification_reference', 255);
            $table->char('destination_digest', 64);
            $table->char('approval_digest', 64);
            $table->timestamps();
            $table->unique(['organization_id', 'vendor_id', 'previous_approval_id'], 'vendor_destination_successor_unique');
            $table->index(['organization_id', 'vendor_id', 'id'], 'vendor_destination_current_index');
        });
        if (in_array(DB::getDriverName(), ['sqlite', 'pgsql'], true)) {
            DB::statement('CREATE UNIQUE INDEX vendor_destination_root_unique ON vendor_destination_approvals (organization_id, vendor_id) WHERE previous_approval_id IS NULL');
        }
        if (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                DB::unprepared("CREATE TRIGGER vendor_destination_chain_{$operation} BEFORE {$operation} ON vendor_destination_versions
                    WHEN typeof(NEW.chain_id) != 'integer' OR NOT ((NEW.chain = 'ARC' AND NEW.chain_id = 5042)
                        OR (NEW.chain = 'ARC-TESTNET' AND NEW.chain_id = 5042002))
                    BEGIN SELECT RAISE(ABORT, 'Invalid destination network identity'); END");
            }
        } else {
            DB::statement("ALTER TABLE vendor_destination_versions ADD CONSTRAINT vendor_destination_chain_check
                CHECK ((chain = 'ARC' AND chain_id = 5042) OR (chain = 'ARC-TESTNET' AND chain_id = 5042002))");
        }
    }

    public function down(): void
    {
        foreach (['vendor_destination_versions', 'vendor_destination_approvals'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Vendor destination evidence exists; use reviewed archival and a forward migration.');
            }
        }
        Schema::dropIfExists('vendor_destination_approvals');
        Schema::dropIfExists('vendor_destination_versions');
    }
};
