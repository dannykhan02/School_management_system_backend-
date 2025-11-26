<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * This method adds the necessary columns to the 'subjects' table to support
     * both the CBC and 8-4-4 curricula.
     */
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            // Add the curriculum_type column to distinguish between CBC and 8-4-4
            $table->enum('curriculum_type', ['CBC', '8-4-4'])->after('code');

            // Add the grade_level column (e.g., 'Grade 1-3', 'Form 1-4')
            $table->string('grade_level')->after('curriculum_type');

            // Add the category column (e.g., 'Languages', 'Sciences'). It can be nullable.
            $table->string('category')->nullable()->after('grade_level');

            // Add the is_core column to distinguish between core and elective subjects.
            // Defaults to false (elective) for safety.
            $table->boolean('is_core')->default(false)->after('category');
        });
    }

    /**
     * Reverse the migrations.
     *
     * This method removes the columns if the migration is rolled back.
     */
    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropColumn(['curriculum_type', 'grade_level', 'category', 'is_core']);
        });
    }
};
