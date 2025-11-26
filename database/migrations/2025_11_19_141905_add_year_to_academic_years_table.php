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

            // Add 'year' column only if missing
            if (!Schema::hasColumn('academic_years', 'year')) {
                $table->integer('year')->after('school_id');
            }

            // Ensure 'term' exists (string)
            if (!Schema::hasColumn('academic_years', 'term')) {
                $table->string('term')->after('year');
            }

            // Ensure start_date exists
            if (!Schema::hasColumn('academic_years', 'start_date')) {
                $table->date('start_date')->nullable()->after('term');
            }

            // Ensure end_date exists
            if (!Schema::hasColumn('academic_years', 'end_date')) {
                $table->date('end_date')->nullable()->after('start_date');
            }

            // Add curriculum_type if missing
            if (!Schema::hasColumn('academic_years', 'curriculum_type')) {
                $table->enum('curriculum_type', ['CBC', '8-4-4'])->after('end_date');
            }

            // Add is_active if missing
            if (!Schema::hasColumn('academic_years', 'is_active')) {
                $table->boolean('is_active')->default(false)->after('curriculum_type');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('academic_years', function (Blueprint $table) {
            if (Schema::hasColumn('academic_years', 'year')) {
                $table->dropColumn('year');
            }
            if (Schema::hasColumn('academic_years', 'term')) {
                $table->dropColumn('term');
            }
            if (Schema::hasColumn('academic_years', 'start_date')) {
                $table->dropColumn('start_date');
            }
            if (Schema::hasColumn('academic_years', 'end_date')) {
                $table->dropColumn('end_date');
            }
            if (Schema::hasColumn('academic_years', 'curriculum_type')) {
                $table->dropColumn('curriculum_type');
            }
            if (Schema::hasColumn('academic_years', 'is_active')) {
                $table->dropColumn('is_active');
            }
        });
    }
};
