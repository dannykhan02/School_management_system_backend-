<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('subject_assignments', function (Blueprint $table) {
            // Specialization and workload tracking
            $table->boolean('is_outside_specialization')->default(false)->after('assignment_type');
            $table->enum('subject_type', ['core', 'pathway_compulsory', 'pathway_elective'])->nullable()->after('is_outside_specialization');
            $table->integer('assignment_priority')->default(0)->after('subject_type');
            
            // Timetable conflict tracking
            $table->json('timetable_periods')->nullable()->after('assignment_priority');
            $table->boolean('has_conflicts')->default(false)->after('timetable_periods');
            $table->json('conflict_details')->nullable()->after('has_conflicts');
            
            // KICD compliance
            $table->boolean('is_kicd_compliant')->default(true)->after('conflict_details');
            $table->string('learning_area')->nullable()->after('is_kicd_compliant');
            
            // Workload impact
            $table->integer('workload_impact_score')->default(0)->after('learning_area');
            
            // Batch assignment tracking
            $table->string('batch_assignment_id')->nullable()->after('workload_impact_score');
            $table->boolean('is_bulk_assignment')->default(false)->after('batch_assignment_id');
        });
    }

    public function down()
    {
        Schema::table('subject_assignments', function (Blueprint $table) {
            $table->dropColumn([
                'is_outside_specialization',
                'subject_type',
                'assignment_priority',
                'timetable_periods',
                'has_conflicts',
                'conflict_details',
                'is_kicd_compliant',
                'learning_area',
                'workload_impact_score',
                'batch_assignment_id',
                'is_bulk_assignment'
            ]);
        });
    }
};