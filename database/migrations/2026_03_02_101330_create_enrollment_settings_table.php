<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->foreignId('academic_year_id')->constrained()->onDelete('cascade');

            // ── Open / Close Window ──────────────────────────────────────────────
            $table->boolean('enrollment_open')->default(false);
            $table->date('open_date')->nullable();
            $table->date('close_date')->nullable();

            // ── Capacity ─────────────────────────────────────────────────────────
            $table->unsignedInteger('max_capacity')->default(0);   // 0 = unlimited
            $table->unsignedInteger('current_enrolled')->default(0);
            $table->boolean('allow_waitlist')->default(true);

            // ── Approval Behaviour ────────────────────────────────────────────────
            $table->boolean('auto_approve')->default(false);
            $table->json('required_documents')->nullable();        // ["birth_certificate","passport_photo"]

            // ── Enrollment Type Toggles ───────────────────────────────────────────
            $table->boolean('accept_new_students')->default(true);
            $table->boolean('accept_transfers')->default(true);
            $table->boolean('accept_returning')->default(true);

            // ── Notification Flags ────────────────────────────────────────────────
            $table->boolean('notify_parent_on_submit')->default(true);
            $table->boolean('notify_parent_on_approval')->default(true);
            $table->boolean('notify_parent_on_rejection')->default(true);
            $table->boolean('notify_admin_on_new_application')->default(true);

            $table->timestamps();

            $table->unique(['school_id', 'academic_year_id'], 'unique_enrollment_settings');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_settings');
    }
};