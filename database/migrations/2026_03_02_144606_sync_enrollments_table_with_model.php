<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            // Middle name
            if (! Schema::hasColumn('enrollments', 'middle_name')) {
                $table->string('middle_name')->nullable()->after('last_name');
            }

            // Birth certificate number
            if (! Schema::hasColumn('enrollments', 'birth_certificate_number')) {
                $table->string('birth_certificate_number')->nullable()->after('religion');
            }

            // Parent address
            if (! Schema::hasColumn('enrollments', 'parent_address')) {
                $table->text('parent_address')->nullable()->after('parent_occupation');
            }

            // Last class attended (for transfers)
            if (! Schema::hasColumn('enrollments', 'last_class_attended')) {
                $table->string('last_class_attended')->nullable()->after('leaving_certificate_number');
            }

            // Approved by (who approved this enrollment)
            if (! Schema::hasColumn('enrollments', 'approved_by')) {
                $table->foreignId('approved_by')
                      ->nullable()
                      ->after('reviewed_by')
                      ->constrained('users')
                      ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            // Drop columns if they exist
            $columns = ['middle_name', 'birth_certificate_number', 'parent_address', 'last_class_attended'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('enrollments', $column)) {
                    $table->dropColumn($column);
                }
            }

            // Drop foreign key and column for approved_by
            if (Schema::hasColumn('enrollments', 'approved_by')) {
                $table->dropForeign(['approved_by']);
                $table->dropColumn('approved_by');
            }
        });
    }
};