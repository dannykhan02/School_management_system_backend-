<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;   // <-- REQUIRED

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {

            // Add the stream_id column ONLY if it doesn't exist
            if (!Schema::hasColumn('students', 'stream_id')) {
                $table->integer('stream_id')->nullable();   // removed ->after('class_name')
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {

            // Remove the stream_id column ONLY if it exists
            if (Schema::hasColumn('students', 'stream_id')) {
                $table->dropColumn('stream_id');
            }
        });
    }
};
