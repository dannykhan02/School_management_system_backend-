<?php

namespace App\Http\Controllers;

use App\Models\Classroom;
use App\Models\Teacher;
use App\Models\School;
use App\Models\Stream;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ClassroomController extends Controller
{
    /**
     * Get the current user (mock if not logged in).
     */
    private function getUser(Request $request)
    {
        // If Sanctum or session user is available
        $user = Auth::user();

        // For testing in Postman (no Sanctum) - ONLY if no authenticated user exists
        if (!$user && $request->has('school_id')) {
            $user = User::where('school_id', $request->school_id)->first();
        }

        return $user;
    }

    /**
     * Display a listing of classrooms.
     */
    public function index(Request $request)
    {
        $user = $this->getUser($request);
        
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        if (!$user->school_id) {
            return response()->json(['message' => 'User is not associated with any school.'], 400);
        }

        $classrooms = Classroom::with(['school', 'teacher.user', 'students', 'streams'])
            ->where('school_id', $user->school_id)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $classrooms
        ]);
    }

    /**
     * Store a newly created classroom.
     */
    public function store(Request $request)
    {
        $user = $this->getUser($request);
        
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        $validated = $request->validate([
            'class_name' => 'required|string|max:255',
            'class_teacher_id' => 'nullable|integer|exists:teachers,id',
            'capacity' => 'nullable|integer|min:1',
            'school_id' => 'required|integer|exists:schools,id'
        ]);

        // Verify the school_id matches the user's school
        if ($validated['school_id'] != $user->school_id) {
            return response()->json([
                'status' => 'error',
                'message' => 'You can only create classrooms for your own school.'
            ], 403);
        }

        if (isset($validated['class_teacher_id'])) {
            $teacher = Teacher::find($validated['class_teacher_id']);
            
            if (!$teacher) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'The specified teacher does not exist.'
                ], 404);
            }
            
            if ($teacher->school_id !== $user->school_id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'The selected teacher does not belong to your school.'
                ], 422);
            }
        }

        $classroom = Classroom::create([
            'class_name' => $validated['class_name'],
            'class_teacher_id' => $validated['class_teacher_id'] ?? null,
            'capacity' => $validated['capacity'] ?? null,
            'school_id' => $validated['school_id'],
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Classroom created successfully.',
            'data' => $classroom->load(['school', 'teacher.user', 'streams'])
        ], 201);
    }

    /**
     * Display the specified classroom.
     */
    public function show(Request $request, $id)
    {
        $user = $this->getUser($request);
        
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        try {
            $classroom = Classroom::with(['school', 'teacher.user', 'students', 'streams'])->findOrFail($id);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'No classroom found with the specified ID.'
            ], 404);
        }

        if ($classroom->school_id !== $user->school_id) {
            return response()->json(['message' => 'Unauthorized access to this classroom.'], 403);
        }

        return response()->json([
            'status' => 'success',
            'data' => $classroom
        ]);
    }

    /**
     * Update the specified classroom.
     */
    public function update(Request $request, $id)
    {
        $user = $this->getUser($request);
        
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        try {
            $classroom = Classroom::findOrFail($id);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'No classroom found with the specified ID.'
            ], 404);
        }

        if ($classroom->school_id !== $user->school_id) {
            return response()->json(['message' => 'Unauthorized. This classroom does not belong to your school.'], 403);
        }

        $validated = $request->validate([
            'class_name' => 'sometimes|required|string|max:255',
            'class_teacher_id' => 'nullable|exists:teachers,id',
            'capacity' => 'nullable|integer|min:1',
            'school_id' => 'sometimes|required|integer|exists:schools,id'
        ]);

        // Verify the school_id (if provided) matches
        if (isset($validated['school_id']) && $validated['school_id'] != $user->school_id) {
            return response()->json([
                'status' => 'error',
                'message' => 'You cannot change the school of a classroom.'
            ], 403);
        }

        if (isset($validated['class_teacher_id']) && !empty($validated['class_teacher_id'])) {
            $teacher = Teacher::find($validated['class_teacher_id']);
            
            if (!$teacher) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'The specified teacher does not exist.'
                ], 404);
            }
            
            if ($teacher->school_id !== $classroom->school_id) {
                return response()->json([
                    'message' => 'The assigned teacher must belong to the same school.'
                ], 422);
            }
        }

        $classroom->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Classroom updated successfully.',
            'data' => $classroom->load(['school', 'teacher.user', 'streams'])
        ]);
    }

    /**
     * Remove the specified classroom.
     */
    public function destroy(Request $request, $id)
    {
        $user = $this->getUser($request);
        
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        try {
            $classroom = Classroom::findOrFail($id);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'No classroom found with the specified ID.'
            ], 404);
        }

        if ($classroom->school_id !== $user->school_id) {
            return response()->json(['message' => 'Unauthorized. This classroom does not belong to your school.'], 403);
        }

        $classroom->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Classroom deleted successfully.'
        ]);
    }

    /**
     * Assign a teacher to a classroom.
     */
    public function assignTeacher(Request $request, $classroomId)
    {
        $user = $this->getUser($request);
        
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        try {
            $classroom = Classroom::findOrFail($classroomId);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'No classroom found with the specified ID.'
            ], 404);
        }

        if ($classroom->school_id !== $user->school_id) {
            return response()->json(['message' => 'Unauthorized. You cannot modify a classroom from another school.'], 403);
        }

        $validated = $request->validate([
            'teacher_id' => 'required|integer',
        ]);

        // Check if teacher exists first
        $teacher = Teacher::find($validated['teacher_id']);
        
        if (!$teacher) {
            return response()->json([
                'status' => 'error',
                'message' => 'No teacher found with the specified ID.'
            ], 404);
        }

        if ($teacher->school_id !== $classroom->school_id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Teacher and classroom must belong to the same school.'
            ], 422);
        }

        $classroom->class_teacher_id = $teacher->id;
        $classroom->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Teacher assigned successfully.',
            'data' => [
                'classroom' => $classroom->load('teacher.user'),
                'teacher' => $teacher->load('user')
            ]
        ]);
    }

    /**
     * Remove a teacher from a classroom.
     */
    public function removeTeacher(Request $request, $classroomId)
    {
        $user = $this->getUser($request);
        
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        try {
            $classroom = Classroom::findOrFail($classroomId);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'No classroom found with the specified ID.'
            ], 404);
        }

        if ($classroom->school_id !== $user->school_id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $classroom->class_teacher_id = null;
        $classroom->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Teacher removed successfully.',
            'data' => $classroom
        ]);
    }

    /**
     * Get all streams for a specific classroom.
     */
    public function getStreams(Request $request, $classroomId)
    {
        $user = $this->getUser($request);
        
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        try {
            $classroom = Classroom::findOrFail($classroomId);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'No classroom found with the specified ID.'
            ], 404);
        }

        if ($classroom->school_id !== $user->school_id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $streams = Stream::where('class_id', $classroomId)
            ->with(['school', 'classTeacher.user', 'teachers'])
            ->get();

        return response()->json([
            'status' => 'success',
            'classroom' => $classroom,
            'streams' => $streams
        ]);
    }
}