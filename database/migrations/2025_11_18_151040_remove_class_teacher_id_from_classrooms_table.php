<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            if (Schema::hasColumn('classes', 'class_teacher_id')) {
                $table->dropForeign('classes_class_teacher_id_foreign');
                $table->dropColumn('class_teacher_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            if (!Schema::hasColumn('classes', 'class_teacher_id')) {
                $table->unsignedBigInteger('class_teacher_id')->nullable()->after('class_name');
                $table->foreign('class_teacher_id')
                      ->references('id')
                      ->on('teachers')
                      ->onDelete('set null');
            }
        });
    }
};
