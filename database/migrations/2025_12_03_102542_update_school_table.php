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
        // This migration is now simplified - just add what's missing
        Schema::table('schools', function (Blueprint $table) {
            // First, add has_junior_secondary if it doesn't exist
            if (!Schema::hasColumn('schools', 'has_junior_secondary')) {
                $table->boolean('has_junior_secondary')->default(false);
            }
            
            // Now add has_senior_secondary
            if (!Schema::hasColumn('schools', 'has_senior_secondary')) {
                $table->boolean('has_senior_secondary')->default(false);
            }
            
            // Add has_secondary
            if (!Schema::hasColumn('schools', 'has_secondary')) {
                $table->boolean('has_secondary')->default(false);
            }
            
            // Add secondary_curriculum
            if (!Schema::hasColumn('schools', 'secondary_curriculum')) {
                $table->string('secondary_curriculum')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            // Optional: drop columns if you want to rollback
            // $table->dropColumn(['has_junior_secondary', 'has_senior_secondary', 'has_secondary', 'secondary_curriculum']);
        });
    }
};