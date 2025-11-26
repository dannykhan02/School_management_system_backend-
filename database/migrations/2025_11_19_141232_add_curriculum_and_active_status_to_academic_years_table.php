<?php

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
        Schema::table('academic_years', function (Blueprint $table) {
            // Add the curriculum_type column to distinguish between CBC and 8-4-4
            $table->enum('curriculum_type', ['CBC', '8-4-4'])->after('end_date');

            // Add a simple boolean to mark if this is the currently active academic year
            $table->boolean('is_active')->default(false)->after('curriculum_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('academic_years', function (Blueprint $table) {
            $table->dropColumn(['curriculum_type', 'is_active']);
        });
    }
};