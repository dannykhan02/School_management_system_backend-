<?php

namespace App\Http\Controllers;

use App\Models\Subject;
use App\Models\Teacher;
use App\Models\Stream;
use App\Models\Classroom;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class SubjectController extends Controller
{
    /**
     * Display a listing of all subjects for the current school.
     * Includes the school and assigned teachers for each subject.
     */
    public function index()
    {
        $user = Auth::user();
        
        // Only get subjects for the current user's school
        $subjects = Subject::where('school_id', $user->school_id)
                          ->with(['school', 'teachers'])
                          ->get();
        
        return response()->json($subjects);
    }

    /**
     * Store a newly created subject in storage.
     */
    public function store(Request $request)
    {
        $user = Auth::user();
        
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            // Ensure the subject code is unique within the same school
            'code' => [
                'required',
                Rule::unique('subjects')->where(function ($query) use ($user) {
                    return $query->where('school_id', $user->school_id);
                })
            ],
        ]);

        // Always use the authenticated user's school_id
        $validated['school_id'] = $user->school_id;

        $subject = Subject::create($validated);

        return response()->json([
            'message' => 'Subject created successfully',
            'data' => $subject->load(['school', 'teachers'])
        ], 201);
    }

    /**
     * Display the specified subject.
     * Includes the school and assigned teachers.
     */
    public function show($id)
    {
        $user = Auth::user();
        $subject = Subject::with(['school', 'teachers'])->findOrFail($id);
        
        // Check if subject belongs to user's school
        if ($subject->school_id !== $user->school_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
        return response()->json($subject);
    }

    /**
     * Update the specified subject in storage.
     */
    public function update(Request $request, $id)
    {
        $user = Auth::user();
        $subject = Subject::findOrFail($id);
        
        // Check if subject belongs to user's school
        if ($subject->school_id !== $user->school_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            // Ensure the code is unique within the school, but ignore the current subject
            'code' => [
                'sometimes',
                Rule::unique('subjects')->where(function ($query) use ($user) {
                    return $query->where('school_id', $user->school_id);
                })->ignore($id)
            ],
        ]);

        $subject->update($validated);

        return response()->json([
            'message' => 'Subject updated successfully',
            'data' => $subject->load(['school', 'teachers'])
        ]);
    }

    /**
     * Remove the specified subject from storage.
     */
    public function destroy($id)
    {
        $user = Auth::user();
        $subject = Subject::findOrFail($id);
        
        // Check if subject belongs to user's school
        if ($subject->school_id !== $user->school_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $subject->delete();

        return response()->json(['message' => 'Subject deleted successfully']);
    }

    /**
     * Assign a subject to a teacher.
     */
    public function assignToTeacher(Request $request, $subjectId)
    {
        $subject = Subject::findOrFail($subjectId);
        
        $validated = $request->validate([
            'teacher_id' => 'required|exists:teachers,id',
        ]);

        $teacher = Teacher::findOrFail($validated['teacher_id']);
        
        // Verify teacher belongs to the same school as the subject
        if ($teacher->school_id !== $subject->school_id) {
            return response()->json([
                'message' => 'Teacher and subject must belong to the same school'
            ], 422);
        }

        // Attach the subject to the teacher (many-to-many relationship)
        $teacher->subjects()->attach($subject->id);

        return response()->json([
            'message' => 'Subject assigned to teacher successfully',
            'data' => [
                'subject' => $subject,
                'teacher' => $teacher->load('subjects')
            ]
        ]);
    }

    /**
     * Remove a subject from a teacher.
     */
    public function removeFromTeacher($subjectId, $teacherId)
    {
        $subject = Subject::findOrFail($subjectId);
        $teacher = Teacher::findOrFail($teacherId);
        
        // Detach the subject from the teacher
        $teacher->subjects()->detach($subject->id);

        return response()->json([
            'message' => 'Subject removed from teacher successfully',
            'data' => [
                'subject' => $subject,
                'teacher' => $teacher->load('subjects')
            ]
        ]);
    }

    /**
     * Get all teachers teaching a specific subject with their streams and classrooms.
     */
    public function getTeachersBySubject($subjectId)
    {
        $subject = Subject::findOrFail($subjectId);
        
        // Get teachers who teach this subject
        $teachers = Teacher::whereHas('subjects', function($query) use ($subjectId) {
            $query->where('subject_id', $subjectId);
        })->with(['user', 'school', 'streams.classroom', 'classrooms'])->get();

        return response()->json([
            'subject' => $subject,
            'teachers' => $teachers
        ]);
    }

    /**
     * Get all subjects for a specific school.
     */
    public function getSubjectsBySchool($schoolId)
    {
        $subjects = Subject::where('school_id', $schoolId)
            ->with(['teachers'])
            ->get();

        return response()->json([
            'school_id' => $schoolId,
            'subjects' => $subjects
        ]);
    }

    /**
     * Assign multiple teachers to a subject.
     */
    public function assignMultipleTeachers(Request $request, $subjectId)
    {
        $subject = Subject::findOrFail($subjectId);
        
        $validated = $request->validate([
            'teacher_ids' => 'required|array',
            'teacher_ids.*' => 'exists:teachers,id',
        ]);

        // Verify all teachers belong to the same school as the subject
        $teachers = Teacher::whereIn('id', $validated['teacher_ids'])->get();
        foreach ($teachers as $teacher) {
            if ($teacher->school_id !== $subject->school_id) {
                return response()->json([
                    'message' => 'All teachers must belong to the same school as the subject'
                ], 422);
            }
        }

        // Sync the teachers with the subject
        $subject->teachers()->sync($validated['teacher_ids']);

        return response()->json([
            'message' => 'Teachers assigned to subject successfully',
            'data' => $subject->load('teachers')
        ]);
    }

    /**
     * Get all subjects taught by a specific teacher.
     */
    public function getSubjectsByTeacher($teacherId)
    {
        $teacher = Teacher::findOrFail($teacherId);
        $subjects = $teacher->subjects()->with('school')->get();

        return response()->json([
            'teacher' => $teacher,
            'subjects' => $subjects
        ]);
    }
}