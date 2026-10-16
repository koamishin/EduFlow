<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('slug')->nullable()->unique()->after('name');
            $table->string('domain')->nullable()->after('slug');
            $table->string('country', 100)->nullable()->after('domain');
            $table->string('timezone')->nullable()->after('country');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        Schema::table('students', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->string('student_id_number')->nullable()->after('student_number');
            $table->string('department')->nullable()->after('program');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn(['student_id_number', 'department']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('organization_id');
        });

        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn(['slug', 'domain', 'country', 'timezone']);
        });
    }
};
