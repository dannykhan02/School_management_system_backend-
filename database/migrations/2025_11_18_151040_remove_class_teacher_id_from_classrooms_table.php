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
        // Check if the column exists before trying to drop it
        if (Schema::hasColumn('classes', 'class_teacher_id')) {
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
        Schema::table('classes', function (Blueprint $table) {
            $table->unsignedBigInteger('class_teacher_id')->nullable()->after('class_name');
            $table->foreign('class_teacher_id')->references('id')->on('teachers')->onDelete('set null');
        });
    }
};