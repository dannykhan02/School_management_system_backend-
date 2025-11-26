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
        Schema::table('teachers', function (Blueprint $table) {
            // The teacher's subject area of expertise (e.g., Sciences, Humanities)
            $table->string('specialization')->nullable()->after('tsc_number');

            // Which curriculum the teacher is qualified to teach
            $table->enum('curriculum_specialization', ['CBC', '8-4-4', 'Both'])->nullable()->after('specialization');

            // Maximum number of subjects this teacher can be assigned
            $table->integer('max_subjects')->nullable()->after('curriculum_specialization');

            // Maximum number of classes/streams this teacher can handle
            $table->integer('max_classes')->nullable()->after('max_subjects');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->dropColumn(['specialization', 'curriculum_specialization', 'max_subjects', 'max_classes']);
        });
    }
};