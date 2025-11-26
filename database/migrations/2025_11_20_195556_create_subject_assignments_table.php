<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('subject_assignments', function (Blueprint $table) {
            $table->id();
            
            // Foreign key to the teacher
            $table->foreignId('teacher_id')->constrained()->onDelete('cascade');
            
            // Foreign key to the subject
            $table->foreignId('subject_id')->constrained()->onDelete('cascade');
            
            // Foreign key to the academic year
            $table->foreignId('academic_year_id')->constrained()->onDelete('cascade');
            
            // Foreign key to the stream (class)
            $table->foreignId('stream_id')->constrained()->onDelete('cascade');
            
            // Details about the assignment
            $table->integer('weekly_periods')->default(1);
            $table->enum('assignment_type', ['main_teacher', 'assistant_teacher', 'substitute'])->default('main_teacher');
            
            $table->timestamps();

            // Ensure a teacher can only be assigned once to teach a specific subject 
            // in a specific stream for an academic year.
            $table->unique(['teacher_id', 'subject_id', 'academic_year_id', 'stream_id'], 'unique_subject_assignment');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subject_assignments');
    }
};