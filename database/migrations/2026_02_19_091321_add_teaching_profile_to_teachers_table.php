<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * This migration:
     *  1. Adds teaching profile columns to the `teachers` table
     *     (teaching_levels, teaching_pathways, min_weekly_lessons, tsc_status, employment_status)
     *  2. Creates the `teacher_subjects` pivot table so a teacher can
     *     declare which specific subjects they are qualified to teach.
     */
    public function up(): void
    {
        // ─── 1. Update teachers table ─────────────────────────────────────────
        Schema::table('teachers', function (Blueprint $table) {

            // teaching_levels stores an array of level strings the teacher can teach
            // e.g. ["Pre-Primary", "Primary", "Junior Secondary", "Senior Secondary"]
            if (!Schema::hasColumn('teachers', 'teaching_levels')) {
                $table->json('teaching_levels')->nullable()->after('curriculum_specialization')
                      ->comment('Levels this teacher is qualified to teach. Allowed values: Pre-Primary, Primary, Junior Secondary, Senior Secondary');
            }

            // teaching_pathways is only relevant for Senior Secondary teachers
            // e.g. ["STEM", "Arts", "Social Sciences"]
            if (!Schema::hasColumn('teachers', 'teaching_pathways')) {
                $table->json('teaching_pathways')->nullable()->after('teaching_levels')
                      ->comment('CBC Senior Secondary pathways: STEM, Arts, Social Sciences. Null for non-SS teachers');
            }

            // min_weekly_lessons – the minimum expected lessons per week
            if (!Schema::hasColumn('teachers', 'min_weekly_lessons')) {
                $table->unsignedSmallInteger('min_weekly_lessons')->default(20)->after('max_weekly_lessons')
                      ->comment('Minimum weekly teaching periods expected');
            }

            // tsc_status – Teacher Service Commission registration status
            if (!Schema::hasColumn('teachers', 'tsc_status')) {
                $table->string('tsc_status')->nullable()->after('tsc_number')
                      ->comment('TSC registration status: registered, pending, not_registered');
            }

            // employment_status – active, on_leave, suspended, etc.
            if (!Schema::hasColumn('teachers', 'employment_status')) {
                $table->string('employment_status')->default('active')->after('employment_type')
                      ->comment('active | on_leave | suspended | resigned | retired');
            }

            // specialization_subjects – JSON array of subject IDs (legacy / quick lookup)
            // The proper relationship is via teacher_subjects pivot, but this is kept
            // for quick reads without a join.
            if (!Schema::hasColumn('teachers', 'specialization_subjects')) {
                $table->json('specialization_subjects')->nullable()->after('specialization')
                      ->comment('Quick-lookup array of subject IDs this teacher specialises in');
            }

            // subject_categories – JSON array of category strings e.g. ["Sciences", "Mathematics"]
            if (!Schema::hasColumn('teachers', 'subject_categories')) {
                $table->json('subject_categories')->nullable()->after('specialization_subjects')
                      ->comment('Broad subject categories this teacher can teach');
            }

            // is_class_teacher – boolean flag (denormalised for speed)
            if (!Schema::hasColumn('teachers', 'is_class_teacher')) {
                $table->boolean('is_class_teacher')->default(false)->after('subject_categories');
            }

            // FK shortcuts for the "current" class-teacher assignment
            if (!Schema::hasColumn('teachers', 'current_class_teacher_classroom_id')) {
                $table->foreignId('current_class_teacher_classroom_id')
                      ->nullable()
                      ->after('is_class_teacher')
                      ->constrained('classrooms')
                      ->nullOnDelete();
            }

            if (!Schema::hasColumn('teachers', 'current_class_teacher_stream_id')) {
                $table->foreignId('current_class_teacher_stream_id')
                      ->nullable()
                      ->after('current_class_teacher_classroom_id')
                      ->constrained('streams')
                      ->nullOnDelete();
            }
        });

        // ─── 2. Create teacher_subjects pivot table ────────────────────────────
        if (!Schema::hasTable('teacher_subjects')) {
            Schema::create('teacher_subjects', function (Blueprint $table) {
                $table->id();

                $table->foreignId('teacher_id')
                      ->constrained('teachers')
                      ->cascadeOnDelete();

                $table->foreignId('subject_id')
                      ->constrained('subjects')
                      ->cascadeOnDelete();

                // Extra metadata stored on the pivot ----------------------------

                // is_primary_subject: the subject the teacher primarily specialises in
                $table->boolean('is_primary_subject')->default(false)
                      ->comment('True when this is the teacher\'s main / first-choice subject');

                // years_experience: how many years teaching this specific subject
                $table->unsignedSmallInteger('years_experience')->nullable()
                      ->comment('Number of years the teacher has taught this subject');

                // can_teach_level: optional override – teacher qualified only for
                // a particular level of the same subject
                // e.g. a teacher might teach Mathematics at Primary but not Senior Secondary
                $table->json('can_teach_levels')->nullable()
                      ->comment('Levels at which the teacher can teach this subject; null = all levels on teacher profile');

                $table->timestamps();

                // A teacher cannot be linked to the same subject twice
                $table->unique(['teacher_id', 'subject_id'], 'uq_teacher_subject');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop the pivot table first (FK dependency)
        Schema::dropIfExists('teacher_subjects');

        // Remove added columns from teachers
        Schema::table('teachers', function (Blueprint $table) {
            $columnsToDrop = [
                'teaching_levels',
                'teaching_pathways',
                'min_weekly_lessons',
                'tsc_status',
                'employment_status',
                'specialization_subjects',
                'subject_categories',
                'is_class_teacher',
                'current_class_teacher_classroom_id',
                'current_class_teacher_stream_id',
            ];

            foreach ($columnsToDrop as $column) {
                if (Schema::hasColumn('teachers', $column)) {
                    // Drop FK constraints before dropping column
                    if (in_array($column, ['current_class_teacher_classroom_id', 'current_class_teacher_stream_id'])) {
                        $table->dropForeign([$column]);
                    }
                    $table->dropColumn($column);
                }
            }
        });
    }
};