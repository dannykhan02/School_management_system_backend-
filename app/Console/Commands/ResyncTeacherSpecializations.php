<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Teacher;

class ResyncTeacherSpecializations extends Command
{
    protected $signature   = 'teachers:resync-specializations';
    protected $description = 'Re-generate specialization strings for all teachers from their qualified subjects.';

    public function handle(): void
    {
        $teachers = Teacher::with('qualifiedSubjects')->get();

        $bar = $this->output->createProgressBar($teachers->count());
        $bar->start();

        foreach ($teachers as $teacher) {
            $teacher->updateSpecializationFromSubjects();
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("✓ Resynced specializations for {$teachers->count()} teacher(s).");
    }
}