<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('attendance_student', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->onDelete('cascade');
            $table->date('date');
            $table->text('note')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->onDelete('cascade');
            $table->enum('status', ['present', 'absent', 'late', 'excused']);
            $table->timestamps();
            
            $table->unique(['student_id', 'date']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('attendance_student');
    }
};