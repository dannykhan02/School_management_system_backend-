<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Subject;
use App\Models\School;

class SubjectSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $schools = School::all();

        if ($schools->isEmpty()) {
            $this->command->info('No schools found. Skipping SubjectSeeder.');
            return;
        }

        // Define subjects for each curriculum and grade level
        $subjectsData = $this->getSubjectsData();

        foreach ($schools as $school) {
            $this->command->info("Seeding subjects for school: {$school->name}");

            foreach ($subjectsData as $curriculum => $grades) {
                foreach ($grades as $gradeLevel => $subjects) {
                    foreach ($subjects as $subjectDetails) {
                        Subject::firstOrCreate(
                            [
                                'school_id' => $school->id,
                                'code' => $subjectDetails['code'],
                                'curriculum_type' => $curriculum,
                                'grade_level' => $gradeLevel,
                            ],
                            [
                                'name' => $subjectDetails['name'],
                                'category' => $subjectDetails['category'],
                                'is_core' => $subjectDetails['is_core'],
                            ]
                        );
                    }
                }
            }
        }

        $this->command->info('✓ SubjectSeeder finished successfully!');
    }

    /**
     * Returns a structured array of subjects for the Kenyan curriculum.
     */
    private function getSubjectsData(): array
    {
        return [
            'CBC' => [
                'Grade 1-3 (Lower Primary)' => [
                    ['name' => 'English', 'code' => 'CBC-G1-ENG', 'category' => 'Languages', 'is_core' => true],
                    ['name' => 'Kiswahili', 'code' => 'CBC-G1-KIS', 'category' => 'Languages', 'is_core' => true],
                    ['name' => 'Indigenous Language', 'code' => 'CBC-G1-IND', 'category' => 'Languages', 'is_core' => true],
                    ['name' => 'Literacy Activities', 'code' => 'CBC-G1-LIT', 'category' => 'Languages', 'is_core' => true],
                    ['name' => 'Mathematics', 'code' => 'CBC-G1-MAT', 'category' => 'Mathematics', 'is_core' => true],
                    ['name' => 'Environmental Activities', 'code' => 'CBC-G1-ENV', 'category' => 'Sciences', 'is_core' => true],
                    ['name' => 'Hygiene & Nutrition', 'code' => 'CBC-G1-HYN', 'category' => 'Sciences', 'is_core' => true],
                    ['name' => 'Religious Education', 'code' => 'CBC-G1-RE', 'category' => 'Humanities', 'is_core' => true],
                    ['name' => 'Movement & Creative Activities', 'code' => 'CBC-G1-MCA', 'category' => 'Creative Arts', 'is_core' => true],
                ],
                'Grade 4-6 (Upper Primary)' => [
                    ['name' => 'English', 'code' => 'CBC-G4-ENG', 'category' => 'Languages', 'is_core' => true],
                    ['name' => 'Kiswahili', 'code' => 'CBC-G4-KIS', 'category' => 'Languages', 'is_core' => true],
                    ['name' => 'Home Science', 'code' => 'CBC-G4-HSC', 'category' => 'Sciences', 'is_core' => true],
                    ['name' => 'Agriculture', 'code' => 'CBC-G4-AGR', 'category' => 'Sciences', 'is_core' => true],
                    ['name' => 'Mathematics', 'code' => 'CBC-G4-MAT', 'category' => 'Mathematics', 'is_core' => true],
                    ['name' => 'Science & Technology', 'code' => 'CBC-G4-SCI', 'category' => 'Sciences', 'is_core' => true],
                    ['name' => 'Social Studies', 'code' => 'CBC-G4-SST', 'category' => 'Humanities', 'is_core' => true],
                    ['name' => 'Religious Education', 'code' => 'CBC-G4-RE', 'category' => 'Humanities', 'is_core' => true],
                    ['name' => 'Creative Arts', 'code' => 'CBC-G4-CAR', 'category' => 'Creative Arts', 'is_core' => true],
                    ['name' => 'Physical Health Education', 'code' => 'CBC-G4-PHE', 'category' => 'Physical Ed', 'is_core' => true],
                ],
                'Grade 7-9 (Junior Secondary)' => [
                    // Core Subjects
                    ['name' => 'English', 'code' => 'CBC-G7-ENG', 'category' => 'Languages', 'is_core' => true],
                    ['name' => 'Kiswahili', 'code' => 'CBC-G7-KIS', 'category' => 'Languages', 'is_core' => true],
                    ['name' => 'Mathematics', 'code' => 'CBC-G7-MAT', 'category' => 'Mathematics', 'is_core' => true],
                    ['name' => 'Integrated Science', 'code' => 'CBC-G7-SCI', 'category' => 'Sciences', 'is_core' => true],
                    ['name' => 'Social Studies', 'code' => 'CBC-G7-SST', 'category' => 'Humanities', 'is_core' => true],
                    ['name' => 'Religious Education', 'code' => 'CBC-G7-RE', 'category' => 'Humanities', 'is_core' => true],
                    ['name' => 'Business Studies', 'code' => 'CBC-G7-BUS', 'category' => 'Technical', 'is_core' => true],
                    ['name' => 'Agriculture & Nutrition', 'code' => 'CBC-G7-AGR', 'category' => 'Technical', 'is_core' => true],
                    ['name' => 'Pre-Technical & Pre-Career', 'code' => 'CBC-G7-TEC', 'category' => 'Technical', 'is_core' => true],
                    // Optional Subjects (Electives)
                    ['name' => 'Visual Arts', 'code' => 'CBC-G7-VIS', 'category' => 'Creative Arts', 'is_core' => false],
                    ['name' => 'Performing Arts', 'code' => 'CBC-G7-PER', 'category' => 'Creative Arts', 'is_core' => false],
                    ['name' => 'Home Science', 'code' => 'CBC-G7-HSC', 'category' => 'Sciences', 'is_core' => false],
                    ['name' => 'Computer Science', 'code' => 'CBC-G7-CSC', 'category' => 'Technical', 'is_core' => false],
                    ['name' => 'French', 'code' => 'CBC-G7-FRE', 'category' => 'Languages', 'is_core' => false],
                    ['name' => 'German', 'code' => 'CBC-G7-GER', 'category' => 'Languages', 'is_core' => false],
                    ['name' => 'Arabic', 'code' => 'CBC-G7-ARA', 'category' => 'Languages', 'is_core' => false],
                    ['name' => 'Mandarin', 'code' => 'CBC-G7-MAN', 'category' => 'Languages', 'is_core' => false],
                    ['name' => 'Kenyan Sign Language', 'code' => 'CBC-G7-KSL', 'category' => 'Languages', 'is_core' => false],
                    ['name' => 'Indigenous Languages', 'code' => 'CBC-G7-IND', 'category' => 'Languages', 'is_core' => false],
                ],
            ],
            '8-4-4' => [
                'Standard 1-4' => [
                    ['name' => 'English', 'code' => '84-S1-ENG', 'category' => 'Languages', 'is_core' => true],
                    ['name' => 'Kiswahili', 'code' => '84-S1-KIS', 'category' => 'Languages', 'is_core' => true],
                    ['name' => 'Mathematics', 'code' => '84-S1-MAT', 'category' => 'Mathematics', 'is_core' => true],
                    ['name' => 'Science', 'code' => '84-S1-SCI', 'category' => 'Sciences', 'is_core' => true],
                    ['name' => 'Social Studies', 'code' => '84-S1-SST', 'category' => 'Humanities', 'is_core' => true],
                    ['name' => 'Religious Education', 'code' => '84-S1-RE', 'category' => 'Humanities', 'is_core' => true],
                    ['name' => 'Creative Arts', 'code' => '84-S1-CAR', 'category' => 'Creative Arts', 'is_core' => true],
                    ['name' => 'Physical Health Education', 'code' => '84-S1-PHE', 'category' => 'Physical Ed', 'is_core' => true],
                ],
                'Standard 5-8' => [
                    ['name' => 'English', 'code' => '84-S5-ENG', 'category' => 'Languages', 'is_core' => true],
                    ['name' => 'Kiswahili', 'code' => '84-S5-KIS', 'category' => 'Languages', 'is_core' => true],
                    ['name' => 'Mathematics', 'code' => '84-S5-MAT', 'category' => 'Mathematics', 'is_core' => true],
                    ['name' => 'Science', 'code' => '84-S5-SCI', 'category' => 'Sciences', 'is_core' => true],
                    ['name' => 'Social Studies', 'code' => '84-S5-SST', 'category' => 'Humanities', 'is_core' => true],
                    ['name' => 'Religious Education', 'code' => '84-S5-RE', 'category' => 'Humanities', 'is_core' => true],
                    ['name' => 'Creative Arts', 'code' => '84-S5-CAR', 'category' => 'Creative Arts', 'is_core' => true],
                    ['name' => 'Physical Health Education', 'code' => '84-S5-PHE', 'category' => 'Physical Ed', 'is_core' => true],
                ],
                'Form 1-4' => [
                    // Core Subjects
                    ['name' => 'English', 'code' => '84-F1-ENG', 'category' => 'Languages', 'is_core' => true],
                    ['name' => 'Kiswahili', 'code' => '84-F1-KIS', 'category' => 'Languages', 'is_core' => true],
                    ['name' => 'Mathematics', 'code' => '84-F1-MAT', 'category' => 'Mathematics', 'is_core' => true],
                    ['name' => 'Biology', 'code' => '84-F1-BIO', 'category' => 'Sciences', 'is_core' => true],
                    ['name' => 'Physics', 'code' => '84-F1-PHY', 'category' => 'Sciences', 'is_core' => true],
                    ['name' => 'Chemistry', 'code' => '84-F1-CHE', 'category' => 'Sciences', 'is_core' => true],
                    ['name' => 'History & Government', 'code' => '84-F1-HIS', 'category' => 'Humanities', 'is_core' => true],
                    ['name' => 'Geography', 'code' => '84-F1-GEO', 'category' => 'Humanities', 'is_core' => true],
                    ['name' => 'Christian Religious Education', 'code' => '84-F1-CRE', 'category' => 'Humanities', 'is_core' => true],
                    ['name' => 'Islamic Religious Education', 'code' => '84-F1-IRE', 'category' => 'Humanities', 'is_core' => true],
                    ['name' => 'Hindu Religious Education', 'code' => '84-F1-HRE', 'category' => 'Humanities', 'is_core' => true],
                    // Optional Subjects (Electives)
                    ['name' => 'Business Studies', 'code' => '84-F1-BUS', 'category' => 'Technical', 'is_core' => false],
                    ['name' => 'Agriculture', 'code' => '84-F1-AGR', 'category' => 'Technical', 'is_core' => false],
                    ['name' => 'Computer Studies', 'code' => '84-F1-COM', 'category' => 'Technical', 'is_core' => false],
                    ['name' => 'Home Science', 'code' => '84-F1-HOM', 'category' => 'Technical', 'is_core' => false],
                    ['name' => 'French', 'code' => '84-F1-FRE', 'category' => 'Languages', 'is_core' => false],
                    ['name' => 'German', 'code' => '84-F1-GER', 'category' => 'Languages', 'is_core' => false],
                    ['name' => 'Arabic', 'code' => '84-F1-ARA', 'category' => 'Languages', 'is_core' => false],
                    ['name' => 'Music', 'code' => '84-F1-MUS', 'category' => 'Creative Arts', 'is_core' => false],
                    ['name' => 'Art & Design', 'code' => '84-F1-ART', 'category' => 'Creative Arts', 'is_core' => false],
                ],
            ],
        ];
    }
}