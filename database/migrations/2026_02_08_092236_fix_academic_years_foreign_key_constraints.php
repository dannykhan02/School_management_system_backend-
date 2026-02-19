<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Instead of dropping the existing unique constraint,
        // just add an additional unique constraint that we need
        
        // First check if the new index already exists using SQL
        $indexExists = DB::select("
            SELECT COUNT(*) as count
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'academic_years'
            AND INDEX_NAME = 'academic_years_school_id_year_unique'
        ");
        
        if ($indexExists[0]->count == 0) {
            Schema::table('academic_years', function (Blueprint $table) {
                $table->unique(['school_id', 'year'], 'academic_years_school_id_year_unique');
            });
        }
        
        // Also add an index for term queries (non-unique)
        $termIndexExists = DB::select("
            SELECT COUNT(*) as count
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'academic_years'
            AND INDEX_NAME = 'academic_years_school_year_term_index'
        ");
        
        if ($termIndexExists[0]->count == 0) {
            Schema::table('academic_years', function (Blueprint $table) {
                $table->index(['school_id', 'year', 'term'], 'academic_years_school_year_term_index');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Check if the indexes exist before dropping them
        $uniqueIndexExists = DB::select("
            SELECT COUNT(*) as count
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'academic_years'
            AND INDEX_NAME = 'academic_years_school_id_year_unique'
        ");
        
        if ($uniqueIndexExists[0]->count > 0) {
            Schema::table('academic_years', function (Blueprint $table) {
                $table->dropUnique('academic_years_school_id_year_unique');
            });
        }
        
        $termIndexExists = DB::select("
            SELECT COUNT(*) as count
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'academic_years'
            AND INDEX_NAME = 'academic_years_school_year_term_index'
        ");
        
        if ($termIndexExists[0]->count > 0) {
            Schema::table('academic_years', function (Blueprint $table) {
                $table->dropIndex('academic_years_school_year_term_index');
            });
        }
    }
};