<?php

namespace App\Http\Controllers;

use App\Models\Classroom;
use App\Models\Teacher;
use App\Models\School;
use App\Models\Stream;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ClassroomController extends Controller
{
    /**
     * Display a listing of the classrooms.
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        
        $classrooms = Classroom::with(['school', 'teacher', 'students', 'streams'])
            ->where('school_id', $user->school_id)
            ->get();
        
        return response()->json($classrooms);
    }

    /**
     * Store a newly created classroom in storage.
     */
    public function store(Request $request)
    {
        $user = Auth::user();

        // Validate request data
        $validated = $request->validate([
            'class_name' => 'required|string|max:255',
            'class_teacher_id' => 'nullable|integer|exists:teachers,id',
            'capacity' => 'nullable|integer|min:1',
        ]);

        // Optional: check if the teacher belongs to the same school
        if (isset($validated['class_teacher_id'])) {
            $teacher = Teacher::find($validated['class_teacher_id']);
            if ($teacher->school_id !== $user->school_id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'The teacher does not belong to your school.'
                ], 422);
            }
        }

        // Create classroom
        $classroom = Classroom::create([
            'class_name' => $validated['class_name'],
            'class_teacher_id' => $validated['class_teacher_id'] ?? null,
            'capacity' => $validated['capacity'] ?? null,
            'school_id' => $user->school_id,
        ]);

        return response()->json([
            'message' => 'Classroom created successfully',
            'data' => $classroom->load(['school', 'teacher', 'streams'])
        ], 201);
    }

    /**
     * Display the specified classroom.
     */
    public function show($id)
    {
        $user = Auth::user();
        $classroom = Classroom::with(['school', 'teacher', 'students', 'streams'])->findOrFail($id);
        
        // Check if classroom belongs to user's school
        if ($classroom->school_id !== $user->school_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
        return response()->json($classroom);
    }

    /**
     * Update the specified classroom in storage.
     */
    public function update(Request $request, $id)
    {
        $user = Auth::user();
        $classroom = Classroom::findOrFail($id);
        
        // Check if classroom belongs to user's school
        if ($classroom->school_id !== $user->school_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'class_name' => 'sometimes|required|string|max:255',
            'class_teacher_id' => 'nullable|exists:teachers,id',
            'capacity' => 'nullable|integer|min:1',
        ]);

        // If a teacher is assigned, verify they belong to the same school
        if (isset($validated['class_teacher_id']) && !empty($validated['class_teacher_id'])) {
            $teacher = Teacher::find($validated['class_teacher_id']);
            
            if ($teacher && $teacher->school_id !== $classroom->school_id) {
                return response()->json([
                    'message' => 'The assigned teacher must belong to the same school'
                ], 422);
            }
        }

        $classroom->update($validated);

        return response()->json([
            'message' => 'Classroom updated successfully',
            'data' => $classroom->load(['school', 'teacher', 'streams'])
        ]);
    }

    /**
     * Remove the specified classroom from storage.
     */
    public function destroy($id)
    {
        $user = Auth::user();
        $classroom = Classroom::findOrFail($id);
        
        // Check if classroom belongs to user's school
        if ($classroom->school_id !== $user->school_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $classroom->delete();

        return response()->json(['message' => 'Classroom deleted successfully']);
    }

    /**
     * Assign a teacher to a classroom.
     */
    public function assignTeacher(Request $request, $classroomId)
    {
        $classroom = Classroom::findOrFail($classroomId);
        
        $validated = $request->validate([
            'teacher_id' => 'required|exists:teachers,id',
        ]);

        $teacher = Teacher::findOrFail($validated['teacher_id']);
        
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
                'classroom' => $classroom->load('teacher'),
                'teacher' => $teacher
            ]
        ]);
    }

    /**
     * Remove a teacher from a classroom.
     */
    public function removeTeacher($classroomId)
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
     * Get all streams for a specific classroom.
     */
    public function getStreams($classroomId)
    {
        $classroom = Classroom::findOrFail($classroomId);
        $streams = Stream::where('class_id', $classroomId)
            ->with(['school', 'classTeacher', 'teachers'])
            ->get();

        return response()->json([
            'classroom' => $classroom,
            'streams' => $streams
        ]);
    }
}