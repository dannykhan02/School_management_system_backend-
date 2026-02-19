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
        Schema::table('schools', function (Blueprint $table) {
            // Add missing boolean columns first
            $missingColumns = [
                'has_pre_primary',
                'has_primary', 
                'has_junior_secondary',
                'has_senior_secondary',
                'has_secondary',
                'has_special_needs_education',
            ];
            
            foreach ($missingColumns as $column) {
                if (!Schema::hasColumn('schools', $column)) {
                    $table->boolean($column)->default(false);
                }
            }
            
            // Add JSON columns
            if (!Schema::hasColumn('schools', 'senior_secondary_pathways')) {
                $table->json('senior_secondary_pathways')->nullable();
            }
            
            if (!Schema::hasColumn('schools', 'grade_levels')) {
                $table->json('grade_levels')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            // We'll keep the columns in case of rollback
            // If you want to remove them on down, uncomment:
            /*
            $table->dropColumn([
                'has_pre_primary',
                'has_primary', 
                'has_junior_secondary',
                'has_senior_secondary',
                'has_secondary',
                'has_special_needs_education',
                'senior_secondary_pathways',
                'grade_levels',
            ]);
            */
        });
    }
};