<?php

namespace App\Http\Controllers;

use App\Models\ParentModel;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StudentController extends Controller
{
    // List all students with filters and pagination
public function index(Request $request)
{
    $query = Student::with(['user', 'school', 'classroom', 'parent.user']); // include student user info

    // Filter by school_id if provided
    if ($request->has('school_id')) {
        $query->where('school_id', $request->school_id);
    }

    // Filter by class_id if provided
    if ($request->has('class_id')) {
        $query->where('class_id', $request->class_id);
    }

    // Filter by status if provided
    if ($request->has('status')) {
        $query->where('status', $request->status);
    }

    $students = $query->paginate(10);

    return response()->json($students);
}

public function getStudents(Request $request)
{
    $user = Auth::user(); // logged-in user
    $schoolId = $user->school_id;

    // Get students for the logged-in user's school
    $students = Student::with(['user'])
                        ->where('school_id', $schoolId)
                        ->get();

    return response()->json($students);
}


public function getParentsForMySchool()
{
    $user = Auth::user();

    // Get all parents in the logged-in user's school, including their user info
    $parents = ParentModel::with('user')
        ->where('school_id', $user->school_id)
        ->get();

    // Optional: flatten parent info for easier frontend usage
    $parents = $parents->map(function ($parent) {
        return [
            'id' => $parent->id,
            'user_id' => $parent->user_id,
            'relation' => $parent->relation,
            'parent_name' => $parent->user?->full_name ?? null,
            'parent_email' => $parent->user?->email ?? null,
        ];
    });

    return response()->json([
        'school_id' => $user->school_id,
        'parents' => $parents,
    ]);
}




    // Get students for a specific school and classroom
public function getClassStudents(Request $request, $classId)
{
    $user = Auth::user();

    $students = Student::with(['school', 'classroom', 'parent.user'])
        ->where('school_id', $user->school_id) // limit to user’s school
        ->where('class_id', $classId)         // dynamic class ID
        ->paginate(10);

    return response()->json([
        'school_id' => $user->school_id,
        'class_id' => $classId,
        'students' => $students,
    ]);
}


    // Create a new student
public function store(Request $request)
{
    $user = Auth::user(); // logged-in user

    $validated = $request->validate([
        'user_id' => 'nullable|exists:users,id',
        'class_id' => 'required|exists:classes,id',
        'parent_user_id' => 'required|exists:users,id',
        'status' => 'in:active,inactive,graduated',
        'admission_number' => 'required|unique:students',
        'date_of_birth' => 'required|date',
        'gender' => 'required|in:male,female',
        'admission_date' => 'required|date',
        'relation' => 'nullable|string',
    ]);

    // Use logged-in user's school
    $schoolId = $user->school_id;

    // Step 1: Check or create parent record
    $parent = ParentModel::firstOrCreate(
        [
            'user_id' => $validated['parent_user_id'],
            'school_id' => $schoolId,
        ],
        [
            'relation' => $validated['relation'] ?? 'Unknown',
        ]
    );

    // Step 2: Create the student
    $student = Student::create([
        'user_id' => $validated['user_id'] ?? null,
        'school_id' => $schoolId,
        'class_id' => $validated['class_id'],
        'parent_id' => $parent->id,
        'status' => $validated['status'] ?? 'active',
        'admission_number' => $validated['admission_number'],
        'date_of_birth' => $validated['date_of_birth'],
        'gender' => $validated['gender'],
        'admission_date' => $validated['admission_date'],
    ]);

    return response()->json([
        'message' => 'Student created successfully',
        'student' => $student->load('parent.user'),
    ]);
}



    // Show single student
    public function show($id)
    {
        $student = Student::with(['school', 'classroom', 'parent'])->findOrFail($id);
        return response()->json($student);
    }

    // Update student
public function update(Request $request, $id)
{
    $user = Auth::user();
    $student = Student::findOrFail($id);

    $validated = $request->validate([
        'user_id' => 'nullable|exists:users,id',
        'class_id' => 'required|exists:classes,id',
        'parent_user_id' => 'nullable|exists:users,id',
        'relation' => 'nullable|string',
        'status' => 'in:active,inactive,graduated',
        'admission_number' => 'unique:students,admission_number,' . $id,
        'date_of_birth' => 'date',
        'gender' => 'in:male,female',
        'admission_date' => 'date',
    ]);

    $schoolId = $user->school_id; // logged-in user's school

    // Step 1: Update or create parent if parent_user_id is provided
    if (!empty($validated['parent_user_id'])) {
        $parent = ParentModel::updateOrCreate(
            [
                'user_id' => $validated['parent_user_id'],
                'school_id' => $schoolId,
            ],
            [
                'relation' => $validated['relation'] ?? 'Unknown',
            ]
        );

        $validated['parent_id'] = $parent->id;
    }

    // Step 2: Update the student
    $student->update(array_merge($validated, ['school_id' => $schoolId]));

    return response()->json([
        'message' => 'Student updated successfully',
        'student' => $student->load('parent.user'),
    ]);
}



    // Delete student
    public function destroy($id)
    {
        $student = Student::findOrFail($id);
        $student->delete();

        return response()->json(['message' => 'Student deleted successfully']);
    }
}