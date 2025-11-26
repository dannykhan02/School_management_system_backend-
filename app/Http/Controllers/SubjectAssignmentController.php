<?php

namespace App\Http\Controllers;

use App\Models\SubjectAssignment;
use App\Models\Teacher;
use App\Models\Subject;
use App\Models\AcademicYear;
use App\Models\Stream;
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
        $query = SubjectAssignment::with(['teacher.user', 'subject', 'academicYear', 'stream.classroom'])
                                  ->whereHas('teacher', function($query) use ($user) {
                                      $query->where('school_id', $user->school_id);
                                  });

        // Apply filters if they exist in the request
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

        return response()->json($assignments);
    }

    /**
     * Store a newly created subject assignment in storage.
     */
    public function store(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'teacher_id' => 'required|exists:teachers,id',
            'subject_id' => 'required|exists:subjects,id',
            'academic_year_id' => 'required|exists:academic_years,id',
            'stream_id' => 'required|exists:streams,id',
            'weekly_periods' => 'nullable|integer|min:1',
            'assignment_type' => 'nullable|in:main_teacher,assistant_teacher,substitute',
        ]);

        // Verify all entities belong to the same school
        $teacher = Teacher::findOrFail($validated['teacher_id']);
        $subject = Subject::findOrFail($validated['subject_id']);
        $academicYear = AcademicYear::findOrFail($validated['academic_year_id']);
        $stream = Stream::findOrFail($validated['stream_id']);

        if ($teacher->school_id !== $user->school_id || 
            $subject->school_id !== $user->school_id ||
            $academicYear->school_id !== $user->school_id ||
            $stream->classroom->school_id !== $user->school_id) {
            return response()->json(['message' => 'All entities must belong to the same school'], 422);
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
        $existingAssignment = SubjectAssignment::where('teacher_id', $validated['teacher_id'])
                                              ->where('subject_id', $validated['subject_id'])
                                              ->where('academic_year_id', $validated['academic_year_id'])
                                              ->where('stream_id', $validated['stream_id'])
                                              ->first();

        if ($existingAssignment) {
            return response()->json(['message' => 'This assignment already exists'], 409);
        }

        $assignment = SubjectAssignment::create($validated);

        return response()->json($assignment->load(['teacher.user', 'subject', 'academicYear', 'stream']), 201);
    }

    /**
     * Display the specified subject assignment.
     */
    public function show($id)
    {
        $user = Auth::user();
        $assignment = SubjectAssignment::with(['teacher.user', 'subject', 'academicYear', 'stream'])->findOrFail($id);

        if ($assignment->teacher->school_id !== $user->school_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json($assignment);
    }

    /**
     * Update the specified subject assignment in storage.
     */
    public function update(Request $request, $id)
    {
        $user = Auth::user();
        $assignment = SubjectAssignment::findOrFail($id);

        if ($assignment->teacher->school_id !== $user->school_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'weekly_periods' => 'sometimes|required|integer|min:1',
            'assignment_type' => 'sometimes|required|in:main_teacher,assistant_teacher,substitute',
        ]);

        $assignment->update($validated);

        return response()->json($assignment->load(['teacher.user', 'subject', 'academicYear', 'stream']));
    }

    /**
     * Remove the specified subject assignment from storage.
     */
    public function destroy($id)
    {
        $user = Auth::user();
        $assignment = SubjectAssignment::findOrFail($id);

        if ($assignment->teacher->school_id !== $user->school_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $assignment->delete();

        return response()->json(['message' => 'Assignment deleted successfully']);
    }
}