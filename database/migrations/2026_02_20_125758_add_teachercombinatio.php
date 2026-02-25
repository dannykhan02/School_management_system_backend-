<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ─────────────────────────────────────────────────────────────────────────────
 * Migration: create_teacher_combinations_table
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Creates the canonical lookup table for Kenyan B.Ed / Diploma subject
 * combinations recognised by the Teachers Service Commission (TSC).
 *
 * Run after: create_teachers_table
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. teacher_combinations — master lookup ───────────────────────────
        Schema::create('teacher_combinations', function (Blueprint $table) {
            $table->id();

            // Identity
            $table->string('code')->unique()
                  ->comment('Machine-readable code e.g. BED-MATH-PHY');
            $table->string('name')
                  ->comment('Human label e.g. Mathematics & Physics');

            // Degree / Qualification metadata
            $table->string('degree_title')
                  ->comment('Full degree name e.g. Bachelor of Education (Science) — Mathematics & Physics');
            $table->string('degree_abbreviation', 60)
                  ->comment('Short form e.g. B.Ed (Sc.) Math/Phys');
            $table->enum('institution_type', [
                    'university',
                    'teacher_training_college',
                    'technical_university',
                ])
                  ->default('university')
                  ->comment('Type of institution that awards this qualification');

            // Grouping
            $table->string('subject_group', 60)
                  ->comment('High-level group: STEM | Languages | Humanities | Business | Creative Arts | etc.');

            // Subject eligibility (JSON columns)
            $table->json('primary_subjects')
                  ->comment('Subject names the teacher trained in directly');
            $table->json('derived_subjects')
                  ->nullable()
                  ->comment('Extra subjects they may teach by professional extension');
            $table->json('eligible_levels')
                  ->comment('Educational levels: Pre-Primary | Primary | Junior Secondary | Senior Secondary | Secondary');
            $table->json('eligible_pathways')
                  ->nullable()
                  ->comment('CBC SS pathways: STEM | Arts | Social Sciences');
            $table->json('curriculum_types')
                  ->comment('Supported curricula: CBC | 8-4-4');

            // Flags
            $table->boolean('tsc_recognized')->default(true)
                  ->comment('Is this combination officially recognised by the TSC?');
            $table->boolean('is_active')->default(true)
                  ->comment('Soft-disable obsolete combinations without deleting them');

            // Context
            $table->longText('notes')->nullable()
                  ->comment('Human-readable notes, caveats, CBC-specific rules');

            $table->timestamps();

            // Indexes for common filter queries
            $table->index('subject_group');
            $table->index('institution_type');
            $table->index('is_active');
        });

        // ── 2. Extend teachers table with combination FK & B.Ed metadata ──────
        Schema::table('teachers', function (Blueprint $table) {

            // FK to the combinations lookup
            $table->foreignId('combination_id')
                  ->nullable()
                  ->after('curriculum_specialization')
                  ->constrained('teacher_combinations')
                  ->nullOnDelete()
                  ->comment('FK → teacher_combinations.id — the B.Ed combination this teacher holds');

            // Human-readable degree information stored alongside the FK
            // so we never lose data if the combination is soft-deleted
            $table->string('bed_combination_code', 60)
                  ->nullable()
                  ->after('combination_id')
                  ->comment('Denormalised code snapshot e.g. BED-MATH-PHY');

            $table->string('bed_combination_label', 120)
                  ->nullable()
                  ->after('bed_combination_code')
                  ->comment('Denormalised label snapshot e.g. Mathematics & Physics');

            $table->year('bed_graduation_year')
                  ->nullable()
                  ->after('bed_combination_label')
                  ->comment('Year the teacher graduated / received their qualification');

            $table->enum('bed_institution_type', [
                    'university',
                    'teacher_training_college',
                    'technical_university',
                ])
                  ->nullable()
                  ->after('bed_graduation_year')
                  ->comment('Type of institution that awarded the qualification');

            $table->string('bed_awarding_institution', 150)
                  ->nullable()
                  ->after('bed_institution_type')
                  ->comment('Name of the specific university / college e.g. Kenyatta University');

            // Index for filtering teachers by combination
            $table->index('combination_id');
            $table->index('bed_combination_code');
        });
    }

    public function down(): void
    {
        // Remove the FK columns from teachers first
        Schema::table('teachers', function (Blueprint $table) {
            $table->dropForeign(['combination_id']);
            $table->dropIndex(['combination_id']);
            $table->dropIndex(['bed_combination_code']);
            $table->dropColumn([
                'combination_id',
                'bed_combination_code',
                'bed_combination_label',
                'bed_graduation_year',
                'bed_institution_type',
                'bed_awarding_institution',
            ]);
        });

        Schema::dropIfExists('teacher_combinations');
    }
};