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
        Schema::table('student_wallets', function (Blueprint $table): void {
            $table->dropUnique(['student_id']);
            $table->index(['student_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('student_wallets', function (Blueprint $table): void {
            $table->dropIndex(['student_id', 'status']);
        });

        // Retain only the latest active wallet row per student so restoring the unique
        // constraint never encounters duplicate keys on historical rows.
        $duplicates = DB::table('student_wallets')
            ->select('student_id')
            ->groupBy('student_id')
            ->havingRaw('count(*) > 1')
            ->pluck('student_id');

        foreach ($duplicates as $studentId) {
            $keepId = DB::table('student_wallets')
                ->where('student_id', $studentId)
                ->where('status', 'active')
                ->orderByDesc('id')
                ->value('id') ?? DB::table('student_wallets')
                ->where('student_id', $studentId)
                ->orderByDesc('id')
                ->value('id');

            DB::table('student_wallets')
                ->where('student_id', $studentId)
                ->where('id', '!=', $keepId)
                ->delete();
        }

        Schema::table('student_wallets', function (Blueprint $table): void {
            $table->unique('student_id');
        });
    }
};
