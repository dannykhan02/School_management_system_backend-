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
        Schema::table('schools', function (Blueprint $table) {
            // Add missing curriculum level columns
            if (!Schema::hasColumn('schools', 'has_senior_secondary')) {
                $table->boolean('has_senior_secondary')->default(false)->after('has_junior_secondary');
            }

            if (!Schema::hasColumn('schools', 'has_secondary')) {
                $table->boolean('has_secondary')->default(false)->after('has_senior_secondary');
            }

            // Add curriculum type column if missing
            if (!Schema::hasColumn('schools', 'secondary_curriculum')) {
                $table->string('secondary_curriculum')->nullable()->after('primary_curriculum');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            if (Schema::hasColumn('schools', 'has_senior_secondary')) {
                $table->dropColumn('has_senior_secondary');
            }
            if (Schema::hasColumn('schools', 'has_secondary')) {
                $table->dropColumn('has_secondary');
            }
            if (Schema::hasColumn('schools', 'secondary_curriculum')) {
                $table->dropColumn('secondary_curriculum');
            }
        });
    }
};
