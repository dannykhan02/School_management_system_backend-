<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AcademicYearController extends Controller
{
    /**
     * Display a listing of academic years.
     * Filter by curriculum type using ?curriculum=CBC or ?curriculum=8-4-4
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $query = AcademicYear::where('school_id', $user->school_id);

        // Filter by curriculum if query parameter is present
        if ($request->has('curriculum') && in_array($request->get('curriculum'), ['CBC', '8-4-4'])) {
            $query->where('curriculum_type', $request->get('curriculum'));
        }

        return $query->with('school')->get();
    }

    /**
     * Store a newly created academic year in storage.
     * Automatically sets the curriculum_type based on the school's primary curriculum.
     */
    public function store(Request $request)
    {
        $user = Auth::user();
        $school = $user->school;

        $data = $request->validate([
            'year' => 'required|integer',
            'term' => 'required|string',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'is_active' => 'sometimes|boolean'
        ]);

        // Set curriculum_type based on school's primary curriculum
        $schoolPrimaryCurriculum = $school->primary_curriculum ?? null;

        if ($schoolPrimaryCurriculum === 'Both') {
            // If school supports both curriculums, user must specify
            $data['curriculum_type'] = $request->validate([
                'curriculum_type' => 'required|in:CBC,8-4-4'
            ]);
        } else {
            // Otherwise, default to school's primary curriculum
            $data['curriculum_type'] = $schoolPrimaryCurriculum;
        }

        $data['school_id'] = $user->school_id;
        $data['is_active'] = $data['is_active'] ?? false;

        $academicYear = AcademicYear::create($data);
        return response()->json($academicYear, 201);
    }

    /**
     * Display the specified academic year.
     */
    public function show(AcademicYear $academicYear)
    {
        $user = Auth::user();

        // Only allow access if the academic year belongs to the user's school
        if ($academicYear->school_id !== $user->school_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return $academicYear->load('school');
    }

    /**
     * Update the specified academic year in storage.
     */
    public function update(Request $request, AcademicYear $academicYear)
    {
        $user = Auth::user();

        // Only allow access if the academic year belongs to the user's school
        if ($academicYear->school_id !== $user->school_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'year' => 'sometimes|required|integer',
            'term' => 'sometimes|required|string',
            'start_date' => 'sometimes|required|date',
            'end_date' => 'sometimes|required|date|after_or_equal:start_date',
            'is_active' => 'sometimes|required|boolean'
        ]);

        // Don't allow changing curriculum_type through update
        unset($data['curriculum_type']);

        $academicYear->update($data);
        return response()->json($academicYear);
    }

    /**
     * Remove the specified academic year from storage.
     */
    public function destroy(AcademicYear $academicYear)
    {
        $user = Auth::user();

        // Only allow access if the academic year belongs to the user's school
        if ($academicYear->school_id !== $user->school_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Check if the academic year is being used by any subjects
        $isUsed = $academicYear->subjects()->exists();
        if ($isUsed) {
            return response()->json([
                'message' => 'Cannot delete academic year. It is being used by subjects.',
                'error' => 'AcademicYearInUse'
            ], 422);
        }

        $academicYear->delete();
        return response()->json(['message' => 'Academic year deleted successfully']);
    }

    /**
     * Set an academic year as active.
     */
    public function setActive(AcademicYear $academicYear)
    {
        $user = Auth::user();

        // Only allow access if the academic year belongs to the user's school
        if ($academicYear->school_id !== $user->school_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Deactivate all other academic years for this school
        AcademicYear::where('school_id', $user->school_id)
            ->where('id', '!=', $academicYear->id)
            ->update(['is_active' => false]);

        // Activate the specified academic year
        $academicYear->update(['is_active' => true]);

        return response()->json([
            'message' => 'Academic year set as active successfully'
        ]);
    }

    /**
     * Get academic years by curriculum type.
     * Useful for filtering academic years in the teacher assignment system.
     */
    public function getByCurriculum(Request $request)
    {
        $user = Auth::user();
        $curriculum = $request->get('curriculum');

        if (!in_array($curriculum, ['CBC', '8-4-4'])) {
            return response()->json([
                'message' => 'Invalid curriculum type',
                'error' => 'InvalidCurriculumType'
            ], 400);
        }

        return AcademicYear::where('school_id', $user->school_id)
            ->where('curriculum_type', $curriculum)
            ->get();
    }
}