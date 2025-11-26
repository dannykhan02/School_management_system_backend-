<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\School;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AcademicYearSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Fetch the School to associate the academic years with
        $school = School::where('name', 'Junior HighSchool')->first();

        if (!$school) {
            $this->command->error("School 'Junior HighSchool' not found. Cannot seed academic years.");
            return;
        }

        $this->command->info("Seeding academic years for school: {$school->name}");

        // Define the academic year data to seed
        $academicYearsData = [
            // Data for 2023
            ['year' => 2023, 'term' => 'Term 1', 'start_date' => '2023-01-23', 'end_date' => '2023-04-21', 'curriculum_type' => 'CBC', 'is_active' => false],
            ['year' => 2023, 'term' => 'Term 2', 'start_date' => '2023-05-15', 'end_date' => '2023-08-11', 'curriculum_type' => 'CBC', 'is_active' => false],
            ['year' => 2023, 'term' => 'Term 3', 'start_date' => '2023-09-04', 'end_date' => '2023-11-03', 'curriculum_type' => 'CBC', 'is_active' => false],
            
            // Data for 2024 (Where the previous error occurred)
            ['year' => 2024, 'term' => 'Term 1', 'start_date' => '2024-01-09', 'end_date' => '2024-03-31', 'curriculum_type' => 'CBC', 'is_active' => true],
            ['year' => 2024, 'term' => 'Term 2', 'start_date' => '2024-04-29', 'end_date' => '2024-08-02', 'curriculum_type' => 'CBC', 'is_active' => false],
            ['year' => 2024, 'term' => 'Term 3', 'start_date' => '2024-08-26', 'end_date' => '2024-11-01', 'curriculum_type' => 'CBC', 'is_active' => false],
        ];

        foreach ($academicYearsData as $data) {
            
            // Define the attributes that form the unique key (school_id, year, term)
            $attributes = [
                'school_id' => $school->id,
                'year' => $data['year'],
                'term' => $data['term'],
            ];

            // Define the remaining values for the record
            $values = [
                'curriculum_type' => $data['curriculum_type'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'is_active' => $data['is_active'],
            ];

            // Use firstOrCreate to prevent duplicates based on the unique constraint
            $academicYear = AcademicYear::firstOrCreate($attributes, $values);

            $status = $academicYear->wasRecentlyCreated ? 'Created' : 'Found';
            $active = $academicYear->is_active ? 'YES' : 'NO';

            $this->command->line("{$status}: {$school->name} - {$academicYear->year} {$academicYear->term} ({$academicYear->curriculum_type}) | Active: {$active}");
        }

        $this->command->info("Academic year seeding complete.");
    }
}