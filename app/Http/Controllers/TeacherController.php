<?php

namespace App\Http\Controllers;

use App\Models\Teacher;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class TeacherController extends Controller
{
    /**
     * Get the current user (supports Sanctum or manual testing).
     */
    private function getUser(Request $request)
    {
        $user = Auth::user();

        // Allow fallback for Postman testing without login
        if (!$user && $request->has('school_id')) {
            $user = User::where('school_id', $request->school_id)->first();
        }

        return $user;
    }

    /**
     * Display a listing of teachers.
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

        // Remove the 'classrooms' eager loading that was causing the error
        $teachers = Teacher::with(['user', 'school', 'classTeacherStreams', 'teachingStreams'])
            ->where('school_id', $user->school_id)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $teachers
        ]);
    }

    /**
     * Store a newly created teacher.
     */
    public function store(Request $request)
    {
        $user = $this->getUser($request);
        
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'qualification' => 'nullable|string',
            'employment_type' => 'nullable|string',
            'tsc_number' => 'nullable|string',
        ]);

        // Verify user belongs to same school
        $teacherUser = User::find($validated['user_id']);
        if ($teacherUser->school_id !== $user->school_id) {
            return response()->json([
                'status' => 'error',
                'message' => 'The user must belong to the same school.'
            ], 422);
        }

        $teacher = Teacher::create([
            'user_id' => $validated['user_id'],
            'school_id' => $user->school_id,
            'qualification' => $validated['qualification'] ?? null,
            'employment_type' => $validated['employment_type'] ?? null,
            'tsc_number' => $validated['tsc_number'] ?? null,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Teacher created successfully.',
            'data' => $teacher->load(['user', 'school'])
        ], 201);
    }

    /**
     * Display the specified teacher.
     */
    public function show(Request $request, $id)
    {
        $user = $this->getUser($request);
        
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        try {
            // Remove the 'classrooms' eager loading that was causing the error
            $teacher = Teacher::with(['user', 'school', 'classTeacherStreams', 'teachingStreams'])->findOrFail($id);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'No teacher found with the specified ID.'
            ], 404);
        }

        if ($teacher->school_id !== $user->school_id) {
            return response()->json(['message' => 'Unauthorized access to this teacher.'], 403);
        }

        return response()->json([
            'status' => 'success',
            'data' => $teacher
        ]);
    }

    /**
     * Update the specified teacher.
     */
    public function update(Request $request, $id)
    {
        $user = $this->getUser($request);
        
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        try {
            $teacher = Teacher::findOrFail($id);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'No teacher found with the specified ID.'
            ], 404);
        }

        if ($teacher->school_id !== $user->school_id) {
            return response()->json(['message' => 'Unauthorized. This teacher does not belong to your school.'], 403);
        }

        $validated = $request->validate([
            'qualification' => 'nullable|string',
            'employment_type' => 'nullable|string',
            'tsc_number' => 'nullable|string',
        ]);

        $teacher->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Teacher updated successfully.',
            'data' => $teacher->load(['user', 'school'])
        ]);
    }

    /**
     * Remove the specified teacher.
     */
    public function destroy(Request $request, $id)
    {
        $user = $this->getUser($request);
        
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        try {
            $teacher = Teacher::findOrFail($id);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'No teacher found with the specified ID.'
            ], 404);
        }

        if ($teacher->school_id !== $user->school_id) {
            return response()->json(['message' => 'Unauthorized. This teacher does not belong to your school.'], 403);
        }

        $teacher->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Teacher deleted successfully.'
        ]);
    }

    /**
     * Get teachers by school ID.
     */
    public function getTeachersBySchool(Request $request, $schoolId)
    {
        $user = $this->getUser($request);
        
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        if ($user->school_id != $schoolId) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        // Remove the 'classrooms' eager loading that was causing the error
        $teachers = Teacher::with(['user', 'school', 'classTeacherStreams', 'teachingStreams'])
            ->where('school_id', $schoolId)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $teachers
        ]);
    }
}