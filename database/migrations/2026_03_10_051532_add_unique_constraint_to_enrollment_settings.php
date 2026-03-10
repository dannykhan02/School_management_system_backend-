<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add a composite unique constraint on (school_id, academic_year_id).
 *
 * WHY THIS IS NEEDED
 * ──────────────────
 * Each academic_year row already represents a single term (e.g. "2026 Term 1"),
 * so (school_id + academic_year_id) must be unique in enrollment_settings —
 * every school gets exactly one settings row per term.
 *
 * The controller uses updateOrCreate() which relies on this pair being unique
 * to correctly find an existing record. Without the DB-level constraint a race
 * condition (two simultaneous POST requests) could insert two rows, causing
 * updateOrCreate() to throw an "unexpected multiple results" exception on the
 * next request — or silently update the wrong row.
 *
 * The FormRequest already validates uniqueness in PHP, but that check has a
 * TOCTOU (time-of-check / time-of-use) window under concurrent load. This
 * migration closes that window at the database level as the final safety net.
 *
 * BEFORE RUNNING
 * ──────────────
 * If the table already has duplicate (school_id, academic_year_id) pairs from
 * before this constraint existed, the migration will fail. Clean them up first:
 *
 *   DELETE es1
 *   FROM   enrollment_settings es1
 *   JOIN   enrollment_settings es2
 *          ON  es2.school_id        = es1.school_id
 *          AND es2.academic_year_id = es1.academic_year_id
 *          AND es2.id               < es1.id;
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollment_settings', function (Blueprint $table) {
            $table->unique(
                ['school_id', 'academic_year_id'],
                'uq_enrollment_settings_school_term'  // explicit name — easier to reference in tests / rollbacks
            );
        });
    }

    public function down(): void
    {
        Schema::table('enrollment_settings', function (Blueprint $table) {
            $table->dropUnique('uq_enrollment_settings_school_term');
        });
    }
};