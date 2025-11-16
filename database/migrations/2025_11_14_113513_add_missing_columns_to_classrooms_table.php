<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classrooms', function (Blueprint $table) {
            if (!Schema::hasColumn('classrooms', 'school_id')) {
                $table->foreignId('school_id')->after('id')->constrained('schools')->onDelete('cascade');
            }
            if (!Schema::hasColumn('classrooms', 'class_name')) {
                $table->string('class_name')->after('school_id');
            }
            if (!Schema::hasColumn('classrooms', 'class_teacher_id')) {
                $table->foreignId('class_teacher_id')->nullable()->after('class_name')->constrained('teachers')->onDelete('set null');
            }
            if (!Schema::hasColumn('classrooms', 'capacity')) {
                $table->integer('capacity')->nullable()->after('class_teacher_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('classrooms', function (Blueprint $table) {
            if (Schema::hasColumn('classrooms', 'capacity')) {
                $table->dropColumn('capacity');
            }
            if (Schema::hasColumn('classrooms', 'class_teacher_id')) {
                $table->dropForeign(['class_teacher_id']);
                $table->dropColumn('class_teacher_id');
            }
            if (Schema::hasColumn('classrooms', 'class_name')) {
                $table->dropColumn('class_name');
            }
            if (Schema::hasColumn('classrooms', 'school_id')) {
                $table->dropForeign(['school_id']);
                $table->dropColumn('school_id');
            }
        });
    }
};