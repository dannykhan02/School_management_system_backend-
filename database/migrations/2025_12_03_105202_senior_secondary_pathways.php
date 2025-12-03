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
            // Add curriculum type fields only if they don't exist
            if (!Schema::hasColumn('schools', 'primary_curriculum')) {
                $table->enum('primary_curriculum', ['CBC', '8-4-4', 'Both'])->default('CBC')->after('email');
            }

            if (!Schema::hasColumn('schools', 'secondary_curriculum')) {
                $table->string('secondary_curriculum')->nullable()->after('primary_curriculum');
            }

            // Add level fields
            if (!Schema::hasColumn('schools', 'has_pre_primary')) {
                $table->boolean('has_pre_primary')->default(false)->after('secondary_curriculum');
            }

            if (!Schema::hasColumn('schools', 'has_primary')) {
                $table->boolean('has_primary')->default(false)->after('has_pre_primary');
            }

            if (!Schema::hasColumn('schools', 'has_junior_secondary')) {
                $table->boolean('has_junior_secondary')->default(false)->after('has_primary');
            }

            if (!Schema::hasColumn('schools', 'has_senior_secondary')) {
                $table->boolean('has_senior_secondary')->default(false)->after('has_junior_secondary');
            }

            if (!Schema::hasColumn('schools', 'has_secondary')) {
                $table->boolean('has_secondary')->default(false)->after('has_senior_secondary');
            }

            // Add pathway field for Senior Secondary
            if (!Schema::hasColumn('schools', 'senior_secondary_pathways')) {
                $table->json('senior_secondary_pathways')->nullable()->after('has_secondary');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $columns = [
                'primary_curriculum',
                'secondary_curriculum',
                'has_pre_primary',
                'has_primary',
                'has_junior_secondary',
                'has_senior_secondary',
                'has_secondary',
                'senior_secondary_pathways'
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('schools', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
