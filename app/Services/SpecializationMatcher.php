<?php

namespace App\Services;

use App\Models\Teacher;
use App\Models\Subject;

class SpecializationMatcher
{
    public function checkMatch(Teacher $teacher, Subject $subject)
    {
        // Check curriculum compatibility
        if (!$this->checkCurriculumMatch($teacher, $subject)) {
            return [
                'matches' => false,
                'reason' => 'curriculum_mismatch',
                'severity' => 'error',
                'message' => "Teacher specializes in {$teacher->curriculum_specialization}, but subject is {$subject->curriculum_type}"
            ];
        }
        
        // Check subject category match
        if (!$this->checkCategoryMatch($teacher, $subject)) {
            return [
                'matches' => false,
                'reason' => 'category_mismatch',
                'severity' => 'warning',
                'message' => "Teacher's specialization doesn't include {$subject->category}. Consider assigning a teacher with {$subject->category} specialization."
            ];
        }
        
        return [
            'matches' => true,
            'message' => 'Teacher is qualified for this subject',
            'severity' => 'success'
        ];
    }
    
    private function checkCurriculumMatch($teacher, $subject)
    {
        // If teacher can teach both, always allow
        if ($teacher->curriculum_specialization === 'Both') {
            return true;
        }
        
        // Otherwise must match exactly
        return $teacher->curriculum_specialization === $subject->curriculum_type;
    }
    
    private function checkCategoryMatch($teacher, $subject)
    {
        // If teacher has no category restriction, allow
        if (empty($teacher->subject_categories)) {
            return true;
        }
        
        // Check if subject category is in teacher's specializations
        return in_array($subject->category, $teacher->subject_categories);
    }
    
    public function findQualifiedTeachers(Subject $subject, $schoolId)
    {
        return \App\Models\Teacher::where('school_id', $schoolId)
            ->where(function($query) use ($subject) {
                $query->where('curriculum_specialization', $subject->curriculum_type)
                      ->orWhere('curriculum_specialization', 'Both');
            })
            ->get()
            ->filter(function($teacher) use ($subject) {
                if (empty($teacher->subject_categories)) {
                    return true;
                }
                return in_array($subject->category, $teacher->subject_categories);
            });
    }
}