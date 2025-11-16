<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('streams', function (Blueprint $table) {
            // Add school_id if it doesn't exist
            if (!Schema::hasColumn('streams', 'school_id')) {
                $table->foreignId('school_id')->after('id')->constrained('schools')->onDelete('cascade');
            }
            
            // Add name if it doesn't exist
            if (!Schema::hasColumn('streams', 'name')) {
                $table->string('name')->after('school_id');
            }
            
            // Add class_id if it doesn't exist
            if (!Schema::hasColumn('streams', 'class_id')) {
                $table->foreignId('class_id')->nullable()->after('name')->constrained('classrooms')->onDelete('set null');
            }
            
            // Add class_teacher_id if it doesn't exist
            if (!Schema::hasColumn('streams', 'class_teacher_id')) {
                $table->foreignId('class_teacher_id')->nullable()->after('class_id')->constrained('teachers')->onDelete('set null');
            }
        });
    }

    public function down(): void
    {
        Schema::table('streams', function (Blueprint $table) {
            if (Schema::hasColumn('streams', 'class_teacher_id')) {
                $table->dropForeign(['class_teacher_id']);
                $table->dropColumn('class_teacher_id');
            }
            if (Schema::hasColumn('streams', 'class_id')) {
                $table->dropForeign(['class_id']);
                $table->dropColumn('class_id');
            }
            if (Schema::hasColumn('streams', 'name')) {
                $table->dropColumn('name');
            }
            if (Schema::hasColumn('streams', 'school_id')) {
                $table->dropForeign(['school_id']);
                $table->dropColumn('school_id');
            }
        });
    }
};