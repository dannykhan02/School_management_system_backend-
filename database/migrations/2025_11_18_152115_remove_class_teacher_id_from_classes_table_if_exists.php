<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Check if the column exists before trying to drop it
        if (Schema::hasColumn('classes', 'class_teacher_id')) {
            // Get all foreign keys for the classes table
            $foreignKeys = DB::select("
                SELECT CONSTRAINT_NAME
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'classes'
                AND COLUMN_NAME = 'class_teacher_id'
            ");
            
            // Drop any foreign keys related to class_teacher_id
            foreach ($foreignKeys as $foreignKey) {
                DB::statement("ALTER TABLE classes DROP FOREIGN KEY {$foreignKey->CONSTRAINT_NAME}");
            }
            
            // Then drop the column
            Schema::table('classes', function (Blueprint $table) {
                $table->dropColumn('class_teacher_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Only add the column if it doesn't exist
        if (!Schema::hasColumn('classes', 'class_teacher_id')) {
            Schema::table('classes', function (Blueprint $table) {
                $table->unsignedBigInteger('class_teacher_id')->nullable()->after('class_name');
                $table->foreign('class_teacher_id')->references('id')->on('teachers')->onDelete('set null');
            });
        }
    }
};