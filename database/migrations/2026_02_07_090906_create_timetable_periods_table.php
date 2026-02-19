<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // First, drop the table if it exists (from failed attempt)
        if (Schema::hasTable('timetable_periods')) {
            Schema::dropIfExists('timetable_periods');
        }
        
        Schema::create('timetable_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained()->onDelete('cascade');
            $table->foreignId('subject_assignment_id')->constrained()->onDelete('cascade');
            $table->foreignId('classroom_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('stream_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('academic_year_id')->constrained()->onDelete('cascade');
            
            // Day and period info - Using term as string instead of foreign key
            $table->string('term'); // e.g., 'Term 1', 'Term 2', 'Term 3'
            $table->enum('day_of_week', ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']);
            $table->integer('period_number');
            $table->time('start_time');
            $table->time('end_time');
            
            // Conflict tracking
            $table->boolean('has_conflict')->default(false);
            $table->json('conflicting_periods')->nullable();
            
            // Additional metadata
            $table->string('period_type')->nullable(); // e.g., 'Lesson', 'Break', 'Assembly'
            $table->boolean('is_break')->default(false);
            $table->boolean('is_assembly')->default(false);
            $table->boolean('is_special_event')->default(false);
            
            $table->timestamps();
            
            // Unique constraint to prevent double booking
            $table->unique(['teacher_id', 'academic_year_id', 'term', 'day_of_week', 'period_number'], 'teacher_timetable_unique');
            $table->unique(['classroom_id', 'academic_year_id', 'term', 'day_of_week', 'period_number'], 'classroom_timetable_unique');
            $table->unique(['stream_id', 'academic_year_id', 'term', 'day_of_week', 'period_number'], 'stream_timetable_unique');
            
            // Indexes for better query performance
            $table->index(['academic_year_id', 'term', 'day_of_week']);
            $table->index(['teacher_id', 'day_of_week', 'period_number']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('timetable_periods');
    }
};