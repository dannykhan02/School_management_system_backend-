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
            $this->command->info("Seeding subjects for school: {$school->name} (ID: {$school->id})");

            foreach ($subjectsData as $curriculum => $levels) {
                $this->command->line("  - Processing {$curriculum} curriculum...");
                
                foreach ($levels as $levelName => $levelData) {
                    $this->command->line("    - Seeding subjects for level: {$levelName}");
                    
                    foreach ($levelData['subjects'] as $subjectDetails) {
                        Subject::updateOrCreate(
                            [
                                'school_id' => $school->id,
                                'code' => $subjectDetails['code'],
                            ],
                            [
                                'name' => $subjectDetails['name'],
                                'curriculum_type' => $curriculum,
                                'grade_level' => $levelName,
                                'level' => $levelData['level'],
                                'pathway' => $levelData['pathway'] ?? null,
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
     * Returns a structured array of subjects for Kenyan curricula.
     */
    private function getSubjectsData(): array
    {
        return [
            'CBC' => [
                'PP1-PP2 (Pre-Primary)' => [
                    'level' => 'Pre-Primary',
                    'subjects' => [
                        ['name' => 'Language Activities', 'code' => 'CBC-PP-LAN', 'category' => 'Languages', 'is_core' => true],
                        ['name' => 'Mathematical Activities', 'code' => 'CBC-PP-MAT', 'category' => 'Mathematics', 'is_core' => true],
                        ['name' => 'Environmental Activities', 'code' => 'CBC-PP-ENV', 'category' => 'Sciences', 'is_core' => true],
                        ['name' => 'Psychomotor & Creative Activities', 'code' => 'CBC-PP-PSY', 'category' => 'Creative Arts', 'is_core' => true],
                        ['name' => 'Religious Education Activities', 'code' => 'CBC-PP-RE', 'category' => 'Humanities', 'is_core' => true],
                    ],
                ],
                'Grade 1-3 (Lower Primary)' => [
                    'level' => 'Primary',
                    'subjects' => [
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
                ],
                'Grade 4-6 (Upper Primary)' => [
                    'level' => 'Primary',
                    'subjects' => [
                        ['name' => 'English', 'code' => 'CBC-G4-ENG', 'category' => 'Languages', 'is_core' => true],
                        ['name' => 'Kiswahili', 'code' => 'CBC-G4-KIS', 'category' => 'Languages', 'is_core' => true],
                        ['name' => 'Mathematics', 'code' => 'CBC-G4-MAT', 'category' => 'Mathematics', 'is_core' => true],
                        ['name' => 'Science & Technology', 'code' => 'CBC-G4-SCI', 'category' => 'Sciences', 'is_core' => true],
                        ['name' => 'Social Studies', 'code' => 'CBC-G4-SST', 'category' => 'Humanities', 'is_core' => true],
                        ['name' => 'Religious Education', 'code' => 'CBC-G4-RE', 'category' => 'Humanities', 'is_core' => true],
                        ['name' => 'Creative Arts', 'code' => 'CBC-G4-CAR', 'category' => 'Creative Arts', 'is_core' => true],
                        ['name' => 'Physical Health Education', 'code' => 'CBC-G4-PHE', 'category' => 'Physical Ed', 'is_core' => true],
                        ['name' => 'Home Science', 'code' => 'CBC-G4-HSC', 'category' => 'Sciences', 'is_core' => true],
                        ['name' => 'Agriculture', 'code' => 'CBC-G4-AGR', 'category' => 'Sciences', 'is_core' => true],
                    ],
                ],
                'Grade 7-9 (Junior Secondary)' => [
                    'level' => 'Junior Secondary',
                    'subjects' => [
                        // Core Subjects
                        ['name' => 'English', 'code' => 'CBC-G7-ENG', 'category' => 'Languages', 'is_core' => true],
                        ['name' => 'Kiswahili', 'code' => 'CBC-G7-KIS', 'category' => 'Languages', 'is_core' => true],
                        ['name' => 'Mathematics', 'code' => 'CBC-G7-MAT', 'category' => 'Mathematics', 'is_core' => true],
                        ['name' => 'Integrated Science', 'code' => 'CBC-G7-SCI', 'category' => 'Sciences', 'is_core' => true],
                        ['name' => 'Health Education', 'code' => 'CBC-G7-HEA', 'category' => 'Sciences', 'is_core' => true],
                        ['name' => 'Social Studies', 'code' => 'CBC-G7-SST', 'category' => 'Humanities', 'is_core' => true],
                        ['name' => 'Religious Education', 'code' => 'CBC-G7-RE', 'category' => 'Humanities', 'is_core' => true],
                        ['name' => 'Life Skills Education', 'code' => 'CBC-G7-LSE', 'category' => 'Humanities', 'is_core' => true],
                        ['name' => 'Physical & Sports Education', 'code' => 'CBC-G7-PSE', 'category' => 'Physical Ed', 'is_core' => true],
                        ['name' => 'Business Studies', 'code' => 'CBC-G7-BUS', 'category' => 'Technical', 'is_core' => true],
                        ['name' => 'Agriculture', 'code' => 'CBC-G7-AGR', 'category' => 'Technical', 'is_core' => true],
                        ['name' => 'Pre-Technical & Pre-Career Studies', 'code' => 'CBC-G7-TEC', 'category' => 'Technical', 'is_core' => true],
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
                        ['name' => 'Indigenous Languages', 'code' => 'CBC-G7-ILG', 'category' => 'Languages', 'is_core' => false],
                    ],
                ],
                'Grade 10-12 (Senior Secondary - STEM Pathway)' => [
                    'level' => 'Senior Secondary',
                    'pathway' => 'STEM',
                    'subjects' => [
                        // Core Subjects
                        ['name' => 'English', 'code' => 'CBC-G10-ENG', 'category' => 'Languages', 'is_core' => true],
                        ['name' => 'Kiswahili', 'code' => 'CBC-G10-KIS', 'category' => 'Languages', 'is_core' => true],
                        ['name' => 'Mathematics', 'code' => 'CBC-G10-MAT', 'category' => 'Mathematics', 'is_core' => true],
                        // STEM Pathway Subjects
                        ['name' => 'Physics', 'code' => 'CBC-G10-PHY', 'category' => 'Sciences', 'is_core' => false],
                        ['name' => 'Chemistry', 'code' => 'CBC-G10-CHE', 'category' => 'Sciences', 'is_core' => false],
                        ['name' => 'Biology', 'code' => 'CBC-G10-BIO', 'category' => 'Sciences', 'is_core' => false],
                        ['name' => 'Computer Science', 'code' => 'CBC-G10-CSC', 'category' => 'Technical', 'is_core' => false],
                        ['name' => 'Engineering Science', 'code' => 'CBC-G10-ENG-SCI', 'category' => 'Technical', 'is_core' => false],
                        ['name' => 'Applied Mathematics', 'code' => 'CBC-G10-APM', 'category' => 'Mathematics', 'is_core' => false],
                        ['name' => 'Aviation Technology', 'code' => 'CBC-G10-AVI', 'category' => 'Technical', 'is_core' => false],
                    ],
                ],
                'Grade 10-12 (Senior Secondary - Arts & Sports Science)' => [
                    'level' => 'Senior Secondary',
                    'pathway' => 'Arts',
                    'subjects' => [
                        // Core Subjects
                        ['name' => 'English', 'code' => 'CBC-G10-ENG-AS', 'category' => 'Languages', 'is_core' => true],
                        ['name' => 'Kiswahili', 'code' => 'CBC-G10-KIS-AS', 'category' => 'Languages', 'is_core' => true],
                        ['name' => 'Mathematics', 'code' => 'CBC-G10-MAT-AS', 'category' => 'Mathematics', 'is_core' => true],
                        // Arts & Sports Pathway Subjects
                        ['name' => 'Music', 'code' => 'CBC-G10-MUS', 'category' => 'Creative Arts', 'is_core' => false],
                        ['name' => 'Visual Arts', 'code' => 'CBC-G10-VIS', 'category' => 'Creative Arts', 'is_core' => false],
                        ['name' => 'Performing Arts', 'code' => 'CBC-G10-PER', 'category' => 'Creative Arts', 'is_core' => false],
                        ['name' => 'Sports Science', 'code' => 'CBC-G10-SPT', 'category' => 'Physical Ed', 'is_core' => false],
                        ['name' => 'Film & Photography', 'code' => 'CBC-G10-FPH', 'category' => 'Creative Arts', 'is_core' => false],
                        ['name' => 'Design & Technology', 'code' => 'CBC-G10-DES', 'category' => 'Creative Arts', 'is_core' => false],
                    ],
                ],
                'Grade 10-12 (Senior Secondary - Social Sciences)' => [
                    'level' => 'Senior Secondary',
                    'pathway' => 'Social Sciences',
                    'subjects' => [
                        // Core Subjects
                        ['name' => 'English', 'code' => 'CBC-G10-ENG-SS', 'category' => 'Languages', 'is_core' => true],
                        ['name' => 'Kiswahili', 'code' => 'CBC-G10-KIS-SS', 'category' => 'Languages', 'is_core' => true],
                        ['name' => 'Mathematics', 'code' => 'CBC-G10-MAT-SS', 'category' => 'Mathematics', 'is_core' => true],
                        // Social Sciences Pathway Subjects
                        ['name' => 'History', 'code' => 'CBC-G10-HIS', 'category' => 'Humanities', 'is_core' => false],
                        ['name' => 'Geography', 'code' => 'CBC-G10-GEO', 'category' => 'Humanities', 'is_core' => false],
                        ['name' => 'Business Studies', 'code' => 'CBC-G10-BUS', 'category' => 'Technical', 'is_core' => false],
                        ['name' => 'Economics', 'code' => 'CBC-G10-ECO', 'category' => 'Humanities', 'is_core' => false],
                        ['name' => 'Religious Studies', 'code' => 'CBC-G10-REL', 'category' => 'Humanities', 'is_core' => false],
                        ['name' => 'Literature', 'code' => 'CBC-G10-LIT', 'category' => 'Languages', 'is_core' => false],
                        ['name' => 'Foreign Languages', 'code' => 'CBC-G10-FLN', 'category' => 'Languages', 'is_core' => false],
                        ['name' => 'Political Science', 'code' => 'CBC-G10-POL', 'category' => 'Humanities', 'is_core' => false],
                        ['name' => 'Sociology', 'code' => 'CBC-G10-SOC', 'category' => 'Humanities', 'is_core' => false],
                    ],
                ],
            ],
            '8-4-4' => [
                'Standard 1-4' => [
                    'level' => 'Primary',
                    'subjects' => [
                        ['name' => 'English', 'code' => '84-S1-ENG', 'category' => 'Languages', 'is_core' => true],
                        ['name' => 'Kiswahili', 'code' => '84-S1-KIS', 'category' => 'Languages', 'is_core' => true],
                        ['name' => 'Mathematics', 'code' => '84-S1-MAT', 'category' => 'Mathematics', 'is_core' => true],
                        ['name' => 'Science', 'code' => '84-S1-SCI', 'category' => 'Sciences', 'is_core' => true],
                        ['name' => 'Social Studies', 'code' => '84-S1-SST', 'category' => 'Humanities', 'is_core' => true],
                        ['name' => 'Religious Education', 'code' => '84-S1-RE', 'category' => 'Humanities', 'is_core' => true],
                        ['name' => 'Creative Arts', 'code' => '84-S1-CAR', 'category' => 'Creative Arts', 'is_core' => true],
                        ['name' => 'Physical Health Education', 'code' => '84-S1-PHE', 'category' => 'Physical Ed', 'is_core' => true],
                    ],
                ],
                'Standard 5-8' => [
                    'level' => 'Primary',
                    'subjects' => [
                        ['name' => 'English', 'code' => '84-S5-ENG', 'category' => 'Languages', 'is_core' => true],
                        ['name' => 'Kiswahili', 'code' => '84-S5-KIS', 'category' => 'Languages', 'is_core' => true],
                        ['name' => 'Mathematics', 'code' => '84-S5-MAT', 'category' => 'Mathematics', 'is_core' => true],
                        ['name' => 'Science', 'code' => '84-S5-SCI', 'category' => 'Sciences', 'is_core' => true],
                        ['name' => 'Social Studies', 'code' => '84-S5-SST', 'category' => 'Humanities', 'is_core' => true],
                        ['name' => 'Religious Education', 'code' => '84-S5-RE', 'category' => 'Humanities', 'is_core' => true],
                        ['name' => 'Creative Arts', 'code' => '84-S5-CAR', 'category' => 'Creative Arts', 'is_core' => true],
                        ['name' => 'Physical Health Education', 'code' => '84-S5-PHE', 'category' => 'Physical Ed', 'is_core' => true],
                    ],
                ],
                'Form 1-4 (Secondary)' => [
                    'level' => 'Secondary',
                    'subjects' => [
                        // Core Subjects
                        ['name' => 'English', 'code' => '84-F1-ENG', 'category' => 'Languages', 'is_core' => true],
                        ['name' => 'Kiswahili', 'code' => '84-F1-KIS', 'category' => 'Languages', 'is_core' => true],
                        ['name' => 'Mathematics', 'code' => '84-F1-MAT', 'category' => 'Mathematics', 'is_core' => true],
                        ['name' => 'Biology', 'code' => '84-F1-BIO', 'category' => 'Sciences', 'is_core' => true],
                        ['name' => 'Physics', 'code' => '84-F1-PHY', 'category' => 'Sciences', 'is_core' => true],
                        ['name' => 'Chemistry', 'code' => '84-F1-CHE', 'category' => 'Sciences', 'is_core' => true],
                        ['name' => 'History & Government', 'code' => '84-F1-HIS', 'category' => 'Humanities', 'is_core' => true],
                        ['name' => 'Geography', 'code' => '84-F1-GEO', 'category' => 'Humanities', 'is_core' => true],
                        ['name' => 'Christian Religious Education', 'code' => '84-F1-CRE', 'category' => 'Humanities', 'is_core' => false],
                        ['name' => 'Islamic Religious Education', 'code' => '84-F1-IRE', 'category' => 'Humanities', 'is_core' => false],
                        ['name' => 'Hindu Religious Education', 'code' => '84-F1-HRE', 'category' => 'Humanities', 'is_core' => false],
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
            ],
        ];
    }
}