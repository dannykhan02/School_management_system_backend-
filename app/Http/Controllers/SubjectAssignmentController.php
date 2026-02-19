<?php

namespace App\Http\Controllers;

use App\Models\SubjectAssignment;
use App\Models\Teacher;
use App\Models\Subject;
use App\Models\AcademicYear;
use App\Models\Stream;
use App\Models\Classroom;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class SubjectAssignmentController extends Controller
{
    /**
     * Display a listing of subject assignments.
     * Can be filtered by teacher, subject, academic_year, or stream using query parameters.
     * Example: /api/?teacher_id=1&academic_year_id=5
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        // Get school information to check if streams are enabled
        $school = School::find($user->school_id);
        $hasStreams = $school ? $school->has_streams : false;

        $query = SubjectAssignment::with(['teacher.user', 'subject', 'academicYear'])
                                  ->whereHas('teacher', function($query) use ($user) {
                                      $query->where('school_id', $user->school_id);
                                  });

        // Add appropriate relationships based on stream configuration
        if ($hasStreams) {
            $query->with(['stream.classroom']);
        } else {
            $query->with(['classroom']);
        }

        // Apply filters if they exist in request
        if ($request->has('teacher_id')) {
            $query->where('teacher_id', $request->teacher_id);
        }
        if ($request->has('subject_id')) {
            $query->where('subject_id', $request->subject_id);
        }
        if ($request->has('academic_year_id')) {
            $query->where('academic_year_id', $request->academic_year_id);
        }
        if ($request->has('stream_id')) {
            $query->where('stream_id', $request->stream_id);
        }

        $assignments = $query->get();

        return response()->json([
            'status' => 'success',
            'has_streams' => $hasStreams,
            'data' => $assignments
        ]);
    }

    /**
     * Store a newly created subject assignment in storage.
     * Handles both schools with and without streams.
     */
    public function store(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        // Get school information to check if streams are enabled
        $school = School::find($user->school_id);
        $hasStreams = $school ? $school->has_streams : false;

        // Log the incoming request for debugging
        Log::info('SubjectAssignment store request:', $request->all());

        // Base validation rules
        $validationRules = [
            'teacher_id' => 'required|exists:teachers,id',
            'subject_id' => 'required|exists:subjects,id',
            'academic_year_id' => 'required|exists:academic_years,id',
            'weekly_periods' => 'nullable|integer|min:1',
            'assignment_type' => 'nullable|in:main_teacher,assistant_teacher,substitute',
        ];

        // Add appropriate validation based on school type
        if ($hasStreams) {
            $validationRules['stream_id'] = 'required|exists:streams,id';
        } else {
            $validationRules['classroom_id'] = 'required|exists:classrooms,id';
        }

        $validated = $request->validate($validationRules);

        // Verify all entities belong to the same school
        $teacher = Teacher::findOrFail($validated['teacher_id']);
        $subject = Subject::findOrFail($validated['subject_id']);
        $academicYear = AcademicYear::findOrFail($validated['academic_year_id']);

        if ($teacher->school_id !== $user->school_id || 
            $subject->school_id !== $user->school_id ||
            $academicYear->school_id !== $user->school_id) {
            return response()->json(['message' => 'All entities must belong to the same school'], 422);
        }

        // For schools with streams, verify stream belongs to the same school
        if ($hasStreams && isset($validated['stream_id'])) {
            $stream = Stream::findOrFail($validated['stream_id']);
            if ($stream->classroom->school_id !== $user->school_id) {
                return response()->json(['message' => 'The stream does not belong to your school'], 422);
            }
        }

        // For schools without streams, verify classroom belongs to the same school
        if (!$hasStreams && isset($validated['classroom_id'])) {
            $classroom = Classroom::findOrFail($validated['classroom_id']);
            if ($classroom->school_id !== $user->school_id) {
                return response()->json(['message' => 'The classroom does not belong to your school'], 422);
            }
        }

        // ========== VALIDATION LOGIC ==========

        // Store warnings to return to frontend
        $warnings = [];

        // 1. Check curriculum qualification (WARNING ONLY - not blocking)
        if ($subject->curriculum_type === 'CBC' && 
            !in_array($teacher->curriculum_specialization, ['CBC', 'Both'])) {
            $warnings[] = "Teacher's curriculum specialization is {$teacher->curriculum_specialization}, but this is a CBC subject. Assignment still allowed.";
        }

        if ($subject->curriculum_type === '8-4-4' && 
            !in_array($teacher->curriculum_specialization, ['8-4-4', 'Both'])) {
            $warnings[] = "Teacher's curriculum specialization is {$teacher->curriculum_specialization}, but this is an 8-4-4 subject. Assignment still allowed.";
        }

        // 2. Check workload
        $workloadCalculator = new \App\Services\WorkloadCalculator();
        $workload = $workloadCalculator->calculate($teacher, $validated['academic_year_id']);
        
        $weeklyPeriods = $validated['weekly_periods'] ?? 5;
        $newTotal = $workload['total_lessons'] + $weeklyPeriods;
        
        if ($newTotal > $teacher->max_weekly_lessons) {
            return response()->json([
                'status' => 'error',
                'message' => "Teacher will be overloaded",
                'details' => [
                    'current_lessons' => $workload['total_lessons'],
                    'adding' => $weeklyPeriods,
                    'new_total' => $newTotal,
                    'max_allowed' => $teacher->max_weekly_lessons,
                    'suggestion' => "Teacher can only take " . max(0, $teacher->max_weekly_lessons - $workload['total_lessons']) . " more lessons"
                ]
            ], 422);
        }

        // 3. Check specialization match
        $specializationMatcher = new \App\Services\SpecializationMatcher();
        $matchResult = $specializationMatcher->checkMatch($teacher, $subject);
        
        $isOutsideSpecialization = !$matchResult['matches'];
        
        if ($isOutsideSpecialization && $matchResult['severity'] === 'warning') {
            $warnings[] = $matchResult['message'];
        }

        // 👇 NEW: 4. Check level match (WARNING ONLY - not blocking)
        $teachingLevels = $teacher->teaching_levels ?? [];
        if (!empty($teachingLevels) && !in_array($subject->level, $teachingLevels)) {
            $warnings[] = "Subject \"{$subject->name}\" is for {$subject->level} level but this teacher covers: "
                . implode(', ', $teachingLevels) . ". Assignment still allowed.";
        }

        // 👇 NEW: 5. Check pathway match for Senior Secondary (WARNING ONLY - not blocking)
        $teachingPathways = $teacher->teaching_pathways ?? [];
        if (
            $subject->level === 'Senior Secondary'
            && !empty($teachingPathways)
            && $subject->cbc_pathway
            && $subject->cbc_pathway !== 'All'
            && !in_array($subject->cbc_pathway, $teachingPathways)
        ) {
            $warnings[] = "Subject \"{$subject->name}\" belongs to the {$subject->cbc_pathway} pathway but this teacher covers: "
                . implode(', ', $teachingPathways) . ". Assignment still allowed.";
        }

        // 6. Check for duplicate assignment
        $existingAssignmentQuery = SubjectAssignment::where('teacher_id', $validated['teacher_id'])
                                          ->where('subject_id', $validated['subject_id'])
                                          ->where('academic_year_id', $validated['academic_year_id']);

        if ($hasStreams) {
            $existingAssignmentQuery->where('stream_id', $validated['stream_id']);
        } else {
            $existingAssignmentQuery->where('classroom_id', $validated['classroom_id']);
        }

        if ($existingAssignmentQuery->exists()) {
            return response()->json(['message' => 'This assignment already exists'], 409);
        }

        // ========== CREATE ASSIGNMENT ==========
        
        $assignment = SubjectAssignment::create(array_merge($validated, [
            'is_outside_specialization' => $isOutsideSpecialization,
            'workload_impact_score' => $weeklyPeriods,
            'is_kicd_compliant' => $subject->is_kicd_compulsory ? 1 : 0,
            'learning_area' => $subject->learning_area,
            'assignment_priority' => $hasStreams 
                ? ($teacher->isClassTeacherFor($validated['stream_id'] ?? null) ? 90 : 50)
                : ($teacher->isClassTeacherFor($validated['classroom_id'] ?? null) ? 90 : 50),
        ]));

        // Load relationships
        if ($hasStreams) {
            $assignment->load(['teacher.user', 'subject', 'academicYear', 'stream.classroom']);
        } else {
            $assignment->load(['teacher.user', 'subject', 'academicYear', 'classroom']);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Subject assignment created successfully.',
            'has_streams' => $hasStreams,
            'data' => $assignment,
            'warnings' => $warnings,
            'workload_info' => [
                'previous_total' => $workload['total_lessons'],
                'new_total' => $newTotal,
                'status' => $workload['status'],
            ]
        ], 201);
    }

    /**
     * Store multiple subject assignments at once.
     * Handles both schools with and without streams.
     */
    public function storeBatch(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        // Get school information to check if streams are enabled
        $school = School::find($user->school_id);
        $hasStreams = $school ? $school->has_streams : false;

        // Base validation rules
        $validationRules = [
            'assignments' => 'required|array',
            'assignments.*.teacher_id' => 'required|exists:teachers,id',
            'assignments.*.subject_id' => 'required|exists:subjects,id',
            'assignments.*.academic_year_id' => 'required|exists:academic_years,id',
            'assignments.*.weekly_periods' => 'nullable|integer|min:1',
            'assignments.*.assignment_type' => 'nullable|in:main_teacher,assistant_teacher,substitute',
        ];

        // Add appropriate validation based on school type
        if ($hasStreams) {
            $validationRules['assignments.*.stream_id'] = 'required|exists:streams,id';
        } else {
            $validationRules['assignments.*.classroom_id'] = 'required|exists:classrooms,id';
        }

        $validated = $request->validate($validationRules);

        $createdAssignments = [];
        $errors = [];
        $batchWarnings = [];
        
        foreach ($validated['assignments'] as $index => $assignmentData) {
            try {
                // Verify all entities belong to the same school
                $teacher = Teacher::findOrFail($assignmentData['teacher_id']);
                $subject = Subject::findOrFail($assignmentData['subject_id']);
                $academicYear = AcademicYear::findOrFail($assignmentData['academic_year_id']);

                if ($teacher->school_id !== $user->school_id || 
                    $subject->school_id !== $user->school_id ||
                    $academicYear->school_id !== $user->school_id) {
                    $errors[] = "Assignment #".($index+1).": All entities must belong to the same school";
                    continue;
                }

                // For schools with streams, verify stream belongs to the same school
                if ($hasStreams && isset($assignmentData['stream_id'])) {
                    $stream = Stream::findOrFail($assignmentData['stream_id']);
                    if ($stream->classroom->school_id !== $user->school_id) {
                        $errors[] = "Assignment #".($index+1).": The stream does not belong to your school";
                        continue;
                    }
                }

                // For schools without streams, verify classroom belongs to the same school
                if (!$hasStreams && isset($assignmentData['classroom_id'])) {
                    $classroom = Classroom::findOrFail($assignmentData['classroom_id']);
                    if ($classroom->school_id !== $user->school_id) {
                        $errors[] = "Assignment #".($index+1).": The classroom does not belong to your school";
                        continue;
                    }
                }

                // Check curriculum qualification (WARNING ONLY - store but don't block)
                if ($subject->curriculum_type === 'CBC' && 
                    !in_array($teacher->curriculum_specialization, ['CBC', 'Both'])) {
                    $batchWarnings[] = "Assignment #".($index+1).": Teacher's curriculum specialization is {$teacher->curriculum_specialization}, but this is a CBC subject. Assignment still allowed.";
                }

                if ($subject->curriculum_type === '8-4-4' && 
                    !in_array($teacher->curriculum_specialization, ['8-4-4', 'Both'])) {
                    $batchWarnings[] = "Assignment #".($index+1).": Teacher's curriculum specialization is {$teacher->curriculum_specialization}, but this is an 8-4-4 subject. Assignment still allowed.";
                }

                // 2. Check workload (blocking error)
                $workloadCalculator = new \App\Services\WorkloadCalculator();
                $workload = $workloadCalculator->calculate($teacher, $assignmentData['academic_year_id']);
                
                $weeklyPeriods = $assignmentData['weekly_periods'] ?? 5;
                $newTotal = $workload['total_lessons'] + $weeklyPeriods;
                
                if ($newTotal > $teacher->max_weekly_lessons) {
                    $errors[] = "Assignment #".($index+1).": Teacher will be overloaded (Current: {$workload['total_lessons']}, Adding: {$weeklyPeriods}, Max: {$teacher->max_weekly_lessons})";
                    continue;
                }

                // 3. Check specialization match (warning only)
                $specializationMatcher = new \App\Services\SpecializationMatcher();
                $matchResult = $specializationMatcher->checkMatch($teacher, $subject);
                
                $isOutsideSpecialization = !$matchResult['matches'];
                
                if ($isOutsideSpecialization && $matchResult['severity'] === 'warning') {
                    $batchWarnings[] = "Assignment #".($index+1).": " . $matchResult['message'];
                }

                // 👇 NEW: 4. Check level match (WARNING ONLY)
                $teachingLevels = $teacher->teaching_levels ?? [];
                if (!empty($teachingLevels) && !in_array($subject->level, $teachingLevels)) {
                    $batchWarnings[] = "Assignment #".($index+1).": Subject \"{$subject->name}\" is for {$subject->level} level but this teacher covers: "
                        . implode(', ', $teachingLevels) . ". Assignment still allowed.";
                }

                // 👇 NEW: 5. Check pathway match for Senior Secondary (WARNING ONLY)
                $teachingPathways = $teacher->teaching_pathways ?? [];
                if (
                    $subject->level === 'Senior Secondary'
                    && !empty($teachingPathways)
                    && $subject->cbc_pathway
                    && $subject->cbc_pathway !== 'All'
                    && !in_array($subject->cbc_pathway, $teachingPathways)
                ) {
                    $batchWarnings[] = "Assignment #".($index+1).": Subject \"{$subject->name}\" belongs to the {$subject->cbc_pathway} pathway but this teacher covers: "
                        . implode(', ', $teachingPathways) . ". Assignment still allowed.";
                }

                // Check for duplicate assignment (blocking error)
                $existingAssignmentQuery = SubjectAssignment::where('teacher_id', $assignmentData['teacher_id'])
                                                      ->where('subject_id', $assignmentData['subject_id'])
                                                      ->where('academic_year_id', $assignmentData['academic_year_id']);

                if ($hasStreams) {
                    $existingAssignmentQuery->where('stream_id', $assignmentData['stream_id']);
                } else {
                    $existingAssignmentQuery->where('classroom_id', $assignmentData['classroom_id']);
                }

                $existingAssignment = $existingAssignmentQuery->first();

                if ($existingAssignment) {
                    $errors[] = "Assignment #".($index+1).": This assignment already exists";
                    continue;
                }

                // Create assignment
                $createdAssignment = SubjectAssignment::create(array_merge($assignmentData, [
                    'is_outside_specialization' => $isOutsideSpecialization,
                    'workload_impact_score' => $weeklyPeriods,
                    'is_kicd_compliant' => $subject->is_kicd_compulsory ? 1 : 0,
                    'learning_area' => $subject->learning_area,
                    'assignment_priority' => $hasStreams 
                        ? ($teacher->isClassTeacherFor($assignmentData['stream_id'] ?? null) ? 90 : 50)
                        : ($teacher->isClassTeacherFor($assignmentData['classroom_id'] ?? null) ? 90 : 50),
                ]));

                $createdAssignments[] = $createdAssignment;
            } catch (\Exception $e) {
                $errors[] = "Assignment #".($index+1).": ".$e->getMessage();
            }
        }

        if (!empty($errors)) {
            return response()->json([
                'message' => 'Some assignments could not be created',
                'errors' => $errors
            ], 422);
        }

        // Load appropriate relationships for created assignments
        foreach ($createdAssignments as $assignment) {
            if ($hasStreams) {
                $assignment->load(['teacher.user', 'subject', 'academicYear', 'stream.classroom']);
            } else {
                $assignment->load(['teacher.user', 'subject', 'academicYear', 'classroom']);
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Subject assignments created successfully.',
            'has_streams' => $hasStreams,
            'data' => $createdAssignments,
            'warnings' => $batchWarnings
        ], 201);
    }

    /**
     * Display the specified subject assignment.
     */
    public function show($id)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        // Get school information to check if streams are enabled
        $school = School::find($user->school_id);
        $hasStreams = $school ? $school->has_streams : false;

        $query = SubjectAssignment::with(['teacher.user', 'subject', 'academicYear']);
        
        if ($hasStreams) {
            $query->with(['stream.classroom']);
        } else {
            $query->with(['classroom']);
        }
        
        $assignment = $query->findOrFail($id);

        if ($assignment->teacher->school_id !== $user->school_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'status' => 'success',
            'has_streams' => $hasStreams,
            'data' => $assignment
        ]);
    }

    /**
     * Update the specified subject assignment in storage.
     */
    public function update(Request $request, $id)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        // Get school information to check if streams are enabled
        $school = School::find($user->school_id);
        $hasStreams = $school ? $school->has_streams : false;

        $assignment = SubjectAssignment::findOrFail($id);

        if ($assignment->teacher->school_id !== $user->school_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Base validation rules
        $validationRules = [
            'weekly_periods' => 'sometimes|required|integer|min:1',
            'assignment_type' => 'sometimes|required|in:main_teacher,assistant_teacher,substitute',
        ];

        if ($hasStreams) {
            $validationRules['stream_id'] = 'sometimes|required|exists:streams,id';
        } else {
            $validationRules['classroom_id'] = 'sometimes|required|exists:classrooms,id';
        }

        $validated = $request->validate($validationRules);

        if ($hasStreams && isset($validated['stream_id'])) {
            $stream = Stream::findOrFail($validated['stream_id']);
            if ($stream->classroom->school_id !== $user->school_id) {
                return response()->json(['message' => 'The stream does not belong to your school'], 422);
            }
        }

        if (!$hasStreams && isset($validated['classroom_id'])) {
            $classroom = Classroom::findOrFail($validated['classroom_id']);
            if ($classroom->school_id !== $user->school_id) {
                return response()->json(['message' => 'The classroom does not belong to your school'], 422);
            }
        }

        $assignment->update($validated);

        if ($hasStreams) {
            $assignment->load(['teacher.user', 'subject', 'academicYear', 'stream.classroom']);
        } else {
            $assignment->load(['teacher.user', 'subject', 'academicYear', 'classroom']);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Subject assignment updated successfully.',
            'has_streams' => $hasStreams,
            'data' => $assignment
        ]);
    }

    /**
     * Remove the specified subject assignment from storage.
     */
    public function destroy($id)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        $assignment = SubjectAssignment::findOrFail($id);

        if ($assignment->teacher->school_id !== $user->school_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $assignment->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Subject assignment deleted successfully.'
        ]);
    }
}