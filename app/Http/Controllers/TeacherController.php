<?php

namespace App\Http\Controllers;

use App\Models\Teacher;
use App\Models\SubjectAssignment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class TeacherController extends BaseController
{
    /**
     * Display a listing of teachers.
     * Can be filtered by curriculum specialization using ?curriculum=CBC or ?curriculum=8-4-4
     */
    public function index(Request $request)
    {
        $user = $this->getUser($request);
        
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        $query = Teacher::with(['user', 'school', 'classTeacherStreams', 'teachingStreams'])
            ->where('school_id', $user->school_id);

        // Filter by curriculum specialization if provided
        if ($request->has('curriculum')) {
            if ($request->curriculum === 'CBC') {
                $query->where(function($q) {
                    $q->where('curriculum_specialization', 'CBC')
                      ->orWhere('curriculum_specialization', 'Both');
                });
            } elseif ($request->curriculum === '8-4-4') {
                $query->where(function($q) {
                    $q->where('curriculum_specialization', '8-4-4')
                      ->orWhere('curriculum_specialization', 'Both');
                });
            }
        }
        
        $teachers = $query->get();

        return response()->json([
            'status' => 'success',
            'data' => $teachers
        ]);
    }

    /**
     * Store a newly created teacher in storage.
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
            'specialization' => 'nullable|string',
            'curriculum_specialization' => 'required|in:CBC,8-4-4,Both',
            'max_subjects' => 'nullable|integer|min:1',
            'max_classes' => 'nullable|integer|min:1',
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
            'specialization' => $validated['specialization'] ?? null,
            'curriculum_specialization' => $validated['curriculum_specialization'],
            'max_subjects' => $validated['max_subjects'] ?? null,
            'max_classes' => $validated['max_classes'] ?? null,
        ]);

        // Set default password for the teacher user
        $this->setDefaultPassword($teacherUser);

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
            $teacher = Teacher::with(['user', 'school', 'classTeacherStreams', 'teachingStreams'])->findOrFail($id);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'No teacher found with the specified ID.'
            ], 404);
        }

        $authError = $this->checkAuthorization($user, $teacher);
        if ($authError) {
            return $authError;
        }

        return response()->json([
            'status' => 'success',
            'data' => $teacher
        ]);
    }

    /**
     * Update the specified teacher in storage.
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

        $authError = $this->checkAuthorization($user, $teacher);
        if ($authError) {
            return $authError;
        }

        $validated = $request->validate([
            'qualification' => 'nullable|string',
            'employment_type' => 'nullable|string',
            'tsc_number' => 'nullable|string',
            'specialization' => 'nullable|string',
            'curriculum_specialization' => 'nullable|in:CBC,8-4-4,Both',
            'max_subjects' => 'nullable|integer|min:1',
            'max_classes' => 'nullable|integer|min:1',
        ]);

        $teacher->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Teacher updated successfully.',
            'data' => $teacher->load(['user', 'school'])
        ]);
    }

    /**
     * Remove the specified teacher from storage.
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

        $authError = $this->checkAuthorization($user, $teacher);
        if ($authError) {
            return $authError;
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

        $teachers = Teacher::with(['user', 'school', 'classTeacherStreams', 'teachingStreams'])
            ->where('school_id', $schoolId)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $teachers
        ]);
    }

    /**
     * Get a teacher's subject assignments (workload).
     */
    public function getAssignments($teacherId)
    {
        $user = Auth::user();
        $teacher = Teacher::findOrFail($teacherId);
        
        $authError = $this->checkAuthorization($user, $teacher);
        if ($authError) {
            return $authError;
        }
        
        $assignments = SubjectAssignment::where('teacher_id', $teacherId)
                                       ->with(['subject', 'academicYear', 'stream.classroom'])
                                       ->get();
        
        return response()->json([
            'teacher' => $teacher,
            'assignments' => $assignments
        ]);
    }
}