<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('teachers', function (Blueprint $table) {
            // First, check if the old integer columns exist and remove them
            if (Schema::hasColumn('teachers', 'class_teacher_classroom_id')) {
                $table->dropColumn('class_teacher_classroom_id');
            }
            
            if (Schema::hasColumn('teachers', 'class_teacher_stream_id')) {
                $table->dropColumn('class_teacher_stream_id');
            }
            
            // Add proper foreign key columns based on your model structure
            // Since you're using pivot tables, we don't need these as direct foreign keys
            // But if you want to keep direct foreign keys for quick access, here they are:
            
            // Optional: Add foreign key for class teacher's classroom (if using direct relationship)
            $table->foreignId('current_class_teacher_classroom_id')
                  ->nullable()
                  ->constrained('classrooms')
                  ->nullOnDelete()
                  ->comment('Direct reference to classroom where teacher is class teacher');
            
            // Optional: Add foreign key for class teacher's stream (if using direct relationship)
            $table->foreignId('current_class_teacher_stream_id')
                  ->nullable()
                  ->constrained('streams')
                  ->nullOnDelete()
                  ->comment('Direct reference to stream where teacher is class teacher');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('teachers', function (Blueprint $table) {
            // Drop the new foreign key columns
            $table->dropForeign(['current_class_teacher_classroom_id']);
            $table->dropForeign(['current_class_teacher_stream_id']);
            
            $table->dropColumn(['current_class_teacher_classroom_id', 'current_class_teacher_stream_id']);
            
            // Re-add old integer columns if needed
            $table->integer('class_teacher_classroom_id')->nullable();
            $table->integer('class_teacher_stream_id')->nullable();
        });
    }
};