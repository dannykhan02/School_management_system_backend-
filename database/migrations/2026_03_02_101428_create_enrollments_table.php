<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->foreignId('academic_year_id')->constrained()->onDelete('cascade');

            // ── Applicant Identity ────────────────────────────────────────────────
            // These columns store raw form data BEFORE a student record is created.
            // Once approved, this data seeds the students + users tables.
            $table->string('first_name');
            $table->string('last_name');
            $table->date('date_of_birth');
            $table->enum('gender', ['male', 'female', 'other']);
            $table->string('nationality')->default('Kenyan');
            $table->string('religion')->nullable();
            $table->boolean('special_needs')->default(false);
            $table->text('special_needs_details')->nullable(); // only if special_needs = true

            // ── Requested Class ───────────────────────────────────────────────────
            // Which class/grade is the applicant applying to join
            $table->foreignId('applying_for_classroom_id')
                  ->nullable()
                  ->constrained('classrooms')
                  ->nullOnDelete();
            $table->foreignId('applying_for_stream_id')
                  ->nullable()
                  ->constrained('streams')
                  ->nullOnDelete();

            // ── Parent / Guardian Info ────────────────────────────────────────────
            $table->string('parent_first_name');
            $table->string('parent_last_name');
            $table->string('parent_phone');
            $table->string('parent_email')->nullable();       // not every parent has email
            $table->string('parent_national_id')->nullable();
            $table->enum('parent_relationship', ['father', 'mother', 'guardian', 'other']);
            $table->string('parent_occupation')->nullable();

            // ── Transfer Info (entire block nullable) ────────────────────────────
            // Only populated when enrollment_type = 'transfer'
            $table->boolean('is_transfer')->default(false);
            $table->string('previous_school_name')->nullable();
            $table->string('previous_school_address')->nullable();
            $table->string('previous_admission_number')->nullable();  // their number at old school
            $table->string('leaving_certificate_number')->nullable();

            // ── Documents ────────────────────────────────────────────────────────
            // JSON array of uploaded file paths
            // e.g. ["documents/birth_cert_123.pdf", "documents/photo_123.jpg"]
            $table->json('documents')->nullable();

            // ── Workflow Status ───────────────────────────────────────────────────
            $table->enum('status', [
                'draft',          // saved but not submitted yet
                'submitted',      // parent has submitted, awaiting admin
                'under_review',   // admin opened and is reviewing
                'approved',       // approved → triggers student creation
                'rejected',       // rejected → reason stored below
                'waitlisted',     // school full, on waiting list
            ])->default('draft');

            $table->enum('enrollment_type', [
                'new',            // brand new student
                'transfer',       // coming from another school
                'returning',      // was in this school before (e.g. readmission)
            ])->default('new');

            // ── Timestamps for workflow tracking ─────────────────────────────────
            $table->timestamp('applied_at')->nullable();    // when status → submitted
            $table->timestamp('reviewed_at')->nullable();   // when admin opened review
            $table->timestamp('approved_at')->nullable();   // when status → approved
            $table->timestamp('rejected_at')->nullable();   // when status → rejected

            // ── Who reviewed / approved ───────────────────────────────────────────
            $table->foreignId('reviewed_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->text('rejection_reason')->nullable();   // filled if rejected
            $table->text('admin_notes')->nullable();        // internal notes, not shown to parent

            // ── Post-Approval Links ───────────────────────────────────────────────
            // NULL until the enrollment is approved and records are created
            $table->foreignId('student_id')
                  ->nullable()
                  ->constrained('students')
                  ->nullOnDelete();

            $table->foreignId('user_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            // Final assigned class (may differ from applying_for_classroom_id)
            $table->foreignId('assigned_classroom_id')
                  ->nullable()
                  ->constrained('classrooms')
                  ->nullOnDelete();

            $table->foreignId('assigned_stream_id')
                  ->nullable()
                  ->constrained('streams')
                  ->nullOnDelete();

            $table->timestamps();

            // ── Indexes for fast lookups ──────────────────────────────────────────
            $table->index(['school_id', 'status']);
            $table->index(['school_id', 'academic_year_id', 'status']);
            $table->index('applied_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollments');
    }
};