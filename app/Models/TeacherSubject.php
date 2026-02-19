<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * TeacherSubject – Pivot model for the teacher_subjects table.
 *
 * Columns:
 *  - teacher_id          FK → teachers.id
 *  - subject_id          FK → subjects.id
 *  - is_primary_subject  bool  – teacher's main / first-choice subject
 *  - years_experience    int?  – years teaching this specific subject
 *  - can_teach_levels    json? – level overrides e.g. ["Primary","Junior Secondary"]
 *                                null = all levels on the teacher's teaching_levels profile
 *  - created_at / updated_at
 */
class TeacherSubject extends Pivot
{
    /**
     * The table this pivot model uses.
     */
    protected $table = 'teacher_subjects';

    /**
     * Allow mass-assignment on pivot columns.
     */
    protected $fillable = [
        'teacher_id',
        'subject_id',
        'is_primary_subject',
        'years_experience',
        'can_teach_levels',
    ];

    protected $casts = [
        'is_primary_subject' => 'boolean',
        'years_experience'   => 'integer',
        'can_teach_levels'   => 'array',   // JSON column – auto encode/decode
        'created_at'         => 'datetime',
        'updated_at'         => 'datetime',
    ];

    /**
     * Pivot models need timestamps declared explicitly.
     */
    public $timestamps = true;

    // ──────────────────────────────────────────────────────────────────────────
    // RELATIONSHIPS
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * The teacher side of this pivot row.
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    /**
     * The subject side of this pivot row.
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Check whether this pivot row allows teaching at a specific level.
     *
     * If can_teach_levels is null (not restricted) it always returns true.
     * Otherwise the given $level must appear in the array.
     *
     * @param  string  $level  e.g. "Junior Secondary"
     */
    public function allowsLevel(string $level): bool
    {
        if (empty($this->can_teach_levels)) {
            return true; // No restriction – teacher profile levels apply
        }

        return in_array($level, (array) $this->can_teach_levels);
    }

    /**
     * Human-readable label for this pivot row.
     * Useful for logs / debugging.
     */
    public function label(): string
    {
        $primary = $this->is_primary_subject ? ' [PRIMARY]' : '';
        $exp     = $this->years_experience !== null ? " ({$this->years_experience} yrs)" : '';
        return ($this->subject->name ?? "Subject #{$this->subject_id}") . $primary . $exp;
    }
}