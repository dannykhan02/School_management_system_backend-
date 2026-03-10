<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            // 1. Add admission_number only if it does NOT exist (it does, so this is skipped)
            if (!Schema::hasColumn('students', 'admission_number')) {
                $table->string('admission_number')->nullable()->after('school_id');
            }

            // 2. Add admission_number_is_manual if missing
            if (!Schema::hasColumn('students', 'admission_number_is_manual')) {
                $table->boolean('admission_number_is_manual')->default(false)->after('admission_number');
            }

            // 3. Add admitted_academic_year_id if missing
            if (!Schema::hasColumn('students', 'admitted_academic_year_id')) {
                $table->foreignId('admitted_academic_year_id')
                      ->nullable()
                      ->after('admission_number_is_manual')
                      ->constrained('academic_years')
                      ->nullOnDelete();
            }

            // 4. Modify admission_number to be nullable (it currently is NOT NULL)
            $table->string('admission_number')->nullable()->change();

            // 5. Drop the existing single‑column unique index on admission_number
            //    (common name is 'students_admission_number_unique')
            try {
                $table->dropUnique('students_admission_number_unique');
            } catch (\Illuminate\Database\QueryException $e) {
                // If the index has a different name, we ignore – the next step will add the composite index.
            }

            // 6. Create the composite unique index on (school_id, admission_number)
            $table->unique(['school_id', 'admission_number'], 'unique_admission_per_school');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            // Reverse changes: drop composite index
            $table->dropUnique('unique_admission_per_school');

            // Restore the single‑column unique index
            $table->unique('admission_number');

            // Remove the columns we added (admission_number itself is kept)
            $table->dropColumn([
                'admission_number_is_manual',
                'admitted_academic_year_id',
            ]);
        });
    }
};