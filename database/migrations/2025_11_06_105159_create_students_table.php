<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();

            // Foreign keys
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('school_id')->constrained('schools')->onDelete('cascade');
            $table->foreignId('class_id')->nullable()->constrained('classes')->onDelete('set null');
            $table->foreignId('parent_id')->nullable()->constrained('parents')->onDelete('set null');

            // Core fields
            $table->enum('status', ['active', 'inactive', 'graduated'])->default('active');
            $table->string('admission_number', 50)->unique();
            $table->date('date_of_birth')->nullable();
            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            $table->date('admission_date')->nullable();

            $table->timestamps();

            // Indexes for speed
            $table->index(['school_id', 'class_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
