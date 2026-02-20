<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Services\WorkloadCalculator;
use App\Services\SpecializationMatcher;

class Teacher extends Model
{
    use HasFactory;

    // ──────────────────────────────────────────────────────────────────────────
    // FILLABLE / CASTS / DEFAULTS
    // ──────────────────────────────────────────────────────────────────────────

    protected $fillable = [
        // Identity
        'user_id',
        'school_id',

        // Professional
        'qualification',
        'employment_type',
        'employment_status',          // active | on_leave | suspended | resigned | retired
        'tsc_number',
        'tsc_status',                 // registered | pending | not_registered

        // Curriculum & subject profile
        'specialization',             // formatted e.g. "Languages(English) | Sciences(Physics)"
        'curriculum_specialization',  // CBC | 8-4-4 | Both
        'teaching_levels',            // JSON: ["Primary", "Junior Secondary", …]
        'teaching_pathways',          // JSON: ["STEM", "Arts", "Social Sciences"]  SS only
        'specialization_subjects',    // JSON: [subjectId, …]  quick-lookup shortcut
        'subject_categories',         // JSON: ["Sciences", "Mathematics", …]

        // Workload limits
        'max_subjects',
        'max_classes',
        'max_weekly_lessons',
        'min_weekly_lessons',

        // Class-teacher flags (denormalised for speed)
        'is_class_teacher',
        'current_class_teacher_classroom_id',
        'current_class_teacher_stream_id',
    ];

    protected $attributes = [
        'max_weekly_lessons' => 40,
        'min_weekly_lessons' => 20,
        'is_class_teacher'   => false,
        'employment_status'  => 'active',
    ];

    protected $casts = [
        'teaching_levels'                    => 'array',
        'teaching_pathways'                  => 'array',
        'specialization_subjects'            => 'array',
        'subject_categories'                 => 'array',
        'is_class_teacher'                   => 'boolean',
        'max_weekly_lessons'                 => 'integer',
        'min_weekly_lessons'                 => 'integer',
        'max_subjects'                        => 'integer',
        'max_classes'                         => 'integer',
        'current_class_teacher_classroom_id' => 'integer',
        'current_class_teacher_stream_id'    => 'integer',
        'created_at'                         => 'datetime',
        'updated_at'                         => 'datetime',
    ];

    // ──────────────────────────────────────────────────────────────────────────
    // CONSTANTS
    // ──────────────────────────────────────────────────────────────────────────

    public const TEACHING_LEVELS = [
        'Pre-Primary',
        'Primary',
        'Junior Secondary',
        'Senior Secondary',
    ];

    public const TEACHING_PATHWAYS = [
        'STEM',
        'Arts',
        'Social Sciences',
    ];

    public const CURRICULUM_TYPES     = ['CBC', '8-4-4', 'Both'];
    public const EMPLOYMENT_STATUSES  = ['active', 'on_leave', 'suspended', 'resigned', 'retired'];
    public const TSC_STATUSES         = ['registered', 'pending', 'not_registered'];

