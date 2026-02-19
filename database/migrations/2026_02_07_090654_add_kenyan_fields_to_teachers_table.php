<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('teachers', function (Blueprint $table) {
            // TSC Workload tracking
            $table->integer('max_weekly_lessons')->default(27)->after('max_classes');
            $table->integer('min_weekly_lessons')->default(21)->after('max_weekly_lessons');
            $table->json('specialization_subjects')->nullable()->after('curriculum_specialization');
            $table->json('subject_categories')->nullable()->after('specialization_subjects');
            $table->boolean('is_class_teacher')->default(false)->after('subject_categories');
            $table->integer('class_teacher_classroom_id')->nullable()->after('is_class_teacher');
            $table->integer('class_teacher_stream_id')->nullable()->after('class_teacher_classroom_id');
            $table->string('tsc_status')->nullable()->after('tsc_number');
            $table->string('employment_status')->nullable()->after('employment_type');
        });
    }

    public function down()
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->dropColumn([
                'max_weekly_lessons',
                'min_weekly_lessons',
                'specialization_subjects',
                'subject_categories',
                'is_class_teacher',
                'class_teacher_classroom_id',
                'class_teacher_stream_id',
                'tsc_status',
                'employment_status'
            ]);
        });
    }
};