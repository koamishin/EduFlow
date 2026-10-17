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
        if (! Schema::hasTable('notifications')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE notifications ALTER COLUMN data TYPE jsonb USING CASE WHEN data IS NULL OR data = '' THEN '{}'::jsonb ELSE data::jsonb END");
        } elseif ($driver === 'mysql') {
            Schema::table('notifications', function (Blueprint $table): void {
                $table->json('data')->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE text USING data::text');
        } elseif ($driver === 'mysql') {
            Schema::table('notifications', function (Blueprint $table): void {
                $table->text('data')->change();
            });
        }
    }
};
