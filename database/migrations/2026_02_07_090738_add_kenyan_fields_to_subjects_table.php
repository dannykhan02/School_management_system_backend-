<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('subjects', function (Blueprint $table) {
            // KICD compliance fields
            $table->boolean('is_kicd_compulsory')->default(false)->after('is_core');
            $table->string('learning_area')->nullable()->after('is_kicd_compulsory');
            $table->integer('minimum_weekly_periods')->default(3)->after('learning_area');
            $table->integer('maximum_weekly_periods')->default(8)->after('minimum_weekly_periods');
            
            // Subject combination rules for 8-4-4
            $table->json('prerequisite_subjects')->nullable()->after('maximum_weekly_periods');
            $table->json('incompatible_subjects')->nullable()->after('prerequisite_subjects');
            $table->boolean('requires_lab')->default(false)->after('incompatible_subjects');
            
            // CBC pathway specific
            $table->enum('cbc_pathway', ['STEM', 'Arts', 'Social Sciences', 'All'])->default('All')->after('requires_lab');
            
            // Grade level specific
            $table->json('grade_levels')->nullable()->after('cbc_pathway');
            
            // Subject code standardization
            $table->string('kicd_code')->nullable()->after('code');
            $table->string('national_subject_id')->nullable()->after('kicd_code');
        });
    }

    public function down()
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropColumn([
                'is_kicd_compulsory',
                'learning_area',
                'minimum_weekly_periods',
                'maximum_weekly_periods',
                'prerequisite_subjects',
                'incompatible_subjects',
                'requires_lab',
                'cbc_pathway',
                'grade_levels',
                'kicd_code',
                'national_subject_id'
            ]);
        });
    }
};