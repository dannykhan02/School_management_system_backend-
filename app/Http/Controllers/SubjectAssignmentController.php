<?php

namespace App\Http\Controllers;

use App\Models\SubjectAssignment;
use App\Models\Teacher;
use App\Models\Subject;
use App\Models\AcademicYear;
use App\Models\Stream;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SubjectAssignmentController extends Controller
{
    /**
     * Display a listing of subject assignments.
     * Can be filtered by teacher, subject, academic_year, or stream using query parameters.
     * Example: /api/subject-assignments?teacher_id=1&academic_year_id=5
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

        // Add stream relationship only if school has streams
        if ($hasStreams) {
            $query->with(['stream.classroom']);
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

        // Base validation rules
        $validationRules = [
            'teacher_id' => 'required|exists:teachers,id',
            'subject_id' => 'required|exists:subjects,id',
            'academic_year_id' => 'required|exists:academic_years,id',  // This is now required
            'weekly_periods' => 'nullable|integer|min:1',
            'assignment_type' => 'nullable|in:main_teacher,assistant_teacher,substitute',
        ];

        // Add stream_id validation only for schools with streams
        if ($hasStreams) {
            $validationRules['stream_id'] = 'required|exists:streams,id';
        } else {
            $validationRules['stream_id'] = 'nullable|exists:streams,id';
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

        // Check if teacher is qualified for this subject's curriculum
        if ($subject->curriculum_type === 'CBC' && 
            !in_array($teacher->curriculum_specialization, ['CBC', 'Both'])) {
            return response()->json(['message' => 'Teacher is not qualified to teach CBC subjects'], 422);
        }
        
        if ($subject->curriculum_type === '8-4-4' && 
            !in_array($teacher->curriculum_specialization, ['8-4-4', 'Both'])) {
            return response()->json(['message' => 'Teacher is not qualified to teach 8-4-4 subjects'], 422);
        }

        // Check for duplicate assignment
        $existingAssignmentQuery = SubjectAssignment::where('teacher_id', $validated['teacher_id'])
                                              ->where('subject_id', $validated['subject_id'])
                                              ->where('academic_year_id', $validated['academic_year_id']);

        // Add stream_id to duplicate check only for schools with streams
        if ($hasStreams) {
            $existingAssignmentQuery->where('stream_id', $validated['stream_id']);
        }

        $existingAssignment = $existingAssignmentQuery->first();

        if ($existingAssignment) {
            return response()->json(['message' => 'This assignment already exists'], 409);
        }

        $assignment = SubjectAssignment::create($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Subject assignment created successfully.',
            'has_streams' => $hasStreams,
            'data' => $assignment->load(['teacher.user', 'subject', 'academicYear', 'stream.classroom'])
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
            'assignments.*.academic_year_id' => 'required|exists:academic_years,id',  // This is now required
            'assignments.*.weekly_periods' => 'nullable|integer|min:1',
            'assignments.*.assignment_type' => 'nullable|in:main_teacher,assistant_teacher,substitute',
        ];

        // Add stream_id validation only for schools with streams
        if ($hasStreams) {
            $validationRules['assignments.*.stream_id'] = 'required|exists:streams,id';
        }

        $validated = $request->validate($validationRules);

        $createdAssignments = [];
        $errors = [];
        
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

                // Check if teacher is qualified for this subject's curriculum
                if ($subject->curriculum_type === 'CBC' && 
                    !in_array($teacher->curriculum_specialization, ['CBC', 'Both'])) {
                    $errors[] = "Assignment #".($index+1).": Teacher is not qualified to teach CBC subjects";
                    continue;
                }
                
                if ($subject->curriculum_type === '8-4-4' && 
                    !in_array($teacher->curriculum_specialization, ['8-4-4', 'Both'])) {
                    $errors[] = "Assignment #".($index+1).": Teacher is not qualified to teach 8-4-4 subjects";
                    continue;
                }

                // Check for duplicate assignment
                $existingAssignmentQuery = SubjectAssignment::where('teacher_id', $assignmentData['teacher_id'])
                                                      ->where('subject_id', $assignmentData['subject_id'])
                                                      ->where('academic_year_id', $assignmentData['academic_year_id']);

                // Add stream_id to duplicate check only for schools with streams
                if ($hasStreams) {
                    $existingAssignmentQuery->where('stream_id', $assignmentData['stream_id']);
                }

                $existingAssignment = $existingAssignmentQuery->first();

                if ($existingAssignment) {
                    $errors[] = "Assignment #".($index+1).": This assignment already exists";
                    continue;
                }

                $createdAssignments[] = SubjectAssignment::create($assignmentData);
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

        return response()->json([
            'status' => 'success',
            'message' => 'Subject assignments created successfully.',
            'has_streams' => $hasStreams,
            'data' => $createdAssignments
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
        
        // Add stream relationship only if school has streams
        if ($hasStreams) {
            $query->with(['stream.classroom']);
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

        // Add stream_id validation only for schools with streams
        if ($hasStreams) {
            $validationRules['stream_id'] = 'sometimes|required|exists:streams,id';
        }

        $validated = $request->validate($validationRules);

        // For schools with streams, verify stream belongs to the same school
        if ($hasStreams && isset($validated['stream_id'])) {
            $stream = Stream::findOrFail($validated['stream_id']);
            if ($stream->classroom->school_id !== $user->school_id) {
                return response()->json(['message' => 'The stream does not belong to your school'], 422);
            }
        }

        $assignment->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Subject assignment updated successfully.',
            'has_streams' => $hasStreams,
            'data' => $assignment->load(['teacher.user', 'subject', 'academicYear', 'stream.classroom'])
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