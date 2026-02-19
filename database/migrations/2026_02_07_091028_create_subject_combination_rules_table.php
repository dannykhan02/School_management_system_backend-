<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('subject_combination_rules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('subject_id')
                  ->constrained()
                  ->cascadeOnDelete();

            $table->foreignId('stream_id')
                  ->constrained()
                  ->cascadeOnDelete();

            // Academic year already includes the term
            $table->foreignId('academic_year_id')
                  ->constrained()
                  ->cascadeOnDelete();

            $table->enum('rule_type', [
                'not_allowed_same_day',
                'not_allowed_same_period',
                'must_be_consecutive',
                'max_per_day'
            ]);

            $table->integer('max_per_day')->nullable();

            $table->timestamps();

            $table->unique(
                ['subject_id', 'stream_id', 'academic_year_id', 'rule_type'],
                'subject_combination_unique_rule'
            );
        });
    }

    public function down()
    {
        Schema::dropIfExists('subject_combination_rules');
    }
};
