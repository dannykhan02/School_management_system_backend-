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

        // Filter by curriculum if the query parameter is present
        if ($request->has('curriculum') && in_array($request->get('curriculum'), ['CBC', '8-4-4'])) {
            $query->where('curriculum_type', $request->get('curriculum'));
        }

        return $query->with('school')->get();
    }

    /**
     * Store a newly created academic year in storage.
     * Automatically sets the curriculum_type based on the school's primary_curriculum.
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

        // Set the school_id automatically to the user's school
        $data['school_id'] = $user->school_id;
        $data['is_active'] = $data['is_active'] ?? false; // Default to false

        // Intelligently set the curriculum_type based on the school's primary_curriculum
        $primaryCurriculum = $school->primary_curriculum;

        if ($primaryCurriculum === 'Both') {
            // If the school uses both, the user MUST specify the curriculum for this year
            $request->validate(['curriculum_type' => 'required|in:CBC,8-4-4']);
            $data['curriculum_type'] = $request->input('curriculum_type');
        } else {
            // Otherwise, default to the school's primary curriculum
            $data['curriculum_type'] = $primaryCurriculum;
        }

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
        if($academicYear->school_id !== $user->school_id){
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

        if($academicYear->school_id !== $user->school_id){
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'year'=>'sometimes|required|integer',
            'term'=>'sometimes|required|string',
            'start_date'=>'sometimes|required|date',
            'end_date'=>'sometimes|required|date|after_or_equal:start_date',
            'curriculum_type' => 'sometimes|required|in:CBC,8-4-4',
            'is_active' => 'sometimes|required|boolean'
        ]);

        $academicYear->update($data);
        return response()->json($academicYear);
    }

    /**
     * Remove the specified academic year from storage.
     */
    public function destroy(AcademicYear $academicYear)
    {
        $user = Auth::user();

        if($academicYear->school_id !== $user->school_id){
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $academicYear->delete();
        return response()->json(['message'=>'Academic year deleted']);
    }
}