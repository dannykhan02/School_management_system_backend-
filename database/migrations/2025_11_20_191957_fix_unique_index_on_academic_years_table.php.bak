<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        // Check if the index exists before dropping it
        $indexExists = DB::select("
            SELECT COUNT(1) as count 
            FROM information_schema.statistics 
            WHERE table_schema = DATABASE()
            AND table_name = 'academic_years'
            AND index_name = 'academic_years_school_id_year_term_unique'
        ")[0]->count;

        if ($indexExists > 0) {
            Schema::table('academic_years', function (Blueprint $table) {
                $table->dropUnique('academic_years_school_id_year_term_unique');
            });
        }

        // Create the correct index
        Schema::table('academic_years', function (Blueprint $table) {
            $table->unique(
                ['school_id', 'term', 'start_date', 'end_date'],
                'academic_years_unique_corrected'
            );
        });
    }

    public function down()
    {
        // Safe drop
        $indexExists = DB::select("
            SELECT COUNT(1) as count 
            FROM information_schema.statistics 
            WHERE table_schema = DATABASE()
            AND table_name = 'academic_years'
            AND index_name = 'academic_years_unique_corrected'
        ")[0]->count;

        if ($indexExists > 0) {
            Schema::table('academic_years', function (Blueprint $table) {
                $table->dropUnique('academic_years_unique_corrected');
            });
        }
    }
};
