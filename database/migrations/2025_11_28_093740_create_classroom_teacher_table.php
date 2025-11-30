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
        // Only create if it doesn't exist
        if (!Schema::hasTable('classroom_teacher')) {
            Schema::create('classroom_teacher', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('classroom_id');
                $table->unsignedBigInteger('teacher_id');
                $table->boolean('is_class_teacher')->default(false);
                $table->timestamps();

                // Foreign keys
                $table->foreign('classroom_id')
                      ->references('id')
                      ->on('classrooms')
                      ->onDelete('cascade');

                $table->foreign('teacher_id')
                      ->references('id')
                      ->on('teachers')
                      ->onDelete('cascade');

                // Unique constraint to prevent duplicate assignments
                $table->unique(['classroom_id', 'teacher_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('classroom_teacher');
    }
};