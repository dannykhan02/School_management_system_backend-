<?php

namespace App\Http\Controllers;

use App\Models\ParentModel;
use App\Models\User;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ParentController extends BaseController
{
    /**
     * Display a listing of parents.
     */
    public function index(Request $request)
    {
        $user = $this->getUser($request);
        
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        $parents = ParentModel::with(['user', 'school', 'students'])
            ->where('school_id', $user->school_id)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $parents
        ]);
    }

    /**
     * Store a newly created parent in storage.
     */
    public function store(Request $request)
    {
        $user = $this->getUser($request);
        
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'relation' => 'required|string',
            'student_ids' => 'nullable|array',
            'student_ids.*' => 'exists:students,id',
        ]);

        // Verify user belongs to same school
        $parentUser = User::find($validated['user_id']);
        if ($parentUser->school_id !== $user->school_id) {
            return response()->json([
                'status' => 'error',
                'message' => 'The user must belong to the same school.'
            ], 422);
        }

        // Verify students belong to same school
        if (isset($validated['student_ids'])) {
            $students = Student::whereIn('id', $validated['student_ids'])->get();
            foreach ($students as $student) {
                if ($student->school_id !== $user->school_id) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'All students must belong to the same school.'
                    ], 422);
                }
            }
        }

        $parent = ParentModel::create([
            'user_id' => $validated['user_id'],
            'school_id' => $user->school_id,
            'relation' => $validated['relation'],
        ]);

        // Associate with students if provided
        if (isset($validated['student_ids'])) {
            $parent->students()->attach($validated['student_ids']);
        }

        // Set default password for the parent user
        $this->setDefaultPassword($parentUser);

        return response()->json([
            'status' => 'success',
            'message' => 'Parent created successfully.',
            'data' => $parent->load(['user', 'school', 'students'])
        ], 201);
    }

    /**
     * Display the specified parent.
     */
    public function show(Request $request, $id)
    {
        $user = $this->getUser($request);
        
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        try {
            $parent = ParentModel::with(['user', 'school', 'students'])->findOrFail($id);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'No parent found with the specified ID.'
            ], 404);
        }

        $authError = $this->checkAuthorization($user, $parent);
        if ($authError) {
            return $authError;
        }

        return response()->json([
            'status' => 'success',
            'data' => $parent
        ]);
    }

    /**
     * Update the specified parent in storage.
     */
    public function update(Request $request, $id)
    {
        $user = $this->getUser($request);
        
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        try {
            $parent = ParentModel::findOrFail($id);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'No parent found with the specified ID.'
            ], 404);
        }

        $authError = $this->checkAuthorization($user, $parent);
        if ($authError) {
            return $authError;
        }

        $validated = $request->validate([
            'relation' => 'nullable|string',
            'student_ids' => 'nullable|array',
            'student_ids.*' => 'exists:students,id',
        ]);

        // Verify students belong to same school if provided
        if (isset($validated['student_ids'])) {
            $students = Student::whereIn('id', $validated['student_ids'])->get();
            foreach ($students as $student) {
                if ($student->school_id !== $user->school_id) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'All students must belong to the same school.'
                    ], 422);
                }
            }
        }

        $parent->update($validated);

        // Update student associations if provided
        if (isset($validated['student_ids'])) {
            $parent->students()->sync($validated['student_ids']);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Parent updated successfully.',
            'data' => $parent->load(['user', 'school', 'students'])
        ]);
    }

    /**
     * Remove the specified parent from storage.
     */
    public function destroy(Request $request, $id)
    {
        $user = $this->getUser($request);
        
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        try {
            $parent = ParentModel::findOrFail($id);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'No parent found with the specified ID.'
            ], 404);
        }

        $authError = $this->checkAuthorization($user, $parent);
        if ($authError) {
            return $authError;
        }

        $parent->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Parent deleted successfully.'
        ]);
    }

    /**
     * Get parents by school ID.
     */
    public function getParentsBySchool(Request $request, $schoolId)
    {
        $user = $this->getUser($request);
        
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        if ($user->school_id != $schoolId) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $parents = ParentModel::with(['user', 'school', 'students'])
            ->where('school_id', $schoolId)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $parents
        ]);
    }

    /**
     * Get a parent's students.
     */
    public function getStudents($parentId)
    {
        $user = Auth::user();
        $parent = ParentModel::findOrFail($parentId);
        
        $authError = $this->checkAuthorization($user, $parent);
        if ($authError) {
            return $authError;
        }
        
        $students = $parent->students()->with(['classroom', 'stream'])->get();
        
        return response()->json([
            'parent' => $parent,
            'students' => $students
        ]);
    }
}