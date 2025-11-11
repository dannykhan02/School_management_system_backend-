<?php

namespace App\Http\Controllers;

use App\Models\Teacher;
use App\Models\Classroom;
use App\Models\School;
use App\Models\Stream;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class TeacherController extends Controller
{
    /**
     * Display a listing of all teachers for the current school.
     */
    public function index()
    {
        $user = Auth::user();
        
        // Get teachers of the user's school with assigned classrooms
        $teachers = Teacher::with(['user', 'classrooms', 'subjects', 'streams'])
            ->where('school_id', $user->school_id)
            ->get();

        return response()->json($teachers);
    }

    /**
     * Store a newly created teacher in storage.
     */
    public function store(Request $request)
    {
        $user = Auth::user();
        
        $validated = $request->validate([
            'user_id' => [
                'required',
                'exists:users,id',
                Rule::unique('teachers', 'user_id'),
                // Verify user belongs to the same school
                Rule::exists('users', 'id')->where('school_id', $user->school_id)
            ],
            'qualification' => 'nullable|string|max:255',
            'employment_type' => 'nullable|string|max:255',
            'tsc_number' => 'nullable|string|max:255',
        ]);

        // Always use the authenticated user's school_id
        $validated['school_id'] = $user->school_id;

        $teacher = Teacher::create($validated);

        return response()->json([
            'message' => 'Teacher created successfully',
            'data' => $teacher
        ], 201);
    }

    /**
     * Display the specified teacher.
     */
    public function show($id)
    {
        $user = Auth::user();
        $teacher = Teacher::with(['user', 'school', 'subjects', 'classrooms', 'streams'])->findOrFail($id);
        
        // Check if teacher belongs to user's school
        if ($teacher->school_id !== $user->school_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
        return response()->json($teacher);
    }

    /**
     * Update the specified teacher in storage.
     */
    public function update(Request $request, $id)
    {
        $user = Auth::user();
        $teacher = Teacher::findOrFail($id);
        
        // Check if teacher belongs to user's school
        if ($teacher->school_id !== $user->school_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'user_id' => [
                'sometimes',
                'required',
                'exists:users,id',
                Rule::unique('teachers', 'user_id')->ignore($id),
                // Verify user belongs to the same school
                Rule::exists('users', 'id')->where('school_id', $user->school_id)
            ],
            'qualification' => 'nullable|string|max:255',
            'employment_type' => 'nullable|string|max:255',
            'tsc_number' => 'nullable|string|max:255',
        ]);

        $teacher->update($validated);

        return response()->json([
            'message' => 'Teacher updated successfully',
            'data' => $teacher->load(['user'])
        ]);
    }

    /**
     * Remove the specified teacher from storage.
     */
    public function destroy($id)
    {
        $user = Auth::user();
        $teacher = Teacher::findOrFail($id);
        
        // Check if teacher belongs to user's school
        if ($teacher->school_id !== $user->school_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $teacher->delete();

        return response()->json(['message' => 'Teacher deleted successfully']);
    }

    /**
     * Assign subjects to a teacher.
     */
    public function assignSubjects(Request $request, $teacherId)
    {
        $teacher = Teacher::findOrFail($teacherId);
        
        $validated = $request->validate([
            'subject_ids' => 'required|array',
            'subject_ids.*' => 'exists:subjects,id',
        ]);

        // sync() will attach the new subject IDs and detach any that are not in the array
        $teacher->subjects()->sync($validated['subject_ids']);

        return response()->json([
            'message' => 'Subjects assigned to teacher successfully',
            'data' => $teacher->load('subjects')
        ]);
    }

    /**
     * Assign a teacher to a classroom as class teacher.
     */
    public function assignToClassroom(Request $request, $teacherId)
    {
        $teacher = Teacher::findOrFail($teacherId);
        
        $validated = $request->validate([
            'classroom_id' => 'required|exists:classes,id',
        ]);

        $classroom = Classroom::findOrFail($validated['classroom_id']);
        
        // Verify teacher belongs to the same school as the classroom
        if ($teacher->school_id !== $classroom->school_id) {
            return response()->json([
                'message' => 'Teacher and classroom must belong to the same school'
            ], 422);
        }

        // Update the classroom with the teacher ID
        $classroom->class_teacher_id = $teacher->id;
        $classroom->save();

        return response()->json([
            'message' => 'Teacher assigned to classroom successfully',
            'data' => [
                'teacher' => $teacher,
                'classroom' => $classroom->load('teacher')
            ]
        ]);
    }

    /**
     * Remove a teacher from a classroom.
     */
    public function removeFromClassroom($classroomId)
    {
        $classroom = Classroom::findOrFail($classroomId);
        
        // Set the teacher reference to null
        $classroom->class_teacher_id = null;
        $classroom->save();

        return response()->json([
            'message' => 'Teacher removed from classroom successfully',
            'data' => $classroom
        ]);
    }

    /**
     * Get all classrooms assigned to a teacher.
     */
    public function getClassrooms($teacherId)
    {
        $teacher = Teacher::with('classrooms')->findOrFail($teacherId);
        
        return response()->json([
            'teacher' => $teacher,
            'classrooms' => $teacher->classrooms
        ]);
    }

    /**
     * Assign a teacher to a stream as class teacher.
     */
    public function assignToStream(Request $request, $teacherId)
    {
        $teacher = Teacher::findOrFail($teacherId);
        
        $validated = $request->validate([
            'stream_id' => 'required|exists:streams,id',
        ]);

        $stream = Stream::findOrFail($validated['stream_id']);
        
        // Verify teacher belongs to the same school as the stream
        if ($teacher->school_id !== $stream->school_id) {
            return response()->json([
                'message' => 'Teacher and stream must belong to the same school'
            ], 422);
        }

        // Update the stream with the teacher ID
        $stream->class_teacher_id = $teacher->id;
        $stream->save();

        return response()->json([
            'message' => 'Teacher assigned to stream successfully',
            'data' => [
                'teacher' => $teacher,
                'stream' => $stream->load('classTeacher')
            ]
        ]);
    }

    /**
     * Get all streams where the teacher is the class teacher.
     */
    public function getStreamsAsClassTeacher($teacherId)
    {
        $teacher = Teacher::findOrFail($teacherId);
        $streams = Stream::where('class_teacher_id', $teacherId)
            ->with(['classroom', 'school'])
            ->get();

        return response()->json([
            'teacher' => $teacher,
            'streams' => $streams
        ]);
    }

    /**
     * Get all streams where the teacher teaches.
     */
    public function getStreamsAsTeacher($teacherId)
    {
        $teacher = Teacher::findOrFail($teacherId);
        $streams = $teacher->streams()
            ->with(['classroom', 'school'])
            ->get();

        return response()->json([
            'teacher' => $teacher,
            'streams' => $streams
        ]);
    }

    /**
     * Get all teachers in a specific school.
     */
    public function getTeachersBySchool($schoolId)
    {
        $school = School::find($schoolId);
        if (!$school) {
            return response()->json(['message' => 'School not found'], 404);
        }

        $teachers = Teacher::where('school_id', $schoolId)
            ->with(['user', 'subjects'])
            ->get();

        return response()->json([
            'school' => $school,
            'teachers' => $teachers
        ]);
    }

    /**
     * Get all class teachers with their streams and classrooms.
     */
    public function getAllClassTeachers()
    {
        $streams = Stream::whereNotNull('class_teacher_id')
            ->with(['classTeacher', 'classroom', 'school'])
            ->get();

        return response()->json($streams);
    }

    /**
     * Get all teachers teaching a specific stream.
     */
    public function getTeachersByStream($streamId)
    {
        $stream = Stream::with(['teachers', 'classroom'])->find($streamId);
        if (!$stream) {
            return response()->json(['message' => 'Stream not found'], 404);
        }

        return response()->json([
            'stream' => $stream,
            'teachers' => $stream->teachers
        ]);
    }
}