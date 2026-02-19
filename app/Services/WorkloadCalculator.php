<?php

namespace App\Services;

use App\Models\Teacher;
use App\Models\SubjectAssignment;

class WorkloadCalculator
{
    public function calculate(Teacher $teacher, $academicYearId)
    {
        $assignments = SubjectAssignment::where('teacher_id', $teacher->id)
            ->where('academic_year_id', $academicYearId)
            ->with('subject')
            ->get();
        
        $totalLessons = $assignments->sum('weekly_periods');
        $subjectCount = $assignments->pluck('subject_id')->unique()->count();
        $classroomCount = $assignments->count();
        
        return [
            'total_lessons' => $totalLessons,
            'subject_count' => $subjectCount,
            'classroom_count' => $classroomCount,
            'max_lessons' => $teacher->max_weekly_lessons,
            'min_lessons' => $teacher->min_weekly_lessons,
            'status' => $this->getStatus($totalLessons, $teacher),
            'is_overloaded' => $totalLessons > $teacher->max_weekly_lessons,
            'is_underloaded' => $totalLessons < $teacher->min_weekly_lessons,
            'available_capacity' => max(0, $teacher->max_weekly_lessons - $totalLessons),
            'percentage_used' => $teacher->max_weekly_lessons > 0 
                ? round(($totalLessons / $teacher->max_weekly_lessons) * 100, 1)
                : 0,
            'assignments' => $assignments->map(function($a) {
                return [
                    'subject' => $a->subject->name ?? 'Unknown',
                    'classroom' => $a->classroom->name ?? $a->stream->name ?? 'Unknown',
                    'weekly_periods' => $a->weekly_periods,
                ];
            }),
        ];
    }
    
    private function getStatus($totalLessons, $teacher)
    {
        if ($totalLessons > $teacher->max_weekly_lessons) {
            return 'overloaded';
        } elseif ($totalLessons < $teacher->min_weekly_lessons) {
            return 'underloaded';
        } else {
            return 'optimal';
        }
    }
    
    public function getStatusColor($status)
    {
        return match($status) {
            'overloaded' => 'red',
            'underloaded' => 'yellow',
            'optimal' => 'green',
            default => 'gray'
        };
    }
}