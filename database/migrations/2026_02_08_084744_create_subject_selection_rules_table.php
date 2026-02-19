<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // First, rename the old table if it exists
        if (Schema::hasTable('subject_combination_rules')) {
            Schema::rename('subject_combination_rules', 'subject_combination_rules_backup');
        }
        
        // Create the new table
        Schema::create('subject_selection_rules', function (Blueprint $table) {
            $table->id();
            
            // For which curriculum and level
            $table->enum('curriculum_type', ['CBC', '8-4-4']);
            $table->string('level'); // e.g., 'Form 3-4', 'Grade 10-12'
            
            // Rule definition
            $table->enum('rule_type', [
                'max_sciences',           // Max 3 sciences in 8-4-4
                'min_languages',          // Must have at least 2 languages
                'required_subject',       // Math/English compulsory
                'incompatible_subjects',  // Can't take X and Y together
                'prerequisite_subject'    // Must take X to take Y
            ]);
            
            $table->json('subject_ids')->nullable(); // Which subjects this rule applies to
            $table->integer('max_count')->nullable(); // For max_sciences = 3
            $table->integer('min_count')->nullable(); // For min_languages = 2
            
            $table->text('description'); // Human readable rule
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
            
            // Add composite index for efficient querying
            $table->index(['curriculum_type', 'level', 'is_active']);
        });
        
        // Create a pivot table for incompatible subjects (optional but recommended)
        Schema::create('incompatible_subject_pairs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_selection_rule_id')
                  ->constrained('subject_selection_rules')
                  ->cascadeOnDelete();
            
            $table->foreignId('subject_id')
                  ->constrained()
                  ->cascadeOnDelete();
            
            $table->foreignId('incompatible_with_subject_id')
                  ->constrained('subjects')
                  ->cascadeOnDelete();
            
            $table->timestamps();
            
            $table->unique(['subject_selection_rule_id', 'subject_id', 'incompatible_with_subject_id'], 
                          'incompatible_subject_pair_unique');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('incompatible_subject_pairs');
        Schema::dropIfExists('subject_selection_rules');
        
        // Restore old table if it was renamed
        if (Schema::hasTable('subject_combination_rules_backup')) {
            Schema::rename('subject_combination_rules_backup', 'subject_combination_rules');
        }
    }
};