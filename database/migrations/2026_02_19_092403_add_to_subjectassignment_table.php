<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the subject_assignments table.
 *
 * This records the LIVE teaching allocation:
 * "Teacher X teaches Subject Y to Class/Stream Z in Academic Year A, Term B."
 *
 * It is distinct from teacher_subjects (qualification table).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('subject_assignments')) {
            Schema::create('subject_assignments', function (Blueprint $table) {
                $table->id();

                $table->foreignId('teacher_id')
                      ->constrained('teachers')
                      ->cascadeOnDelete();

                $table->foreignId('subject_id')
                      ->constrained('subjects')
                      ->cascadeOnDelete();

                $table->foreignId('school_id')
                      ->constrained('schools')
                      ->cascadeOnDelete();

                $table->foreignId('academic_year_id')
                      ->constrained('academic_years')
                      ->cascadeOnDelete();

                $table->foreignId('term_id')
                      ->nullable()
                      ->constrained('terms')
                      ->nullOnDelete();

                // For non-stream schools
                $table->foreignId('classroom_id')
                      ->nullable()
                      ->constrained('classrooms')
                      ->nullOnDelete();

                // For stream schools
                $table->foreignId('stream_id')
                      ->nullable()
                      ->constrained('streams')
                      ->nullOnDelete();

                $table->unsignedSmallInteger('weekly_periods')->default(0)
                      ->comment('Number of teaching periods per week for this assignment');

                $table->boolean('is_active')->default(true);

                $table->text('notes')->nullable();

                $table->timestamps();

                // A teacher cannot be assigned to the same subject+class+year+term twice
                $table->unique(
                    ['teacher_id', 'subject_id', 'academic_year_id', 'term_id', 'classroom_id'],
                    'uq_assignment_classroom'
                );

                $table->unique(
                    ['teacher_id', 'subject_id', 'academic_year_id', 'term_id', 'stream_id'],
                    'uq_assignment_stream'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_assignments');
    }
};