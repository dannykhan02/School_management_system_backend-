<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {

            // ── Status tracking token ─────────────────────────────────────────
            // A UUID sent to the parent in their confirmation email.
            // Allows unauthenticated status lookup via reference + email only.
            // Never exposed in list responses — only returned once on store().
            $table->uuid('tracking_token')
                  ->nullable()
                  ->unique()
                  ->after('id');

            // Track how many times the status check endpoint has been hit
            // per enrollment. Rate limiting — max 10 checks per hour per token.
            $table->unsignedSmallInteger('status_check_count')
                  ->default(0)
                  ->after('tracking_token');

            $table->timestamp('status_last_checked_at')
                  ->nullable()
                  ->after('status_check_count');

            // ── Government placement fields ───────────────────────────────────
            // Only relevant for enrollment_type = 'government_placement'
            // These are for CBC senior secondary school placements
            // issued by KNEC / Ministry of Education

            // The student's KNEC assessment index number
            // Format varies: e.g. "11234501001" or "CBC/2024/001234"
            $table->string('assessment_index_number', 30)
                  ->nullable()
                  ->after('last_class_attended');

            // Year of the assessment/placement e.g. 2024
            $table->unsignedSmallInteger('placement_year')
                  ->nullable()
                  ->after('assessment_index_number');

            // The reference code on the official placement letter
            $table->string('placement_reference_code', 50)
                  ->nullable()
                  ->after('placement_year');

            // The school the government placed the student at.
            // Should match THIS school — admin verifies this.
            $table->string('placement_school_name', 200)
                  ->nullable()
                  ->after('placement_reference_code');

            // Admin verification status for government placements
            // pending   = not yet verified
            // verified  = admin confirmed student is on official placement list
            // disputed  = student claims placement but not on school's list
            // manual    = placement letter uploaded, awaiting manual check
            $table->enum('placement_verification_status', [
                'pending', 'verified', 'disputed', 'manual'
            ])->default('pending')->after('placement_school_name');

            // Notes the admin writes during placement verification
            // e.g. "Verified against MoE list dated 2024-01-15"
            $table->text('placement_verification_notes')
                  ->nullable()
                  ->after('placement_verification_status');

            // Admin who verified the placement
            $table->foreignId('placement_verified_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete()
                  ->after('placement_verification_notes');

            $table->timestamp('placement_verified_at')
                  ->nullable()
                  ->after('placement_verified_by');
        });

        // Index for fast status tracking lookup
        // Parent provides: enrollment reference (id) + parent_email
        Schema::table('enrollments', function (Blueprint $table) {
            $table->index(['id', 'parent_email'], 'enrollments_tracking_lookup');
            $table->index('assessment_index_number', 'enrollments_assessment_index');
            $table->index('placement_verification_status', 'enrollments_placement_status');
        });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropIndex('enrollments_tracking_lookup');
            $table->dropIndex('enrollments_assessment_index');
            $table->dropIndex('enrollments_placement_status');
            $table->dropUnique(['tracking_token']);
            $table->dropColumn([
                'tracking_token',
                'status_check_count',
                'status_last_checked_at',
                'assessment_index_number',
                'placement_year',
                'placement_reference_code',
                'placement_school_name',
                'placement_verification_status',
                'placement_verification_notes',
                'placement_verified_by',
                'placement_verified_at',
            ]);
        });
    }
};