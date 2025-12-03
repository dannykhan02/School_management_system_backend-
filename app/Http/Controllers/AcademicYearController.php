<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AcademicYearController extends Controller
{
    /**
     * Display all academic years for the logged-in user's school.
     */
    public function index()
    {
        $user = Auth::user();

        return AcademicYear::where('school_id', $user->school_id)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Create a new academic year.
     */
    public function store(Request $request)
    {
        $user = Auth::user();

        // Get the school to determine curriculum type
        $school = School::find($user->school_id);
        
        if (!$school) {
            return response()->json([
                'message' => 'School not found'
            ], 404);
        }

        // Define validation rules
        $validationRules = [
            'year' => 'required|string|max:255',
            'term' => 'required|string|max:255',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'is_active' => 'sometimes|boolean'
        ];

        // If school has "Both" curriculum, require curriculum_type selection
        // Otherwise, don't require it (will be auto-set)
        if ($school->primary_curriculum === 'Both') {
            $validationRules['curriculum_type'] = 'required|in:CBC,8-4-4';
        } else {
            $validationRules['curriculum_type'] = 'sometimes|in:CBC,8-4-4';
        }

        $validated = $request->validate($validationRules);

        // Auto-set curriculum_type for single curriculum schools
        if ($school->primary_curriculum !== 'Both') {
            $validated['curriculum_type'] = $school->primary_curriculum;
        }

        $validated['school_id'] = $user->school_id;

        return AcademicYear::create($validated);
    }

    /**
     * Get academic years by curriculum.
     */
    public function getByCurriculum($curriculum)
    {
        $user = Auth::user();

        // Normalize underscores -> dashes (Laravel converts URL "-" to "_")
        $curriculum = str_replace('_', '-', $curriculum);

        if (!in_array($curriculum, ['CBC', '8-4-4'])) {
            return response()->json([
                'message' => 'Invalid curriculum type',
                'error' => 'InvalidCurriculumType',
            ], 400);
        }

        return AcademicYear::where('school_id', $user->school_id)
            ->where('curriculum_type', $curriculum)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Show a single academic year.
     */
    public function show($id)
    {
        $user = Auth::user();

        return AcademicYear::where('school_id', $user->school_id)
            ->where('id', $id)
            ->firstOrFail();
    }

    /**
     * Update an academic year.
     */
    public function update(Request $request, $id)
    {
        $user = Auth::user();

        $academicYear = AcademicYear::where('school_id', $user->school_id)
            ->where('id', $id)
            ->firstOrFail();

        // Get the school to determine curriculum type rules
        $school = School::find($user->school_id);
        
        if (!$school) {
            return response()->json([
                'message' => 'School not found'
            ], 404);
        }

        // Define validation rules
        $validationRules = [
            'year' => 'sometimes|string|max:255',
            'term' => 'sometimes|string|max:255',
            'start_date' => 'sometimes|date|nullable',
            'end_date' => 'sometimes|date|nullable',
            'is_active' => 'sometimes|boolean'
        ];

        // For "Both" curriculum schools, allow curriculum_type updates
        // For single curriculum schools, prevent curriculum_type changes
        if ($school->primary_curriculum === 'Both') {
            $validationRules['curriculum_type'] = 'sometimes|in:CBC,8-4-4';
        } else {
            // For single curriculum schools, don't allow curriculum_type to be changed
            // It should always match the school's primary curriculum
            if ($request->has('curriculum_type') && $request->curriculum_type !== $school->primary_curriculum) {
                return response()->json([
                    'message' => 'Curriculum type cannot be changed for this school',
                    'error' => 'InvalidCurriculumChange',
                ], 422);
            }
            $validationRules['curriculum_type'] = 'sometimes|in:CBC,8-4-4';
        }

        $validated = $request->validate($validationRules);

        // For single curriculum schools, ensure curriculum_type matches school's primary curriculum
        if ($school->primary_curriculum !== 'Both') {
            $validated['curriculum_type'] = $school->primary_curriculum;
        }

        $academicYear->update($validated);

        return $academicYear;
    }

    /**
     * Delete an academic year.
     */
    public function destroy($id)
    {
        $user = Auth::user();

        $academicYear = AcademicYear::where('school_id', $user->school_id)
            ->where('id', $id)
            ->firstOrFail();

        $academicYear->delete();

        return response()->json(['message' => 'Academic year deleted successfully']);
    }
}