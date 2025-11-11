<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AcademicYearController extends Controller
{
    public function index(){
        // Get the authenticated user
        $user = Auth::user();

        // Return only the academic years for the user's school
        return AcademicYear::with('school')
            ->where('school_id', $user->school_id)
            ->get();
    }

    public function store(Request $request){
        $user = Auth::user();

        $data = $request->validate([
            // Ensure the school_id is the same as the user's school
            'term' => 'required|string',
            'start_date' => 'required|date',
            'end_date' => 'required|date'
        ]);

        // Set the school_id automatically to the user's school
        $data['school_id'] = $user->school_id;

        $academicYear = AcademicYear::create($data);
        return response()->json($academicYear, 201);
    }

    public function show(AcademicYear $academicYear){
        $user = Auth::user();

        // Only allow access if the academic year belongs to the user's school
        if($academicYear->school_id !== $user->school_id){
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return $academicYear->load('school');
    }

    public function update(Request $request, AcademicYear $academicYear){
        $user = Auth::user();

        if($academicYear->school_id !== $user->school_id){
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'term'=>'sometimes|required|string',
            'start_date'=>'sometimes|required|date',
            'end_date'=>'sometimes|required|date'
        ]);

        $academicYear->update($data);
        return response()->json($academicYear);
    }

    public function destroy(AcademicYear $academicYear){
        $user = Auth::user();

        if($academicYear->school_id !== $user->school_id){
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $academicYear->delete();
        return response()->json(['message'=>'Academic year deleted']);
    }
}
