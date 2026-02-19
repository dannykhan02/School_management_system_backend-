<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Disable foreign key checks temporarily
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            // Drop all foreign keys referencing 'classes' table
            $this->dropForeignKeysReferencingClasses();

            // Check if classes table exists
            if (Schema::hasTable('classes')) {
                // Check if classrooms already exists
                if (Schema::hasTable('classrooms')) {
                    // Drop the old classes table
                    Schema::drop('classes');
                } else {
                    // Rename classes to classrooms
                    Schema::rename('classes', 'classrooms');
                }
            }

            // Re-add foreign keys pointing to classrooms
            $this->addForeignKeysToClassrooms();

        } finally {
            // Re-enable foreign key checks
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /**
     * Drop all foreign keys that reference the 'classes' table
     */
    private function dropForeignKeysReferencingClasses(): void
    {
        // Find all foreign keys referencing classes table
        $foreignKeys = DB::select("
            SELECT TABLE_NAME, CONSTRAINT_NAME
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE REFERENCED_TABLE_NAME = 'classes'
            AND REFERENCED_TABLE_SCHEMA = DATABASE()
        ");

        foreach ($foreignKeys as $fk) {
            try {
                DB::statement("ALTER TABLE {$fk->TABLE_NAME} DROP FOREIGN KEY {$fk->CONSTRAINT_NAME}");
            } catch (\Exception $e) {
                // Constraint might not exist, continue
                continue;
            }
        }
    }

    /**
     * Add foreign keys pointing to 'classrooms' table
     */
    private function addForeignKeysToClassrooms(): void
    {
        // Helper function to check if foreign key exists
        $foreignKeyExists = function ($table, $column) {
            $result = DB::select("
                SELECT COUNT(*) as count
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND COLUMN_NAME = ?
                AND REFERENCED_TABLE_NAME = 'classrooms'
            ", [$table, $column]);
            
            return $result[0]->count > 0;
        };

        // classroom_teacher table
        if (Schema::hasTable('classroom_teacher') && 
            Schema::hasColumn('classroom_teacher', 'classroom_id') &&
            !$foreignKeyExists('classroom_teacher', 'classroom_id')) {
            
            Schema::table('classroom_teacher', function (Blueprint $table) {
                $table->foreign('classroom_id')
                      ->references('id')
                      ->on('classrooms')
                      ->onDelete('cascade');
            });
        }

        // streams table
        if (Schema::hasTable('streams') && 
            Schema::hasColumn('streams', 'class_id') &&
            !$foreignKeyExists('streams', 'class_id')) {
            
            Schema::table('streams', function (Blueprint $table) {
                $table->foreign('class_id')
                      ->references('id')
                      ->on('classrooms')
                      ->onDelete('cascade');
            });
        }

        // student_classes table
        if (Schema::hasTable('student_classes') && 
            Schema::hasColumn('student_classes', 'class_id') &&
            !$foreignKeyExists('student_classes', 'class_id')) {
            
            Schema::table('student_classes', function (Blueprint $table) {
                $table->foreign('class_id')
                      ->references('id')
                      ->on('classrooms')
                      ->onDelete('cascade');
            });
        }

        // students table (if it exists)
        if (Schema::hasTable('students') && 
            Schema::hasColumn('students', 'class_id') &&
            !$foreignKeyExists('students', 'class_id')) {
            
            Schema::table('students', function (Blueprint $table) {
                $table->foreign('class_id')
                      ->references('id')
                      ->on('classrooms')
                      ->onDelete('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Disable foreign key checks temporarily
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            // Drop all foreign keys referencing 'classrooms' table
            $foreignKeys = DB::select("
                SELECT TABLE_NAME, CONSTRAINT_NAME
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                WHERE REFERENCED_TABLE_NAME = 'classrooms'
                AND REFERENCED_TABLE_SCHEMA = DATABASE()
            ");

            foreach ($foreignKeys as $fk) {
                try {
                    DB::statement("ALTER TABLE {$fk->TABLE_NAME} DROP FOREIGN KEY {$fk->CONSTRAINT_NAME}");
                } catch (\Exception $e) {
                    // Constraint might not exist, continue
                    continue;
                }
            }

            // Rename back if classrooms exists
            if (Schema::hasTable('classrooms')) {
                Schema::rename('classrooms', 'classes');
            }

            // Re-add foreign keys pointing to classes
            $this->reAddForeignKeysToClasses();

        } finally {
            // Re-enable foreign key checks
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /**
     * Re-add foreign keys pointing to 'classes' table for down migration
     */
    private function reAddForeignKeysToClasses(): void
    {
        // Helper function to check if foreign key exists
        $foreignKeyExists = function ($table, $column) {
            $result = DB::select("
                SELECT COUNT(*) as count
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND COLUMN_NAME = ?
                AND REFERENCED_TABLE_NAME = 'classes'
            ", [$table, $column]);
            
            return $result[0]->count > 0;
        };

        // classroom_teacher table (if we're rolling back, it should have class_id)
        if (Schema::hasTable('classroom_teacher') && 
            Schema::hasColumn('classroom_teacher', 'classroom_id')) {
            
            // Rename column back to class_id if needed
            Schema::table('classroom_teacher', function (Blueprint $table) {
                if (Schema::hasColumn('classroom_teacher', 'classroom_id') && 
                    !Schema::hasColumn('classroom_teacher', 'class_id')) {
                    $table->renameColumn('classroom_id', 'class_id');
                }
            });
            
            // Add foreign key to classes
            if (Schema::hasColumn('classroom_teacher', 'class_id') &&
                !$foreignKeyExists('classroom_teacher', 'class_id')) {
                
                Schema::table('classroom_teacher', function (Blueprint $table) {
                    $table->foreign('class_id')
                          ->references('id')
                          ->on('classes')
                          ->onDelete('cascade');
                });
            }
        }

        // streams table
        if (Schema::hasTable('streams') && 
            Schema::hasColumn('streams', 'class_id') &&
            !$foreignKeyExists('streams', 'class_id')) {
            
            Schema::table('streams', function (Blueprint $table) {
                $table->foreign('class_id')
                      ->references('id')
                      ->on('classes')
                      ->onDelete('cascade');
            });
        }

        // student_classes table
        if (Schema::hasTable('student_classes') && 
            Schema::hasColumn('student_classes', 'class_id') &&
            !$foreignKeyExists('student_classes', 'class_id')) {
            
            Schema::table('student_classes', function (Blueprint $table) {
                $table->foreign('class_id')
                      ->references('id')
                      ->on('classes')
                      ->onDelete('cascade');
            });
        }

        // students table
        if (Schema::hasTable('students') && 
            Schema::hasColumn('students', 'class_id') &&
            !$foreignKeyExists('students', 'class_id')) {
            
            Schema::table('students', function (Blueprint $table) {
                $table->foreign('class_id')
                      ->references('id')
                      ->on('classes')
                      ->onDelete('cascade');
            });
        }
    }
};