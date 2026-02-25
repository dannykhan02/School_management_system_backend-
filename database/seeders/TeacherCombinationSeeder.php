<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\TeacherCombination;

/**
 * ─────────────────────────────────────────────────────────────────────────────
 * TeacherCombinationSeeder
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Seeds the canonical list of Kenyan B.Ed / Diploma subject combinations that
 * determine what a teacher is allowed to teach under the CBC and 8-4-4 systems
 * as regulated by the Teachers Service Commission (TSC).
 *
 * DATA MODEL
 * ──────────
 *  teacher_combinations  — one row per recognised B.Ed combination
 *
 * HOW IT WORKS
 * ────────────
 *  1. Each combination records the degree/diploma the teacher studied.
 *  2. primary_subjects   = the subjects they trained in (TSC-registered).
 *  3. derived_subjects   = extra subjects they MAY teach by reasonable extension
 *                          of their training. These are NOT automatically granted.
 *                          Assignment of derived subjects must go through admin
 *                          approval in the TeacherAssignmentValidator layer.
 *  4. eligible_levels    = educational levels they can teach.
 *  5. eligible_pathways  = CBC Senior School pathways they qualify for.
 *  6. curriculum_types   = CBC | 8-4-4 | Both.
 *
 * LEVEL NAMING CONVENTION                                              [ADJ-1]
 * ───────────────────────
 *  'Pre-Primary'          — CBC PP1 & PP2
 *  'Primary'              — CBC Grade 1–6 / 8-4-4 Standard 1–8
 *  'Junior Secondary'     — CBC Grade 7–9
 *  'Senior Secondary'     — CBC Grade 10–12
 *  'Secondary (8-4-4)'    — 8-4-4 Form 1–4 (legacy system)
 *
 *  IMPORTANT: 'Secondary (8-4-4)' is deliberately distinct from 'Senior Secondary'
 *  (CBC) to prevent ambiguity in queries, validation logic, and UI displays.
 *  Any scopeForLevel() or whereJsonContains() calls must use the exact string
 *  'Secondary (8-4-4)' when targeting legacy-system secondary schools.
 *  Any application code previously querying 'Secondary' must be updated.
 *
 * DERIVED SUBJECTS POLICY                                              [ADJ-2]
 * ───────────────────────
 *  Derived subjects are informational metadata only. The system must NOT
 *  auto-approve them for teaching assignment. The TeacherAssignmentValidator
 *  service must enforce:
 *   - Admin approval before a derived subject can be assigned to a teacher.
 *   - Audit trail of who approved the derived assignment and when.
 *   - Primary subjects may be assigned directly without approval.
 *
 * SUBJECT IDENTITY NOTE (future roadmap)                              [ADJ-3]
 * ───────────────────────────────────────
 *  primary_subjects and derived_subjects are currently stored as JSON string
 *  arrays e.g. ["Mathematics", "Physics"]. In a future migration these will be
 *  normalised to primary_subject_ids (foreign keys into the subjects table).
 *  Until that migration runs, string values here must exactly match the `name`
 *  column in the subjects table to allow runtime resolution.
 *
 * CHANGELOG
 * ─────────
 *  v1 — Initial seeder with core Kenyan B.Ed combinations.
 *  v2 — Audit fixes:
 *         [FIX-1] Removed 'Mathematics' from BED-PHY-CHEM derived_subjects.
 *         [FIX-2] Removed 'Business Studies' from BED-ECO-MATH derived_subjects.
 *         [FIX-3] Flagged BED-HIS-GEO-SOC as uncommon 3-subject programme.
 *         [NEW-1] Added BED-MATH-BUS (Mathematics & Business Studies).
 *  v3 — Minor professional adjustments:
 *         [ADJ-1] Renamed 'Secondary' → 'Secondary (8-4-4)' across ALL
 *                 eligible_levels arrays for unambiguous level identification.
 *         [ADJ-2] Added DERIVED SUBJECTS POLICY section to header documenting
 *                 that derived subjects require admin approval — not auto-grant.
 *         [ADJ-3] Added SUBJECT IDENTITY NOTE section to header documenting
 *                 the future roadmap for migrating string arrays to subject IDs.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 */
class TeacherCombinationSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('🎓 Seeding Teacher Combinations (Kenyan B.Ed / Diploma) v3…');

        foreach ($this->getCombinations() as $data) {
            $combo = TeacherCombination::updateOrCreate(
                ['code' => $data['code']],
                [
                    'name'                    => $data['name'],
                    'degree_title'            => $data['degree_title'],
                    'degree_abbreviation'     => $data['degree_abbreviation'],
                    'institution_type'        => $data['institution_type'],
                    'subject_group'           => $data['subject_group'],
                    'primary_subjects'        => $data['primary_subjects'],
                    'derived_subjects'        => $data['derived_subjects'] ?? [],
                    'eligible_levels'         => $data['eligible_levels'],
                    'eligible_pathways'       => $data['eligible_pathways'] ?? [],
                    'curriculum_types'        => $data['curriculum_types'],
                    'tsc_recognized'          => $data['tsc_recognized'] ?? true,
                    'notes'                   => $data['notes'] ?? null,
                    'is_active'               => true,
                ]
            );

            $this->command->line("  ✓ {$combo->code} — {$combo->name}");
        }

        $this->command->info('✅ TeacherCombinationSeeder finished — ' . TeacherCombination::count() . ' combinations seeded.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // COMBINATION DATA
    // ─────────────────────────────────────────────────────────────────────────

    private function getCombinations(): array
    {
        return [

            // ═════════════════════════════════════════════════════════════════
            // GROUP A — MATHEMATICS & SCIENCES
            // Feeds into: Junior Secondary (Integrated Science, Maths),
            //             Senior Secondary STEM Pathway
            // ═════════════════════════════════════════════════════════════════

            [
                'code'                => 'BED-MATH-PHY',
                'name'                => 'Mathematics & Physics',
                'degree_title'        => 'Bachelor of Education (Science) — Mathematics & Physics',
                'degree_abbreviation' => 'B.Ed (Sc.) Math/Phys',
                'institution_type'    => 'university',
                'subject_group'       => 'STEM',
                'primary_subjects'    => ['Mathematics', 'Physics'],
                'derived_subjects'    => ['Integrated Science', 'Pre-Technical & Pre-Career Studies'],
                'eligible_levels'     => ['Junior Secondary', 'Senior Secondary', 'Secondary (8-4-4)'],
                'eligible_pathways'   => ['STEM'],
                'curriculum_types'    => ['CBC', '8-4-4'],
                'tsc_recognized'      => true,
                'notes'               => 'One of the most common and versatile STEM combinations. '
                                       . 'Physics graduates can cover Integrated Science at JS level '
                                       . 'and Physics/Mathematics at SS STEM pathway.',
            ],

            [
                'code'                => 'BED-MATH-CHEM',
                'name'                => 'Mathematics & Chemistry',
                'degree_title'        => 'Bachelor of Education (Science) — Mathematics & Chemistry',
                'degree_abbreviation' => 'B.Ed (Sc.) Math/Chem',
                'institution_type'    => 'university',
                'subject_group'       => 'STEM',
                'primary_subjects'    => ['Mathematics', 'Chemistry'],
                'derived_subjects'    => ['Integrated Science', 'Pre-Technical & Pre-Career Studies'],
                'eligible_levels'     => ['Junior Secondary', 'Senior Secondary', 'Secondary (8-4-4)'],
                'eligible_pathways'   => ['STEM'],
                'curriculum_types'    => ['CBC', '8-4-4'],
                'tsc_recognized'      => true,
                'notes'               => 'Strong analytical background. Chemistry teachers often '
                                       . 'cover Integrated Science at Junior Secondary.',
            ],

            [
                'code'                => 'BED-MATH-CSC',
                'name'                => 'Mathematics & Computer Science',
                'degree_title'        => 'Bachelor of Education (Science) — Mathematics & Computer Science',
                'degree_abbreviation' => 'B.Ed (Sc.) Math/CS',
                'institution_type'    => 'university',
                'subject_group'       => 'STEM',
                'primary_subjects'    => ['Mathematics', 'Computer Science'],
                'derived_subjects'    => ['Pre-Technical & Pre-Career Studies'],
                'eligible_levels'     => ['Junior Secondary', 'Senior Secondary', 'Secondary (8-4-4)'],
                'eligible_pathways'   => ['STEM'],
                'curriculum_types'    => ['CBC', '8-4-4'],
                'tsc_recognized'      => true,
                'notes'               => 'Increasingly in demand under CBC. Computer Science at JS '
                                       . 'and SS STEM pathway. Can also cover Computer Studies (8-4-4).',
            ],

            [
                'code'                => 'BED-BIO-CHEM',
                'name'                => 'Biology & Chemistry',
                'degree_title'        => 'Bachelor of Education (Science) — Biology & Chemistry',
                'degree_abbreviation' => 'B.Ed (Sc.) Bio/Chem',
                'institution_type'    => 'university',
                'subject_group'       => 'STEM',
                'primary_subjects'    => ['Biology', 'Chemistry'],
                'derived_subjects'    => ['Integrated Science', 'Agriculture', 'Home Science'],
                'eligible_levels'     => ['Junior Secondary', 'Senior Secondary', 'Secondary (8-4-4)'],
                'eligible_pathways'   => ['STEM'],
                'curriculum_types'    => ['CBC', '8-4-4'],
                'tsc_recognized'      => true,
                'notes'               => 'Very versatile science combination. Biology/Chemistry '
                                       . 'is well-suited for Integrated Science at JS and life sciences '
                                       . 'at Senior Secondary STEM.',
            ],

            [
                'code'                => 'BED-PHY-CHEM',
                'name'                => 'Physics & Chemistry',
                'degree_title'        => 'Bachelor of Education (Science) — Physics & Chemistry',
                'degree_abbreviation' => 'B.Ed (Sc.) Phys/Chem',
                'institution_type'    => 'university',
                'subject_group'       => 'STEM',
                'primary_subjects'    => ['Physics', 'Chemistry'],
                // [FIX-1] Mathematics removed — Physics/Chemistry does NOT grant Mathematics
                // teaching rights under TSC. Math must be a primary trained subject.
                'derived_subjects'    => ['Integrated Science', 'Pre-Technical & Pre-Career Studies'],
                'eligible_levels'     => ['Junior Secondary', 'Senior Secondary', 'Secondary (8-4-4)'],
                'eligible_pathways'   => ['STEM'],
                'curriculum_types'    => ['CBC', '8-4-4'],
                'tsc_recognized'      => true,
                'notes'               => 'Core physical sciences combination. Strong fit for '
                                       . 'STEM pathway at Senior Secondary. Note: Mathematics is NOT '
                                       . 'a derived subject — only primary-trained Math teachers may '
                                       . 'teach Mathematics under TSC rules.',
            ],

            [
                'code'                => 'BED-BIO-AGR',
                'name'                => 'Biology & Agriculture',
                'degree_title'        => 'Bachelor of Education (Science) — Biology & Agriculture',
                'degree_abbreviation' => 'B.Ed (Sc.) Bio/Agr',
                'institution_type'    => 'university',
                'subject_group'       => 'STEM',
                'primary_subjects'    => ['Biology', 'Agriculture'],
                'derived_subjects'    => ['Integrated Science', 'Home Science'],
                'eligible_levels'     => ['Primary', 'Junior Secondary', 'Senior Secondary', 'Secondary (8-4-4)'],
                'eligible_pathways'   => ['STEM'],
                'curriculum_types'    => ['CBC', '8-4-4'],
                'tsc_recognized'      => true,
                'notes'               => 'Common in rural schools. Agriculture is taught across '
                                       . 'multiple levels (Primary through SS STEM).',
            ],

            [
                'code'                => 'BED-MATH-BIO',
                'name'                => 'Mathematics & Biology',
                'degree_title'        => 'Bachelor of Education (Science) — Mathematics & Biology',
                'degree_abbreviation' => 'B.Ed (Sc.) Math/Bio',
                'institution_type'    => 'university',
                'subject_group'       => 'STEM',
                'primary_subjects'    => ['Mathematics', 'Biology'],
                'derived_subjects'    => ['Integrated Science', 'Agriculture'],
                'eligible_levels'     => ['Junior Secondary', 'Senior Secondary', 'Secondary (8-4-4)'],
                'eligible_pathways'   => ['STEM'],
                'curriculum_types'    => ['CBC', '8-4-4'],
                'tsc_recognized'      => true,
                'notes'               => 'Less common but valid. Mathematics specialist with life '
                                       . 'science awareness — useful for mixed-level schools.',
            ],

            [
                'code'                => 'BED-CSC-BUS',
                'name'                => 'Computer Science & Business Studies',
                'degree_title'        => 'Bachelor of Education (Technical) — Computer Science & Business Studies',
                'degree_abbreviation' => 'B.Ed (Tech.) CS/Bus',
                'institution_type'    => 'university',
                'subject_group'       => 'Technical',
                'primary_subjects'    => ['Computer Science', 'Business Studies'],
                'derived_subjects'    => ['Pre-Technical & Pre-Career Studies'],
                'eligible_levels'     => ['Junior Secondary', 'Senior Secondary', 'Secondary (8-4-4)'],
                'eligible_pathways'   => ['STEM', 'Social Sciences'],
                'curriculum_types'    => ['CBC', '8-4-4'],
                'tsc_recognized'      => true,
                'notes'               => 'Applied technical combination. Business Studies feeds '
                                       . 'Social Sciences pathway; Computer Science feeds STEM.',
            ],

            // ═════════════════════════════════════════════════════════════════
            // GROUP B — LANGUAGES
            // Feeds into: All levels (languages are compulsory across CBC & 8-4-4)
            // ═════════════════════════════════════════════════════════════════

            [
                'code'                => 'BED-ENG-LIT',
                'name'                => 'English & Literature',
                'degree_title'        => 'Bachelor of Education (Arts) — English Language & Literature',
                'degree_abbreviation' => 'B.Ed (Arts) Eng/Lit',
                'institution_type'    => 'university',
                'subject_group'       => 'Languages',
                'primary_subjects'    => ['English', 'Literature in English'],
                'derived_subjects'    => ['Literacy Activities', 'English Language Activities'],
                'eligible_levels'     => ['Primary', 'Junior Secondary', 'Senior Secondary', 'Secondary (8-4-4)'],
                'eligible_pathways'   => ['STEM', 'Arts', 'Social Sciences'],
                'curriculum_types'    => ['CBC', '8-4-4'],
                'tsc_recognized'      => true,
                'notes'               => 'English is compulsory at ALL CBC levels and pathways. '
                                       . 'Literature is largely embedded in CBC but remains a '
                                       . 'standalone subject in 8-4-4. Extremely versatile combination.',
            ],

            [
                'code'                => 'BED-KIS-LIT',
                'name'                => 'Kiswahili & Literature',
                'degree_title'        => 'Bachelor of Education (Arts) — Kiswahili Language & Literature',
                'degree_abbreviation' => 'B.Ed (Arts) Kis/Lit',
                'institution_type'    => 'university',
                'subject_group'       => 'Languages',
                'primary_subjects'    => ['Kiswahili', 'Fasihi ya Kiswahili'],
                'derived_subjects'    => ['Kiswahili Language Activities'],
                'eligible_levels'     => ['Primary', 'Junior Secondary', 'Senior Secondary', 'Secondary (8-4-4)'],
                'eligible_pathways'   => ['STEM', 'Arts', 'Social Sciences'],
                'curriculum_types'    => ['CBC', '8-4-4'],
                'tsc_recognized'      => true,
                'notes'               => 'Kiswahili is compulsory at ALL CBC levels and pathways. '
                                       . 'National language focus — high demand across Kenya.',
            ],

            [
                'code'                => 'BED-ENG-KIS',
                'name'                => 'English & Kiswahili',
                'degree_title'        => 'Bachelor of Education (Arts) — English & Kiswahili',
                'degree_abbreviation' => 'B.Ed (Arts) Eng/Kis',
                'institution_type'    => 'university',
                'subject_group'       => 'Languages',
                'primary_subjects'    => ['English', 'Kiswahili'],
                'derived_subjects'    => ['Literacy Activities', 'Language Activities'],
                'eligible_levels'     => ['Pre-Primary', 'Primary', 'Junior Secondary', 'Senior Secondary', 'Secondary (8-4-4)'],
                'eligible_pathways'   => ['STEM', 'Arts', 'Social Sciences'],
                'curriculum_types'    => ['CBC', '8-4-4'],
                'tsc_recognized'      => true,
                'notes'               => 'Dual national-language specialist. One of the most '
                                       . 'sought-after combinations — both subjects are compulsory '
                                       . 'at every level under CBC and 8-4-4.',
            ],

            [
                'code'                => 'BED-ENG-HIS',
                'name'                => 'English & History',
                'degree_title'        => 'Bachelor of Education (Arts) — English & History',
                'degree_abbreviation' => 'B.Ed (Arts) Eng/His',
                'institution_type'    => 'university',
                'subject_group'       => 'Languages',
                'primary_subjects'    => ['English', 'History & Government'],
                'derived_subjects'    => ['Social Studies', 'History & Citizenship'],
                'eligible_levels'     => ['Junior Secondary', 'Senior Secondary', 'Secondary (8-4-4)'],
                'eligible_pathways'   => ['Arts', 'Social Sciences'],
                'curriculum_types'    => ['CBC', '8-4-4'],
                'tsc_recognized'      => true,
                'notes'               => 'Humanities-languages crossover. English teacher who can '
                                       . 'also cover History at Junior Secondary and Social Sciences pathway.',
            ],

            [
                'code'                => 'BED-KIS-GEO',
                'name'                => 'Kiswahili & Geography',
                'degree_title'        => 'Bachelor of Education (Arts) — Kiswahili & Geography',
                'degree_abbreviation' => 'B.Ed (Arts) Kis/Geo',
                'institution_type'    => 'university',
                'subject_group'       => 'Languages',
                'primary_subjects'    => ['Kiswahili', 'Geography'],
                'derived_subjects'    => ['Social Studies', 'History & Citizenship'],
                'eligible_levels'     => ['Junior Secondary', 'Senior Secondary', 'Secondary (8-4-4)'],
                'eligible_pathways'   => ['Arts', 'Social Sciences'],
                'curriculum_types'    => ['CBC', '8-4-4'],
                'tsc_recognized'      => true,
                'notes'               => 'Kiswahili + Geography feeds the Social Sciences pathway. '
                                       . 'Geography at JS Social Studies and SS Social Sciences.',
            ],

            [
                'code'                => 'BED-ENG-FRE',
                'name'                => 'English & French',
                'degree_title'        => 'Bachelor of Education (Arts) — English & French',
                'degree_abbreviation' => 'B.Ed (Arts) Eng/Fre',
                'institution_type'    => 'university',
                'subject_group'       => 'Languages',
                'primary_subjects'    => ['English', 'French'],
                'derived_subjects'    => [],
                'eligible_levels'     => ['Junior Secondary', 'Senior Secondary', 'Secondary (8-4-4)'],
                'eligible_pathways'   => ['Arts', 'Social Sciences'],
                'curriculum_types'    => ['8-4-4'],
                'tsc_recognized'      => true,
                'notes'               => 'French is primarily an 8-4-4 elective subject. '
                                       . 'Not yet fully integrated into CBC curriculum.',
            ],

            [
                'code'                => 'BED-ENG-GER',
                'name'                => 'English & German',
                'degree_title'        => 'Bachelor of Education (Arts) — English & German',
                'degree_abbreviation' => 'B.Ed (Arts) Eng/Ger',
                'institution_type'    => 'university',
                'subject_group'       => 'Languages',
                'primary_subjects'    => ['English', 'German'],
                'derived_subjects'    => [],
                // [ADJ-1] Renamed from 'Secondary' → 'Secondary (8-4-4)'
                'eligible_levels'     => ['Secondary (8-4-4)'],
                'eligible_pathways'   => [],
                'curriculum_types'    => ['8-4-4'],
                'tsc_recognized'      => true,
                'notes'               => 'German is primarily an 8-4-4 elective. Limited to a '
                                       . 'small number of schools offering German language.',
            ],

            // ═════════════════════════════════════════════════════════════════
            // GROUP C — HUMANITIES / SOCIAL SCIENCES
            // Feeds into: Junior Secondary Social Studies, Senior Secondary
            //             Social Sciences Pathway
            // ═════════════════════════════════════════════════════════════════

            [
                'code'                => 'BED-HIS-GEO',
                'name'                => 'History & Geography',
                'degree_title'        => 'Bachelor of Education (Arts) — History & Geography',
                'degree_abbreviation' => 'B.Ed (Arts) His/Geo',
                'institution_type'    => 'university',
                'subject_group'       => 'Humanities',
                'primary_subjects'    => ['History & Government', 'Geography'],
                'derived_subjects'    => ['Social Studies', 'History & Citizenship', 'Community Service Learning'],
                'eligible_levels'     => ['Junior Secondary', 'Senior Secondary', 'Secondary (8-4-4)'],
                'eligible_pathways'   => ['Social Sciences'],
                'curriculum_types'    => ['CBC', '8-4-4'],
                'tsc_recognized'      => true,
                'notes'               => 'Classic social science combination. Both subjects appear '
                                       . 'in CBC SS Social Sciences pathway. Social Studies at JS level.',
            ],

            [
                'code'                => 'BED-HIS-CRE',
                'name'                => 'History & Christian Religious Education',
                'degree_title'        => 'Bachelor of Education (Arts) — History & Christian Religious Education',
                'degree_abbreviation' => 'B.Ed (Arts) His/CRE',
                'institution_type'    => 'university',
                'subject_group'       => 'Humanities',
                'primary_subjects'    => ['History & Government', 'Christian Religious Education'],
                'derived_subjects'    => ['Social Studies', 'Religious Education', 'History & Citizenship'],
                'eligible_levels'     => ['Primary', 'Junior Secondary', 'Senior Secondary', 'Secondary (8-4-4)'],
                'eligible_pathways'   => ['Social Sciences'],
                'curriculum_types'    => ['CBC', '8-4-4'],
                'tsc_recognized'      => true,
                'notes'               => 'Common in church-sponsored schools. Religious Education '
                                       . 'appears at all CBC levels. History & Citizenship in SS.',
            ],

            [
                'code'                => 'BED-HIS-IRE',
                'name'                => 'History & Islamic Religious Education',
                'degree_title'        => 'Bachelor of Education (Arts) — History & Islamic Religious Education',
                'degree_abbreviation' => 'B.Ed (Arts) His/IRE',
                'institution_type'    => 'university',
                'subject_group'       => 'Humanities',
                'primary_subjects'    => ['History & Government', 'Islamic Religious Education'],
                'derived_subjects'    => ['Social Studies', 'Religious Education', 'History & Citizenship'],
                'eligible_levels'     => ['Primary', 'Junior Secondary', 'Senior Secondary', 'Secondary (8-4-4)'],
                'eligible_pathways'   => ['Social Sciences'],
                'curriculum_types'    => ['CBC', '8-4-4'],
                'tsc_recognized'      => true,
                'notes'               => 'Prevalent in Islamic-sponsored schools, coastal and '
                                       . 'north-eastern regions. IRE is available in 8-4-4.',
            ],

            [
                'code'                => 'BED-GEO-BUS',
                'name'                => 'Geography & Business Studies',
                'degree_title'        => 'Bachelor of Education (Arts) — Geography & Business Studies',
                'degree_abbreviation' => 'B.Ed (Arts) Geo/Bus',
                'institution_type'    => 'university',
                'subject_group'       => 'Humanities',
                'primary_subjects'    => ['Geography', 'Business Studies'],
                'derived_subjects'    => ['Social Studies', 'Economics'],
                'eligible_levels'     => ['Junior Secondary', 'Senior Secondary', 'Secondary (8-4-4)'],
                'eligible_pathways'   => ['Social Sciences'],
                'curriculum_types'    => ['CBC', '8-4-4'],
                'tsc_recognized'      => true,
                'notes'               => 'Useful mix — Geography for SS Social Sciences, '
                                       . 'Business Studies for both Social Sciences and applied pathways.',
            ],

            [
                'code'                => 'BED-CRE-KIS',
                'name'                => 'Christian Religious Education & Kiswahili',
                'degree_title'        => 'Bachelor of Education (Arts) — Christian Religious Education & Kiswahili',
                'degree_abbreviation' => 'B.Ed (Arts) CRE/Kis',
                'institution_type'    => 'university',
                'subject_group'       => 'Humanities',
                'primary_subjects'    => ['Christian Religious Education', 'Kiswahili'],
                'derived_subjects'    => ['Religious Education', 'Social Studies'],
                'eligible_levels'     => ['Primary', 'Junior Secondary', 'Senior Secondary', 'Secondary (8-4-4)'],
                'eligible_pathways'   => ['Arts', 'Social Sciences'],
                'curriculum_types'    => ['CBC', '8-4-4'],
                'tsc_recognized'      => true,
                'notes'               => 'Kiswahili is high demand. CRE teacher also covers '
                                       . 'Religious Education at Primary and JS levels.',
            ],

            [
                // [FIX-3] Three-subject combination kept but flagged clearly.
                // Uncommon in Kenyan universities — most B.Ed programmes are two-subject.
                // Validate carefully before linking teachers to this combination.
                'code'                => 'BED-HIS-GEO-SOC',
                'name'                => 'History, Geography & Social Studies',
                'degree_title'        => 'Bachelor of Education (Arts) — History, Geography & Social Studies',
                'degree_abbreviation' => 'B.Ed (Arts) His/Geo/Soc',
                'institution_type'    => 'university',
                'subject_group'       => 'Humanities',
                'primary_subjects'    => ['History & Government', 'Geography', 'Social Studies'],
                'derived_subjects'    => ['History & Citizenship', 'Community Service Learning'],
                'eligible_levels'     => ['Primary', 'Junior Secondary', 'Senior Secondary', 'Secondary (8-4-4)'],
                'eligible_pathways'   => ['Social Sciences'],
                'curriculum_types'    => ['CBC', '8-4-4'],
                'tsc_recognized'      => true,
                'notes'               => 'UNCOMMON: Most Kenyan B.Ed programmes train in two subjects. '
                                       . 'A small number of universities offer 3-subject Humanities '
                                       . 'programmes. Verify the teacher\'s actual degree transcript '
                                       . 'before assigning this combination. Ideal for JS Social Studies '
                                       . 'and SS Social Sciences pathway where offered.',
            ],

            // ═════════════════════════════════════════════════════════════════
            // GROUP D — BUSINESS & ECONOMICS
            // Feeds into: Social Sciences Pathway, Technical Pathway
            // ═════════════════════════════════════════════════════════════════

            [
                'code'                => 'BED-BUS-ECO',
                'name'                => 'Business Studies & Economics',
                'degree_title'        => 'Bachelor of Education (Arts) — Business Studies & Economics',
                'degree_abbreviation' => 'B.Ed (Arts) Bus/Eco',
                'institution_type'    => 'university',
                'subject_group'       => 'Business',
                'primary_subjects'    => ['Business Studies', 'Economics'],
                'derived_subjects'    => ['Mathematics (Applied)'],
                'eligible_levels'     => ['Junior Secondary', 'Senior Secondary', 'Secondary (8-4-4)'],
                'eligible_pathways'   => ['Social Sciences'],
                'curriculum_types'    => ['CBC', '8-4-4'],
                'tsc_recognized'      => true,
                'notes'               => 'Core commerce combination. Business Studies appears '
                                       . 'in both CBC Social Sciences SS pathway and 8-4-4 secondary.',
            ],

            [
                'code'                => 'BED-ECO-MATH',
                'name'                => 'Economics & Mathematics',
                'degree_title'        => 'Bachelor of Education (Arts/Science) — Economics & Mathematics',
                'degree_abbreviation' => 'B.Ed Eco/Math',
                'institution_type'    => 'university',
                'subject_group'       => 'Business',
                'primary_subjects'    => ['Economics', 'Mathematics'],
                // [FIX-2] Business Studies removed — Economics ≠ Business Studies under TSC rules.
                // These are distinct trained subjects. Business Studies must be a primary subject.
                'derived_subjects'    => ['Mathematics (Applied)', 'Pre-Technical & Pre-Career Studies'],
                'eligible_levels'     => ['Junior Secondary', 'Senior Secondary', 'Secondary (8-4-4)'],
                'eligible_pathways'   => ['STEM', 'Social Sciences'],
                'curriculum_types'    => ['CBC', '8-4-4'],
                'tsc_recognized'      => true,
                'notes'               => 'Dual pathway eligibility. Mathematics qualifies for STEM; '
                                       . 'Economics qualifies for Social Sciences. Strong analytical focus. '
                                       . 'Note: Business Studies is NOT derived from this combination — '
                                       . 'Economics and Business Studies are distinct TSC subjects.',
            ],

            // ─────────────────────────────────────────────────────────────────
            // [NEW-1] Mathematics & Business Studies
            // Added per audit: a valid and common Kenyan B.Ed combination
            // previously missing. Bridges STEM (Mathematics) and Social
            // Sciences (Business Studies) pathways.
            // ─────────────────────────────────────────────────────────────────
            [
                'code'                => 'BED-MATH-BUS',
                'name'                => 'Mathematics & Business Studies',
                'degree_title'        => 'Bachelor of Education (Arts/Science) — Mathematics & Business Studies',
                'degree_abbreviation' => 'B.Ed Math/Bus',
                'institution_type'    => 'university',
                'subject_group'       => 'Business',
                'primary_subjects'    => ['Mathematics', 'Business Studies'],
                'derived_subjects'    => ['Mathematics (Applied)', 'Pre-Technical & Pre-Career Studies'],
                'eligible_levels'     => ['Junior Secondary', 'Senior Secondary', 'Secondary (8-4-4)'],
                'eligible_pathways'   => ['STEM', 'Social Sciences'],
                'curriculum_types'    => ['CBC', '8-4-4'],
                'tsc_recognized'      => true,
                'notes'               => 'Valid and common Kenyan B.Ed combination. Mathematics '
                                       . 'qualifies the teacher for the STEM pathway; Business Studies '
                                       . 'qualifies for the Social Sciences pathway. Dual pathway '
                                       . 'eligibility makes this a versatile combination for schools '
                                       . 'running both pathways. Economics is NOT derived — it must be '
                                       . 'a trained subject.',
            ],

            [
                'code'                => 'BED-AGR-BIO',
                'name'                => 'Agriculture & Biology',
                'degree_title'        => 'Bachelor of Education (Science) — Agriculture & Biology',
                'degree_abbreviation' => 'B.Ed (Sc.) Agr/Bio',
                'institution_type'    => 'university',
                'subject_group'       => 'Technical',
                'primary_subjects'    => ['Agriculture', 'Biology'],
                'derived_subjects'    => ['Integrated Science', 'Home Science', 'Environmental Activities'],
                'eligible_levels'     => ['Primary', 'Junior Secondary', 'Senior Secondary', 'Secondary (8-4-4)'],
                'eligible_pathways'   => ['STEM'],
                'curriculum_types'    => ['CBC', '8-4-4'],
                'tsc_recognized'      => true,
                'notes'               => 'Strong practical-science combination. Agriculture taught '
                                       . 'from Upper Primary through SS STEM. Biology covers life sciences.',
            ],

            // ═════════════════════════════════════════════════════════════════
            // GROUP E — CREATIVE ARTS & PHYSICAL EDUCATION
            // Feeds into: Arts Pathway, Creative Arts subjects across all levels
            // ═════════════════════════════════════════════════════════════════

            [
                'code'                => 'BED-ART-MUS',
                'name'                => 'Art & Music',
                'degree_title'        => 'Bachelor of Education (Arts) — Fine Art & Music',
                'degree_abbreviation' => 'B.Ed (Arts) Art/Mus',
                'institution_type'    => 'university',
                'subject_group'       => 'Creative Arts',
                'primary_subjects'    => ['Visual Arts', 'Music'],
                'derived_subjects'    => ['Performing Arts', 'Creative Arts & Sports', 'Psychomotor & Creative Activities'],
                'eligible_levels'     => ['Pre-Primary', 'Primary', 'Junior Secondary', 'Senior Secondary', 'Secondary (8-4-4)'],
                'eligible_pathways'   => ['Arts'],
                'curriculum_types'    => ['CBC', '8-4-4'],
                'tsc_recognized'      => true,
                'notes'               => 'Creative arts specialist. Can cover Creative Arts & Sports '
                                       . 'at Primary, Visual Arts and Performing Arts at JS, '
                                       . 'and all arts at SS Arts pathway.',
            ],

            [
                'code'                => 'BED-PE-BIO',
                'name'                => 'Physical Education & Biology',
                'degree_title'        => 'Bachelor of Education — Physical Education & Biology',
                'degree_abbreviation' => 'B.Ed PE/Bio',
                'institution_type'    => 'university',
                'subject_group'       => 'Physical Education',
                'primary_subjects'    => ['Physical Education', 'Biology'],
                'derived_subjects'    => ['Integrated Science', 'Creative Arts & Sports'],
                'eligible_levels'     => ['Primary', 'Junior Secondary', 'Senior Secondary', 'Secondary (8-4-4)'],
                'eligible_pathways'   => ['Arts', 'STEM'],
                'curriculum_types'    => ['CBC', '8-4-4'],
                'tsc_recognized'      => true,
                'notes'               => 'PE is core to the Arts pathway at SS. Biology allows '
                                       . 'coverage of Integrated Science at JS.',
            ],

            [
                'code'                => 'BED-ART-PE',
                'name'                => 'Art & Physical Education',
                'degree_title'        => 'Bachelor of Education — Fine Art & Physical Education',
                'degree_abbreviation' => 'B.Ed Art/PE',
                'institution_type'    => 'university',
                'subject_group'       => 'Creative Arts',
                'primary_subjects'    => ['Visual Arts', 'Physical Education'],
                'derived_subjects'    => ['Performing Arts', 'Creative Arts & Sports', 'Psychomotor & Creative Activities'],
                // Note: BED-ART-PE is a CBC-only combination — no 8-4-4 legacy equivalent.
                // 'Secondary (8-4-4)' intentionally excluded from eligible_levels.
                'eligible_levels'     => ['Pre-Primary', 'Primary', 'Junior Secondary', 'Senior Secondary'],
                'eligible_pathways'   => ['Arts'],
                'curriculum_types'    => ['CBC', '8-4-4'],
                'tsc_recognized'      => true,
                'notes'               => 'SS Arts pathway teacher. Creative Arts & Sports covers '
                                       . 'both subjects at Lower/Upper Primary level.',
            ],

            [
                'code'                => 'BED-MUS-KIS',
                'name'                => 'Music & Kiswahili',
                'degree_title'        => 'Bachelor of Education (Arts) — Music & Kiswahili',
                'degree_abbreviation' => 'B.Ed (Arts) Mus/Kis',
                'institution_type'    => 'university',
                'subject_group'       => 'Creative Arts',
                'primary_subjects'    => ['Music', 'Kiswahili'],
                'derived_subjects'    => ['Performing Arts', 'Creative Arts & Sports'],
                'eligible_levels'     => ['Primary', 'Junior Secondary', 'Senior Secondary', 'Secondary (8-4-4)'],
                'eligible_pathways'   => ['Arts'],
                'curriculum_types'    => ['CBC', '8-4-4'],
                'tsc_recognized'      => true,
                'notes'               => 'Useful in primary schools where both Music and Kiswahili '
                                       . 'are taught. Kiswahili is compulsory across all CBC levels.',
            ],

            // ═════════════════════════════════════════════════════════════════
            // GROUP F — HOME SCIENCE & APPLIED SUBJECTS
            // Feeds into: Technical pathway, Applied Sciences at all levels
            // ═════════════════════════════════════════════════════════════════

            [
                'code'                => 'BED-HOM-BIO',
                'name'                => 'Home Science & Biology',
                'degree_title'        => 'Bachelor of Education (Science) — Home Science & Biology',
                'degree_abbreviation' => 'B.Ed (Sc.) Hom/Bio',
                'institution_type'    => 'university',
                'subject_group'       => 'Technical',
                'primary_subjects'    => ['Home Science', 'Biology'],
                'derived_subjects'    => ['Integrated Science', 'Agriculture'],
                'eligible_levels'     => ['Primary', 'Junior Secondary', 'Senior Secondary', 'Secondary (8-4-4)'],
                'eligible_pathways'   => ['STEM', 'Arts'],
                'curriculum_types'    => ['CBC', '8-4-4'],
                'tsc_recognized'      => true,
                'notes'               => 'Home Science is relevant at Upper Primary, JS, SS Arts '
                                       . 'and 8-4-4 Secondary. Biology extends to STEM pathway.',
            ],

            [
                'code'                => 'BED-HOM-CHEM',
                'name'                => 'Home Science & Chemistry',
                'degree_title'        => 'Bachelor of Education (Science) — Home Science & Chemistry',
                'degree_abbreviation' => 'B.Ed (Sc.) Hom/Chem',
                'institution_type'    => 'university',
                'subject_group'       => 'Technical',
                'primary_subjects'    => ['Home Science', 'Chemistry'],
                'derived_subjects'    => ['Integrated Science', 'Biology'],
                'eligible_levels'     => ['Primary', 'Junior Secondary', 'Senior Secondary', 'Secondary (8-4-4)'],
                'eligible_pathways'   => ['STEM', 'Arts'],
                'curriculum_types'    => ['CBC', '8-4-4'],
                'tsc_recognized'      => true,
                'notes'               => 'Chemistry covers STEM pathway; Home Science covers Arts '
                                       . 'pathway and Applied Sciences at multiple levels.',
            ],

            // ═════════════════════════════════════════════════════════════════
            // GROUP G — EARLY CHILDHOOD / PRIMARY SPECIALIST
            // Feeds into: Pre-Primary & Lower Primary
            // ═════════════════════════════════════════════════════════════════

            [
                'code'                => 'BED-ECDE',
                'name'                => 'Early Childhood Development & Education',
                'degree_title'        => 'Bachelor of Education — Early Childhood Development & Education',
                'degree_abbreviation' => 'B.Ed ECDE',
                'institution_type'    => 'university',
                'subject_group'       => 'General',
                'primary_subjects'    => [
                    'Language Activities',
                    'Mathematical Activities',
                    'Environmental Activities',
                    'Psychomotor & Creative Activities',
                    'Religious Education Activities',
                ],
                'derived_subjects'    => [
                    'Literacy Activities',
                    'Kiswahili Language Activities',
                    'English Language Activities',
                ],
                'eligible_levels'     => ['Pre-Primary', 'Primary'],
                'eligible_pathways'   => [],
                'curriculum_types'    => ['CBC'],
                'tsc_recognized'      => true,
                'notes'               => 'Specialist early childhood qualification. Covers all PP1-PP2 '
                                       . 'subjects and extends into Lower Primary (Grade 1-3). '
                                       . 'Required for Pre-Primary schools under CBC.',
            ],

            [
                'code'                => 'DPED-PRIMARY',
                'name'                => 'Diploma in Primary Education (General)',
                'degree_title'        => 'Diploma in Primary Education',
                'degree_abbreviation' => 'Dip. P.Ed',
                'institution_type'    => 'teacher_training_college',
                'subject_group'       => 'General',
                'primary_subjects'    => [
                    'English',
                    'Kiswahili',
                    'Mathematics',
                    'Science & Technology',
                    'Social Studies',
                    'Religious Education',
                    'Creative Arts & Sports',
                ],
                'derived_subjects'    => [
                    'Literacy Activities',
                    'Mathematical Activities',
                    'Environmental Activities',
                ],
                'eligible_levels'     => ['Pre-Primary', 'Primary'],
                'eligible_pathways'   => [],
                'curriculum_types'    => ['CBC', '8-4-4'],
                'tsc_recognized'      => true,
                'notes'               => 'Teachers Training College (P1) qualification. Covers all '
                                       . 'primary subjects (Grade 1-6 / Standard 1-8). General '
                                       . 'primary school teacher — NOT a subject specialist.',
            ],

            [
                'code'                => 'DPED-SPECIAL',
                'name'                => 'Diploma in Special Needs Education',
                'degree_title'        => 'Diploma in Special Needs Education',
                'degree_abbreviation' => 'Dip. SNE',
                'institution_type'    => 'teacher_training_college',
                'subject_group'       => 'Special Needs',
                'primary_subjects'    => [
                    'English',
                    'Kiswahili',
                    'Mathematics',
                ],
                'derived_subjects'    => [],
                'eligible_levels'     => ['Pre-Primary', 'Primary'],
                'eligible_pathways'   => [],
                'curriculum_types'    => ['CBC', '8-4-4'],
                'tsc_recognized'      => true,
                'notes'               => 'SNE teachers work across all primary subjects with '
                                       . 'learners with special needs. Not pathway-specific.',
            ],

            // ═════════════════════════════════════════════════════════════════
            // GROUP H — TECHNICAL & VOCATIONAL
            // Feeds into: Pre-Technical, Technical/Vocational electives
            // ═════════════════════════════════════════════════════════════════

            [
                'code'                => 'BED-TECH-MATH',
                'name'                => 'Technical Education & Mathematics',
                'degree_title'        => 'Bachelor of Education (Technical) — Technical Education & Mathematics',
                'degree_abbreviation' => 'B.Ed (Tech.) Tech/Math',
                'institution_type'    => 'technical_university',
                'subject_group'       => 'Technical',
                'primary_subjects'    => ['Pre-Technical & Pre-Career Studies', 'Mathematics'],
                'derived_subjects'    => ['Computer Science', 'Agriculture'],
                'eligible_levels'     => ['Junior Secondary', 'Senior Secondary', 'Secondary (8-4-4)'],
                'eligible_pathways'   => ['STEM'],
                'curriculum_types'    => ['CBC', '8-4-4'],
                'tsc_recognized'      => true,
                'notes'               => 'Technical education specialist. Pre-Technical & Pre-Career '
                                       . 'Studies is a core JS CBC subject. Feeds STEM pathway.',
            ],

            [
                'code'                => 'BED-CST-MATH',
                'name'                => 'Computer Studies & Mathematics',
                'degree_title'        => 'Bachelor of Education (Technical) — Computer Studies & Mathematics',
                'degree_abbreviation' => 'B.Ed (Tech.) CST/Math',
                'institution_type'    => 'university',
                'subject_group'       => 'Technical',
                'primary_subjects'    => ['Computer Studies', 'Mathematics'],
                'derived_subjects'    => ['Computer Science', 'Pre-Technical & Pre-Career Studies'],
                'eligible_levels'     => ['Junior Secondary', 'Senior Secondary', 'Secondary (8-4-4)'],
                'eligible_pathways'   => ['STEM'],
                'curriculum_types'    => ['CBC', '8-4-4'],
                'tsc_recognized'      => true,
                'notes'               => 'Computer Studies (8-4-4) maps to Computer Science (CBC). '
                                       . 'Mathematics specialist at all secondary levels.',
            ],

        ];
    }
}