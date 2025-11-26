<?php

namespace App\Http\Controllers;

use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class SubjectController extends Controller
{
    /**
     * Display a listing of subjects for the current school.
     * Can be filtered by curriculum type using a query parameter, e.g., ?curriculum=CBC
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        
        // Start with a query for subjects belonging to the user's school
        $query = Subject::where('school_id', $user->school_id);

        // Filter by curriculum if the query parameter is present (e.g., ?curriculum=CBC)
        if ($request->has('curriculum') && in_array($request->get('curriculum'), ['CBC', '8-4-4'])) {
            $query->where('curriculum_type', $request->get('curriculum'));
        }

        $subjects = $query->with(['school'])->get();
        
        return response()->json($subjects);
    }

    /**
     * Store a newly created subject in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $user = Auth::user();
        
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => [
                'required',
                'string',
                Rule::unique('subjects')->where(function ($query) use ($user) {
                    return $query->where('school_id', $user->school_id);
                })
            ],
            'curriculum_type' => 'required|in:CBC,8-4-4',
            'grade_level' => 'required|string|max:255',
            'category' => 'nullable|string|max:255',
            'is_core' => 'sometimes|boolean',
        ]);

        // Always use the authenticated user's school_id
        $validated['school_id'] = $user->school_id;
        // Default 'is_core' to false if not provided
        $validated['is_core'] = $validated['is_core'] ?? false;

        $subject = Subject::create($validated);

        return response()->json([
            'message' => 'Subject created successfully',
            'data' => $subject->load(['school'])
        ], 201);
    }

    /**
     * Display the specified subject.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $user = Auth::user();
        $subject = Subject::with(['school'])->findOrFail($id);
        
        // Check if the subject belongs to the user's school
        if ($subject->school_id !== $user->school_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
        return response()->json($subject);
    }

    /**
     * Update the specified subject in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $user = Auth::user();
        $subject = Subject::findOrFail($id);
        
        // Check if the subject belongs to the user's school
        if ($subject->school_id !== $user->school_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'code' => [
                'sometimes',
                'string',
                Rule::unique('subjects')->where(function ($query) use ($user) {
                    return $query->where('school_id', $user->school_id);
                })->ignore($id)
            ],
            'curriculum_type' => 'sometimes|required|in:CBC,8-4-4',
            'grade_level' => 'sometimes|required|string|max:255',
            'category' => 'nullable|string|max:255',
            'is_core' => 'sometimes|boolean',
        ]);

        $subject->update($validated);

        return response()->json([
            'message' => 'Subject updated successfully',
            'data' => $subject->load(['school'])
        ]);
    }

    /**
     * Remove the specified subject from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $user = Auth::user();
        $subject = Subject::findOrFail($id);
        
        // Check if the subject belongs to the user's school
        if ($subject->school_id !== $user->school_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $subject->delete();

        return response()->json(['message' => 'Subject deleted successfully']);
    }
}