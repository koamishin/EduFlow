<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
            $table->unique('student_id');
        });
    }
};
