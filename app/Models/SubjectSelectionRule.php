<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubjectSelectionRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'curriculum_type',
        'level',
        'rule_type',
        'subject_ids',
        'max_count',
        'min_count',
        'description',
        'is_active'
    ];

    protected $casts = [
        'subject_ids' => 'array',
        'is_active' => 'boolean',
    ];

    // Relationships
    public function incompatiblePairs(): HasMany
    {
        return $this->hasMany(IncompatibleSubjectPair::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForCurriculum($query, $curriculumType)
    {
        return $query->where('curriculum_type', $curriculumType);
    }

    public function scopeForLevel($query, $level)
    {
        return $query->where('level', $level);
    }

    // Helper methods
    public function validate($selectedSubjectIds)
    {
        switch ($this->rule_type) {
            case 'max_sciences':
                return $this->validateMaxCount($selectedSubjectIds);
            
            case 'min_languages':
                return $this->validateMinCount($selectedSubjectIds);
            
            case 'required_subject':
                return $this->validateRequiredSubject($selectedSubjectIds);
            
            case 'incompatible_subjects':
                return $this->validateIncompatibleSubjects($selectedSubjectIds);
            
            case 'prerequisite_subject':
                return $this->validatePrerequisite($selectedSubjectIds);
        }

        return ['valid' => true];
    }

    private function validateMaxCount($selectedSubjectIds)
    {
        $relevantSubjects = array_intersect($selectedSubjectIds, $this->subject_ids ?? []);
        $count = count($relevantSubjects);

        if ($count > $this->max_count) {
            return [
                'valid' => false,
                'message' => $this->description . " (Maximum: {$this->max_count}, Selected: {$count})"
            ];
        }

        return ['valid' => true];
    }

    private function validateMinCount($selectedSubjectIds)
    {
        $relevantSubjects = array_intersect($selectedSubjectIds, $this->subject_ids ?? []);
        $count = count($relevantSubjects);

        if ($count < $this->min_count) {
            return [
                'valid' => false,
                'message' => $this->description . " (Minimum: {$this->min_count}, Selected: {$count})"
            ];
        }

        return ['valid' => true];
    }

    private function validateRequiredSubject($selectedSubjectIds)
    {
        $hasRequired = !empty(array_intersect($selectedSubjectIds, $this->subject_ids ?? []));

        if (!$hasRequired) {
            return [
                'valid' => false,
                'message' => $this->description
            ];
        }

        return ['valid' => true];
    }

    private function validateIncompatibleSubjects($selectedSubjectIds)
    {
        // Check incompatible pairs
        foreach ($this->incompatiblePairs as $pair) {
            if (in_array($pair->subject_id, $selectedSubjectIds) && 
                in_array($pair->incompatible_with_subject_id, $selectedSubjectIds)) {
                return [
                    'valid' => false,
                    'message' => $this->description
                ];
            }
        }

        return ['valid' => true];
    }

    private function validatePrerequisite($selectedSubjectIds)
    {
        // Implement prerequisite logic
        // This would need additional fields to track which subject requires which
        return ['valid' => true];
    }
}