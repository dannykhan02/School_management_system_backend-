<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ─────────────────────────────────────────────────────────────────────────────
 * TeacherCombination  —  App\Models\TeacherCombination
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Represents a canonical Kenyan B.Ed / Diploma subject combination.
 *
 * One combination has:
 *   - A unique code          e.g. "BED-MATH-PHY"
 *   - A human name           e.g. "Mathematics & Physics"
 *   - A full degree title    e.g. "Bachelor of Education (Science) — Mathematics & Physics"
 *   - primary_subjects       JSON array of subject names the teacher trained in
 *   - derived_subjects       JSON array of extra subjects they may teach by extension
 *   - eligible_levels        JSON array of educational levels
 *   - eligible_pathways      JSON array of CBC SS pathways
 *   - curriculum_types       JSON array — CBC | 8-4-4 | Both
 *
 * ─────────────────────────────────────────────────────────────────────────────
 */
class TeacherCombination extends Model
{
    use HasFactory;

    // ──────────────────────────────────────────────────────────────────────────
    // FILLABLE / CASTS
    // ──────────────────────────────────────────────────────────────────────────

    protected $fillable = [
        'code',
        'name',
        'degree_title',
        'degree_abbreviation',
        'institution_type',          // university | teacher_training_college | technical_university
        'subject_group',             // STEM | Languages | Humanities | Business | Creative Arts | etc.
        'primary_subjects',          // JSON: ["Mathematics", "Physics"]
        'derived_subjects',          // JSON: ["Integrated Science", "Pre-Technical & Pre-Career Studies"]
        'eligible_levels',           // JSON: ["Junior Secondary", "Senior Secondary"]
        'eligible_pathways',         // JSON: ["STEM"]
        'curriculum_types',          // JSON: ["CBC", "8-4-4"]
        'tsc_recognized',            // bool — is it a TSC-approved combination?
        'notes',                     // longText — context / caveats
        'is_active',
    ];

    protected $casts = [
        'primary_subjects'  => 'array',
        'derived_subjects'  => 'array',
        'eligible_levels'   => 'array',
        'eligible_pathways' => 'array',
        'curriculum_types'  => 'array',
        'tsc_recognized'    => 'boolean',
        'is_active'         => 'boolean',
    ];

    // ──────────────────────────────────────────────────────────────────────────
    // CONSTANTS
    // ──────────────────────────────────────────────────────────────────────────

    public const INSTITUTION_TYPES = [
        'university',
        'teacher_training_college',
        'technical_university',
    ];

    public const SUBJECT_GROUPS = [
        'STEM',
        'Languages',
        'Humanities',
        'Business',
        'Creative Arts',
        'Physical Education',
        'Technical',
        'General',
        'Special Needs',
    ];

    // ──────────────────────────────────────────────────────────────────────────
    // RELATIONSHIPS
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Teachers who hold this combination.
     */
    public function teachers(): HasMany
    {
        return $this->hasMany(Teacher::class, 'combination_id');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * All subject names this combination can teach (primary + derived).
     */
    public function allTeachableSubjects(): array
    {
        return array_unique(array_merge(
            $this->primary_subjects ?? [],
            $this->derived_subjects ?? []
        ));
    }

    /**
     * Can a teacher with this combination teach the given subject name at the
     * given level?
     *
     * @param  string       $subjectName  e.g. "Integrated Science"
     * @param  string|null  $level        e.g. "Junior Secondary"
     */
    public function canTeach(string $subjectName, ?string $level = null): bool
    {
        $teachable = $this->allTeachableSubjects();

        // Case-insensitive name check
        $found = collect($teachable)->first(fn($s) => strtolower($s) === strtolower($subjectName));
        if (!$found) return false;

        // Level check
        if ($level && !in_array($level, $this->eligible_levels ?? [])) return false;

        return true;
    }

    /**
     * Does this combination qualify for the given CBC Senior Secondary pathway?
     */
    public function qualifiesForPathway(string $pathway): bool
    {
        return in_array($pathway, $this->eligible_pathways ?? []);
    }

    /**
     * Is this combination valid for the given curriculum type?
     */
    public function supportsCirculum(string $curriculumType): bool
    {
        return in_array($curriculumType, $this->curriculum_types ?? []);
    }

    /**
     * Human-readable label for dropdowns.
     * e.g. "Mathematics & Physics (B.Ed Sc.)"
     */
    public function getDropdownLabelAttribute(): string
    {
        return "{$this->name} — {$this->degree_abbreviation}";
    }

    /**
     * Scope: active combinations only.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: TSC recognised only.
     */
    public function scopeTscRecognized($query)
    {
        return $query->where('tsc_recognized', true);
    }

    /**
     * Scope: filter by subject group.
     */
    public function scopeForGroup($query, string $group)
    {
        return $query->where('subject_group', $group);
    }

    /**
     * Scope: combinations that include a specific level.
     */
    public function scopeForLevel($query, string $level)
    {
        return $query->whereJsonContains('eligible_levels', $level);
    }

    /**
     * Scope: combinations that include a specific pathway.
     */
    public function scopeForPathway($query, string $pathway)
    {
        return $query->whereJsonContains('eligible_pathways', $pathway);
    }
}