    // ──────────────────────────────────────────────────────────────────────────
    // CORE RELATIONSHIPS
    // ──────────────────────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // SUBJECT RELATIONSHIPS  (via teacher_subjects pivot)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * All subjects this teacher is qualified to teach.
     *
     * Uses the TeacherSubject pivot model so that $subject->pivot is a full
     * TeacherSubject instance with helpers like allowsLevel().
     *
     * Eager-load example:
     *   $teacher->load('qualifiedSubjects')
     *   $teacher->qualifiedSubjects->each(fn($s) => $s->pivot->allowsLevel('Primary'))
     */
    public function qualifiedSubjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'teacher_subjects')
                    ->using(TeacherSubject::class)           // ← pivot model
                    ->withPivot([
                        'is_primary_subject',
                        'years_experience',
                        'can_teach_levels',
                    ])
                    ->withTimestamps();
    }

    /**
     * Only the subjects marked as primary/specialisation.
     */
    public function primarySubjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'teacher_subjects')
                    ->using(TeacherSubject::class)
                    ->withPivot([
                        'is_primary_subject',
                        'years_experience',
                        'can_teach_levels',
                    ])
                    ->wherePivot('is_primary_subject', true)
                    ->withTimestamps();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // ASSIGNMENT / TIMETABLE RELATIONSHIPS
    // ──────────────────────────────────────────────────────────────────────────

    public function subjectAssignments(): HasMany
    {
        return $this->hasMany(SubjectAssignment::class);
    }

    public function timetablePeriods(): HasMany
    {
        return $this->hasMany(TimetablePeriod::class);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // CLASS-TEACHER FK SHORTCUTS
    // ──────────────────────────────────────────────────────────────────────────

    public function currentClassTeacherClassroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class, 'current_class_teacher_classroom_id');
    }

    public function currentClassTeacherStream(): BelongsTo
    {
        return $this->belongsTo(Stream::class, 'current_class_teacher_stream_id');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // STREAM-SCHOOL RELATIONSHIPS
    // ──────────────────────────────────────────────────────────────────────────

    /** Streams where this teacher IS the class teacher (FK on streams table). */
    public function classTeacherStreams(): HasMany
    {
        return $this->hasMany(Stream::class, 'class_teacher_id');
    }

    /** Streams this teacher teaches in (teaching – not class-teacher). */
    public function teachingStreams(): BelongsToMany
    {
        return $this->belongsToMany(
            Stream::class, 'stream_teacher', 'teacher_id', 'stream_id'
        )->withTimestamps();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // NON-STREAM SCHOOL RELATIONSHIPS
    // ──────────────────────────────────────────────────────────────────────────

    /** Classrooms this teacher is attached to. */
    public function classrooms(): BelongsToMany
    {
        return $this->belongsToMany(
            Classroom::class, 'classroom_teacher', 'teacher_id', 'classroom_id'
        )->withPivot('is_class_teacher')->withTimestamps();
    }

    /** First classroom where this teacher is the class teacher (via pivot). */
    public function classTeacherClassroom()
    {
        return $this->classrooms()->wherePivot('is_class_teacher', true)->first();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // TEACHING PROFILE HELPERS
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Does this teacher teach at the given level?
     *   $teacher->teachesAtLevel('Junior Secondary')  // true/false
     */
    public function teachesAtLevel(string $level): bool
    {
        return in_array($level, $this->teaching_levels ?? []);
    }

    /**
     * Does this teacher cover the given CBC pathway?
     *   $teacher->teachesPathway('STEM')
     */
    public function teachesPathway(string $pathway): bool
    {
        return in_array($pathway, $this->teaching_pathways ?? []);
    }

    /**
     * Return qualified subjects filtered to a specific level and/or pathway.
     * Respects the per-subject can_teach_levels override on the pivot.
     *
     * @param  string|null  $level    e.g. "Junior Secondary"
     * @param  string|null  $pathway  e.g. "STEM"
     */
    public function subjectsForLevel(?string $level = null, ?string $pathway = null)
    {
        $subjects = $this->qualifiedSubjects;

        if ($level) {
            $subjects = $subjects->filter(function ($subject) use ($level) {
                // Respect per-pivot level override first
                $canTeachLevels = $subject->pivot->can_teach_levels;
                if (!empty($canTeachLevels)) {
                    return in_array($level, (array) $canTeachLevels);
                }
                // Fall back to subject's own level
                return $subject->level === $level;
            });
        }

        if ($pathway) {
            $subjects = $subjects->filter(
                fn($s) => in_array($s->cbc_pathway, [$pathway, 'All', null])
            );
        }

        return $subjects->values();
    }

    /**
     * Sync the teacher's qualified subject list.
     *
     * @param  array  $subjectIds   e.g. [1, 5, 12]
     * @param  array  $pivotData    Keyed by subject_id for overrides:
     *                              [5 => ['is_primary_subject' => true, 'years_experience' => 3]]
     */
    public function syncQualifiedSubjects(array $subjectIds, array $pivotData = []): void
    {
        $payload = [];

        foreach ($subjectIds as $index => $subjectId) {
            $override = $pivotData[$subjectId] ?? [];
            $payload[$subjectId] = [
                'is_primary_subject' => $override['is_primary_subject'] ?? ($index === 0),
                'years_experience'   => $override['years_experience']   ?? null,
                'can_teach_levels'   => isset($override['can_teach_levels'])
                    ? json_encode($override['can_teach_levels'])
                    : null,
            ];
        }

        $this->qualifiedSubjects()->sync($payload);

        // Keep the JSON shortcut column in sync
        $this->update(['specialization_subjects' => $subjectIds ?: null]);
    }

    /**
     * Is this teacher qualified to teach a specific subject?
     * Checks the teacher_subjects pivot + level restrictions.
     */
    public function isQualifiedForSubject(Subject $subject): bool
    {
        $pivot = $this->qualifiedSubjects()
                      ->where('subjects.id', $subject->id)
                      ->first()
                      ?->pivot;

        if (!$pivot) {
            return false;
        }

        // If the pivot has level overrides, honour them
        if (!empty($pivot->can_teach_levels)) {
            $levels = is_array($pivot->can_teach_levels)
                ? $pivot->can_teach_levels
                : json_decode($pivot->can_teach_levels, true);

            if (!in_array($subject->level, (array) $levels)) {
                return false;
            }
        }

        return true;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // WORKLOAD HELPERS
    // ──────────────────────────────────────────────────────────────────────────

    public function calculateWorkload($academicYearId): array
    {
        return (new WorkloadCalculator())->calculate($this, $academicYearId);
    }

    public function getCurrentWorkload(?int $academicYearId = null, ?int $termId = null): int
    {
        $q = $this->timetablePeriods();
        if ($academicYearId) $q->where('academic_year_id', $academicYearId);
        if ($termId)         $q->where('term_id', $termId);
        return $q->count();
    }

    public function getAvailablePeriods(?int $academicYearId = null, ?int $termId = null): int
    {
        return max(0, $this->max_weekly_lessons - $this->getCurrentWorkload($academicYearId, $termId));
    }

    public function hasReachedMaxWorkload(?int $academicYearId = null): bool
    {
        return $this->getCurrentWorkload($academicYearId) >= $this->max_weekly_lessons;
    }

    public function hasTimetablePeriods(?int $academicYearId = null, ?int $termId = null): bool
    {
        $q = $this->timetablePeriods();
        if ($academicYearId) $q->where('academic_year_id', $academicYearId);
        if ($termId)         $q->where('term_id', $termId);
        return $q->exists();
    }

    public function hasScheduleConflict(
        string $dayOfWeek,
        int $periodNumber,
        int $academicYearId,
        int $termId
    ): bool {
        return $this->timetablePeriods()
                    ->where('academic_year_id', $academicYearId)
                    ->where('term_id', $termId)
                    ->where('day_of_week', $dayOfWeek)
                    ->where('period_number', $periodNumber)
                    ->exists();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // SPECIALIZATION HELPERS
    // ──────────────────────────────────────────────────────────────────────────

    public function hasSpecialization(string $subjectCategory): bool
    {
        if (empty($this->subject_categories)) {
            return true; // No restriction set
        }
        return in_array($subjectCategory, (array) $this->subject_categories);
    }

    public function canTeachSubject(Subject $subject): bool
    {
        return (new SpecializationMatcher())->checkMatch($this, $subject)['matches'];
    }

    public function checkSubjectMatch(Subject $subject): array
    {
        return (new SpecializationMatcher())->checkMatch($this, $subject);
    }

    /**
     * Generate a formatted specialization string from the teacher's qualified subjects.
     *
     * Format:  Category(Subject1, Subject2) | Category(Subject3)
     * Example: Languages(English, Kiswahili) | Sciences(Physics, Chemistry)
     *
     * - Deduplicates by name first to avoid repeats across levels/pathways
     *   (e.g. English at PP, JS, and SS all collapse to one "English" entry).
     * - Groups deduplicated subjects by their category.
     * - Falls back to "General" when no subjects are assigned.
     */
    public function updateSpecializationFromSubjects(): void
    {
        if ($this->qualifiedSubjects->isEmpty()) {
            $this->specialization = 'General';
            $this->saveQuietly();
            return;
        }

        $this->specialization = $this->qualifiedSubjects
            ->unique('name')                            // collapse cross-level duplicates
            ->sortBy('category')                        // consistent ordering
            ->groupBy('category')                       // Languages, Sciences, Mathematics, …
            ->map(function ($subjects, $category) {
                $names = $subjects->pluck('name')->unique()->implode(', ');
                return "{$category}({$names})";         // e.g. Languages(English, Kiswahili)
            })
            ->implode(' | ');                           // Languages(English) | Sciences(Physics)

        $this->saveQuietly();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // CLASS-TEACHER HELPERS
    // ──────────────────────────────────────────────────────────────────────────

    public function isClassTeacher(): bool
    {
        $hasStreams = $this->school?->has_streams ?? false;

        return $hasStreams
            ? ($this->is_class_teacher
                || !is_null($this->current_class_teacher_stream_id)
                || $this->classTeacherStreams()->exists())
            : ($this->is_class_teacher
                || !is_null($this->current_class_teacher_classroom_id)
                || $this->classrooms()->wherePivot('is_class_teacher', true)->exists());
    }

    public function isClassTeacherFor(int $classroomOrStreamId, ?bool $isStream = null): bool
    {
        $isStream = $isStream ?? ($this->school?->has_streams ?? false);

        return $isStream
            ? ($this->current_class_teacher_stream_id == $classroomOrStreamId
                || $this->classTeacherStreams()->where('id', $classroomOrStreamId)->exists())
            : ($this->current_class_teacher_classroom_id == $classroomOrStreamId
                || $this->classrooms()
                         ->where('classrooms.id', $classroomOrStreamId)
                         ->wherePivot('is_class_teacher', true)
                         ->exists());
    }

    public function getCurrentClassTeacherAssignment()
    {
        $hasStreams = $this->school?->has_streams ?? false;

        return $hasStreams
            ? ($this->currentClassTeacherStream ?? $this->classTeacherStreams()->first())
            : ($this->currentClassTeacherClassroom
                ?? $this->classrooms()->wherePivot('is_class_teacher', true)->first());
    }

    public function assignAsClassTeacher($classroomOrStream, ?bool $isStream = null): void
    {
        $isStream = $isStream ?? ($this->school?->has_streams ?? false);

        if ($isStream) {
            $this->current_class_teacher_stream_id = $classroomOrStream->id;
            $this->is_class_teacher = true;
            $this->save();
            $classroomOrStream->update(['class_teacher_id' => $this->id]);
        } else {
            $this->current_class_teacher_classroom_id = $classroomOrStream->id;
            $this->is_class_teacher = true;
            $this->save();
            $this->classrooms()->syncWithoutDetaching([
                $classroomOrStream->id => ['is_class_teacher' => true],
            ]);
        }
    }

    public function removeClassTeacherAssignment(?bool $isStream = null): void
    {
        $isStream = $isStream ?? ($this->school?->has_streams ?? false);

        if ($isStream) {
            Stream::where('class_teacher_id', $this->id)->update(['class_teacher_id' => null]);
            $this->current_class_teacher_stream_id = null;
        } else {
            $this->classrooms()
                 ->wherePivot('is_class_teacher', true)
                 ->each(fn($c) => $this->classrooms()->updateExistingPivot(
                     $c->id, ['is_class_teacher' => false]
                 ));
            $this->current_class_teacher_classroom_id = null;
        }

        $this->is_class_teacher = false;
        $this->save();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // STREAM / CLASSROOM GETTERS
    // ──────────────────────────────────────────────────────────────────────────

    public function getStreamsAsClassTeacher()
    {
        if (!($this->school?->has_streams ?? false)) return collect();
        return $this->classTeacherStreams()->with('classroom')->get();
    }

    public function getStreamsAsTeacher()
    {
        if (!($this->school?->has_streams ?? false)) return collect();
        return $this->teachingStreams()->with('classroom')->get();
    }

    public function getAssignmentCount(): int
    {
        return ($this->school?->has_streams ?? false)
            ? $this->teachingStreams()->count()
            : $this->classrooms()->count();
    }

    public function getSpecializationSubjects()
    {
        if (empty($this->specialization_subjects)) return collect();
        return Subject::whereIn('id', $this->specialization_subjects)
                      ->where('school_id', $this->school_id)
                      ->get();
    }
}