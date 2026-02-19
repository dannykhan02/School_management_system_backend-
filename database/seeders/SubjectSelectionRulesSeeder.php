<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\SubjectSelectionRule;
use App\Models\Subject;

class SubjectSelectionRulesSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Seeding subject selection rules...');

        // CBC Rules (Primary Curriculum for Kenya)
        $this->createCBCRules();
        
        // 8-4-4 Rules (Legacy System)
        $this->create844Rules();

        $this->command->info('✓ Subject selection rules seeded successfully!');
    }

    /**
     * Create CBC (Competency-Based Curriculum) Rules
     * This is the main curriculum system in Kenya
     */
    private function createCBCRules()
    {
        $this->command->line('  - Creating CBC curriculum rules...');

        // ============================================
        // PRE-PRIMARY RULES (PP1-PP2)
        // ============================================
        $this->command->line('    • Pre-Primary: All subjects compulsory');
        
        $prePrimarySubjects = Subject::where('curriculum_type', 'CBC')
            ->where('level', 'Pre-Primary')
            ->pluck('id')
            ->toArray();

        if (!empty($prePrimarySubjects)) {
            SubjectSelectionRule::create([
                'curriculum_type' => 'CBC',
                'level' => 'Pre-Primary',
                'rule_type' => 'required_subject',
                'subject_ids' => $prePrimarySubjects,
                'min_count' => count($prePrimarySubjects),
                'description' => 'All Pre-Primary activities are compulsory (Language, Mathematical, Environmental, Psychomotor & Creative, Religious Education)',
                'is_active' => true,
            ]);
        }

        // ============================================
        // PRIMARY RULES (Grade 1-6)
        // ============================================
        $this->command->line('    • Primary: All subjects compulsory');
        
        $primarySubjects = Subject::where('curriculum_type', 'CBC')
            ->where('level', 'Primary')
            ->pluck('id')
            ->toArray();

        if (!empty($primarySubjects)) {
            SubjectSelectionRule::create([
                'curriculum_type' => 'CBC',
                'level' => 'Primary',
                'rule_type' => 'required_subject',
                'subject_ids' => $primarySubjects,
                'min_count' => count($primarySubjects),
                'description' => 'All Primary subjects are compulsory as per CBC guidelines',
                'is_active' => true,
            ]);
        }

        // ============================================
        // JUNIOR SECONDARY RULES (Grade 7-9)
        // ============================================
        $this->command->line('    • Junior Secondary: Core + Optional subjects');
        
        // Rule 1: All core subjects are compulsory
        $juniorCoreSubjects = Subject::where('curriculum_type', 'CBC')
            ->where('level', 'Junior Secondary')
            ->where('is_core', true)
            ->pluck('id')
            ->toArray();

        if (!empty($juniorCoreSubjects)) {
            SubjectSelectionRule::create([
                'curriculum_type' => 'CBC',
                'level' => 'Junior Secondary',
                'rule_type' => 'required_subject',
                'subject_ids' => $juniorCoreSubjects,
                'min_count' => count($juniorCoreSubjects),
                'description' => 'All core subjects are compulsory in Junior Secondary (English, Kiswahili, Mathematics, Integrated Science, Social Studies, etc.)',
                'is_active' => true,
            ]);
        }

        // Rule 2: Must take at least 2 optional subjects
        $juniorOptionalSubjects = Subject::where('curriculum_type', 'CBC')
            ->where('level', 'Junior Secondary')
            ->where('is_core', false)
            ->pluck('id')
            ->toArray();

        if (!empty($juniorOptionalSubjects)) {
            SubjectSelectionRule::create([
                'curriculum_type' => 'CBC',
                'level' => 'Junior Secondary',
                'rule_type' => 'min_languages',
                'subject_ids' => $juniorOptionalSubjects,
                'min_count' => 2,
                'description' => 'Students must select at least 2 optional subjects (e.g., Visual Arts, Performing Arts, Foreign Languages, Computer Science)',
                'is_active' => true,
            ]);
        }

        // Rule 3: Maximum 4 optional subjects
        if (!empty($juniorOptionalSubjects)) {
            SubjectSelectionRule::create([
                'curriculum_type' => 'CBC',
                'level' => 'Junior Secondary',
                'rule_type' => 'max_sciences',
                'subject_ids' => $juniorOptionalSubjects,
                'max_count' => 4,
                'description' => 'Students can select a maximum of 4 optional subjects',
                'is_active' => true,
            ]);
        }

        // Rule 4: Foreign languages (optional - student can choose max 2)
        $foreignLanguages = Subject::where('curriculum_type', 'CBC')
            ->where('level', 'Junior Secondary')
            ->whereIn('name', ['French', 'German', 'Arabic', 'Mandarin', 'Kenyan Sign Language'])
            ->pluck('id')
            ->toArray();

        if (!empty($foreignLanguages)) {
            SubjectSelectionRule::create([
                'curriculum_type' => 'CBC',
                'level' => 'Junior Secondary',
                'rule_type' => 'max_sciences',
                'subject_ids' => $foreignLanguages,
                'max_count' => 2,
                'description' => 'Students can select a maximum of 2 foreign languages',
                'is_active' => true,
            ]);
        }

        // ============================================
        // SENIOR SECONDARY - STEM PATHWAY (Grade 10-12)
        // ============================================
        $this->command->line('    • Senior Secondary STEM: Core + Pathway subjects');
        
        // Rule 1: Core subjects (English, Kiswahili, Mathematics) are compulsory
        $stemCoreSubjects = Subject::where('curriculum_type', 'CBC')
            ->where('level', 'Senior Secondary')
            ->where('pathway', 'STEM')
            ->where('is_core', true)
            ->pluck('id')
            ->toArray();

        if (!empty($stemCoreSubjects)) {
            SubjectSelectionRule::create([
                'curriculum_type' => 'CBC',
                'level' => 'Senior Secondary - STEM',
                'rule_type' => 'required_subject',
                'subject_ids' => $stemCoreSubjects,
                'min_count' => count($stemCoreSubjects),
                'description' => 'English, Kiswahili, and Mathematics are compulsory in STEM pathway',
                'is_active' => true,
            ]);
        }

        // Rule 2: Must take at least 3 STEM subjects
        $stemElectives = Subject::where('curriculum_type', 'CBC')
            ->where('level', 'Senior Secondary')
            ->where('pathway', 'STEM')
            ->where('is_core', false)
            ->pluck('id')
            ->toArray();

        if (!empty($stemElectives)) {
            SubjectSelectionRule::create([
                'curriculum_type' => 'CBC',
                'level' => 'Senior Secondary - STEM',
                'rule_type' => 'min_languages',
                'subject_ids' => $stemElectives,
                'min_count' => 3,
                'description' => 'Students must select at least 3 STEM subjects (e.g., Physics, Chemistry, Biology, Computer Science)',
                'is_active' => true,
            ]);
        }

        // Rule 3: Maximum 5 STEM subjects
        if (!empty($stemElectives)) {
            SubjectSelectionRule::create([
                'curriculum_type' => 'CBC',
                'level' => 'Senior Secondary - STEM',
                'rule_type' => 'max_sciences',
                'subject_ids' => $stemElectives,
                'max_count' => 5,
                'description' => 'Students can select a maximum of 5 STEM subjects',
                'is_active' => true,
            ]);
        }

        // ============================================
        // SENIOR SECONDARY - ARTS PATHWAY (Grade 10-12)
        // ============================================
        $this->command->line('    • Senior Secondary Arts: Core + Pathway subjects');
        
        // Rule 1: Core subjects compulsory
        $artsCoreSubjects = Subject::where('curriculum_type', 'CBC')
            ->where('level', 'Senior Secondary')
            ->where('pathway', 'Arts')
            ->where('is_core', true)
            ->pluck('id')
            ->toArray();

        if (!empty($artsCoreSubjects)) {
            SubjectSelectionRule::create([
                'curriculum_type' => 'CBC',
                'level' => 'Senior Secondary - Arts',
                'rule_type' => 'required_subject',
                'subject_ids' => $artsCoreSubjects,
                'min_count' => count($artsCoreSubjects),
                'description' => 'English, Kiswahili, and Mathematics are compulsory in Arts pathway',
                'is_active' => true,
            ]);
        }

        // Rule 2: Must take at least 3 Arts subjects
        $artsElectives = Subject::where('curriculum_type', 'CBC')
            ->where('level', 'Senior Secondary')
            ->where('pathway', 'Arts')
            ->where('is_core', false)
            ->pluck('id')
            ->toArray();

        if (!empty($artsElectives)) {
            SubjectSelectionRule::create([
                'curriculum_type' => 'CBC',
                'level' => 'Senior Secondary - Arts',
                'rule_type' => 'min_languages',
                'subject_ids' => $artsElectives,
                'min_count' => 3,
                'description' => 'Students must select at least 3 Arts subjects (e.g., Music, Visual Arts, Performing Arts, Sports Science)',
                'is_active' => true,
            ]);
        }

        // Rule 3: Maximum 5 Arts subjects
        if (!empty($artsElectives)) {
            SubjectSelectionRule::create([
                'curriculum_type' => 'CBC',
                'level' => 'Senior Secondary - Arts',
                'rule_type' => 'max_sciences',
                'subject_ids' => $artsElectives,
                'max_count' => 5,
                'description' => 'Students can select a maximum of 5 Arts subjects',
                'is_active' => true,
            ]);
        }

        // ============================================
        // SENIOR SECONDARY - SOCIAL SCIENCES PATHWAY (Grade 10-12)
        // ============================================
        $this->command->line('    • Senior Secondary Social Sciences: Core + Pathway subjects');
        
        // Rule 1: Core subjects compulsory
        $socialCoreSubjects = Subject::where('curriculum_type', 'CBC')
            ->where('level', 'Senior Secondary')
            ->where('pathway', 'Social Sciences')
            ->where('is_core', true)
            ->pluck('id')
            ->toArray();

        if (!empty($socialCoreSubjects)) {
            SubjectSelectionRule::create([
                'curriculum_type' => 'CBC',
                'level' => 'Senior Secondary - Social Sciences',
                'rule_type' => 'required_subject',
                'subject_ids' => $socialCoreSubjects,
                'min_count' => count($socialCoreSubjects),
                'description' => 'English, Kiswahili, and Mathematics are compulsory in Social Sciences pathway',
                'is_active' => true,
            ]);
        }

        // Rule 2: Must take at least 3 Social Science subjects
        $socialElectives = Subject::where('curriculum_type', 'CBC')
            ->where('level', 'Senior Secondary')
            ->where('pathway', 'Social Sciences')
            ->where('is_core', false)
            ->pluck('id')
            ->toArray();

        if (!empty($socialElectives)) {
            SubjectSelectionRule::create([
                'curriculum_type' => 'CBC',
                'level' => 'Senior Secondary - Social Sciences',
                'rule_type' => 'min_languages',
                'subject_ids' => $socialElectives,
                'min_count' => 3,
                'description' => 'Students must select at least 3 Social Science subjects (e.g., History, Geography, Business Studies, Economics)',
                'is_active' => true,
            ]);
        }

        // Rule 3: Maximum 5 Social Science subjects
        if (!empty($socialElectives)) {
            SubjectSelectionRule::create([
                'curriculum_type' => 'CBC',
                'level' => 'Senior Secondary - Social Sciences',
                'rule_type' => 'max_sciences',
                'subject_ids' => $socialElectives,
                'max_count' => 5,
                'description' => 'Students can select a maximum of 5 Social Science subjects',
                'is_active' => true,
            ]);
        }

        // ============================================
        // CROSS-PATHWAY RULES (All Senior Secondary)
        // ============================================
        $this->command->line('    • Cross-pathway restrictions');
        
        // Rule: Students can take subjects from other pathways (max 2)
        $allSeniorSubjects = Subject::where('curriculum_type', 'CBC')
            ->where('level', 'Senior Secondary')
            ->where('is_core', false)
            ->pluck('id')
            ->toArray();

        if (!empty($allSeniorSubjects)) {
            SubjectSelectionRule::create([
                'curriculum_type' => 'CBC',
                'level' => 'Senior Secondary - All Pathways',
                'rule_type' => 'max_sciences',
                'subject_ids' => $allSeniorSubjects,
                'max_count' => 7,
                'description' => 'Students can take a maximum of 7 subjects total (3 core + up to 7 electives including cross-pathway subjects)',
                'is_active' => true,
            ]);
        }
    }

    /**
     * Create 8-4-4 (Legacy System) Rules
     */
    private function create844Rules()
    {
        $this->command->line('  - Creating 8-4-4 curriculum rules (legacy)...');

        // ============================================
        // FORM 1-2 RULES (All subjects compulsory)
        // ============================================
        $this->command->line('    • Form 1-2: All subjects compulsory');
        
        $form12Subjects = Subject::where('curriculum_type', '8-4-4')
            ->where('level', 'Secondary')
            ->where('is_core', true)
            ->pluck('id')
            ->toArray();

        if (!empty($form12Subjects)) {
            SubjectSelectionRule::create([
                'curriculum_type' => '8-4-4',
                'level' => 'Form 1-2',
                'rule_type' => 'required_subject',
                'subject_ids' => $form12Subjects,
                'min_count' => count($form12Subjects),
                'description' => 'All subjects are compulsory in Form 1-2',
                'is_active' => true,
            ]);
        }

        // ============================================
        // FORM 3-4 RULES (Subject Selection)
        // ============================================
        $this->command->line('    • Form 3-4: Subject selection rules');
        
        // Rule 1: Mathematics and English are compulsory
        $requiredSubjects = Subject::where('curriculum_type', '8-4-4')
            ->where('level', 'Secondary')
            ->whereIn('name', ['Mathematics', 'English'])
            ->pluck('id')
            ->toArray();

        if (!empty($requiredSubjects)) {
            SubjectSelectionRule::create([
                'curriculum_type' => '8-4-4',
                'level' => 'Form 3-4',
                'rule_type' => 'required_subject',
                'subject_ids' => $requiredSubjects,
                'min_count' => 2,
                'description' => 'Mathematics and English are compulsory subjects',
                'is_active' => true,
            ]);
        }

        // Rule 2: Minimum 2 languages (English + Kiswahili or other)
        $languages = Subject::where('curriculum_type', '8-4-4')
            ->where('level', 'Secondary')
            ->whereIn('name', ['English', 'Kiswahili', 'French', 'German', 'Arabic'])
            ->pluck('id')
            ->toArray();

        if (!empty($languages)) {
            SubjectSelectionRule::create([
                'curriculum_type' => '8-4-4',
                'level' => 'Form 3-4',
                'rule_type' => 'min_languages',
                'subject_ids' => $languages,
                'min_count' => 2,
                'description' => 'Students must take at least 2 languages (English and Kiswahili are recommended)',
                'is_active' => true,
            ]);
        }

        // Rule 3: Maximum 3 sciences
        $sciences = Subject::where('curriculum_type', '8-4-4')
            ->where('level', 'Secondary')
            ->whereIn('name', ['Biology', 'Physics', 'Chemistry', 'Agriculture'])
            ->pluck('id')
            ->toArray();

        if (!empty($sciences)) {
            SubjectSelectionRule::create([
                'curriculum_type' => '8-4-4',
                'level' => 'Form 3-4',
                'rule_type' => 'max_sciences',
                'subject_ids' => $sciences,
                'max_count' => 3,
                'description' => 'Students cannot take more than 3 science subjects (Biology, Physics, Chemistry, Agriculture)',
                'is_active' => true,
            ]);
        }

        // Rule 4: Must take at least 1 humanities subject
        $humanities = Subject::where('curriculum_type', '8-4-4')
            ->where('level', 'Secondary')
            ->whereIn('name', ['History & Government', 'Geography', 'Christian Religious Education', 'Islamic Religious Education'])
            ->pluck('id')
            ->toArray();

        if (!empty($humanities)) {
            SubjectSelectionRule::create([
                'curriculum_type' => '8-4-4',
                'level' => 'Form 3-4',
                'rule_type' => 'min_languages',
                'subject_ids' => $humanities,
                'min_count' => 1,
                'description' => 'Students must take at least 1 humanities subject (History, Geography, or Religious Education)',
                'is_active' => true,
            ]);
        }

        // Rule 5: Total subjects must be 8-9 subjects for KCSE
        $allForm34Subjects = Subject::where('curriculum_type', '8-4-4')
            ->where('level', 'Secondary')
            ->pluck('id')
            ->toArray();

        if (!empty($allForm34Subjects)) {
            SubjectSelectionRule::create([
                'curriculum_type' => '8-4-4',
                'level' => 'Form 3-4',
                'rule_type' => 'min_languages',
                'subject_ids' => $allForm34Subjects,
                'min_count' => 8,
                'description' => 'Students must register for at least 8 subjects for KCSE examination',
                'is_active' => true,
            ]);

            SubjectSelectionRule::create([
                'curriculum_type' => '8-4-4',
                'level' => 'Form 3-4',
                'rule_type' => 'max_sciences',
                'subject_ids' => $allForm34Subjects,
                'max_count' => 9,
                'description' => 'Students can register for a maximum of 9 subjects for KCSE examination',
                'is_active' => true,
            ]);
        }
    }
}