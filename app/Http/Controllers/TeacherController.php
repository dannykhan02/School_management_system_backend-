<?php

namespace App\Http\Controllers;

use App\Models\Teacher;
use App\Models\SubjectAssignment;
use App\Models\User;
use App\Models\School;
use App\Models\Classroom;
use App\Models\Stream;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class TeacherController extends Controller
{
    /**
     * Get authenticated user or fallback user from request.
     */
    private function getUser(Request $request)
    {
        $user = Auth::user();
        if (!$user && $request->has('school_id')) {
            $user = User::where('school_id', $request->school_id)->first();
        }
        return $user;
    }

    /**
     * Check if authenticated user is authorized to access teacher.
     */
    private function checkAuthorization($user, $teacher)
    {
        if ($teacher->school_id !== $user->school_id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. This teacher does not belong to your school.'
            ], 403);
        }
        return null;
    }

    /**
     * Set a default password for a teacher user.
     */
    private function setDefaultPassword($user)
    {
        // Set default password (e.g., "password" or generate based on your logic)
        $defaultPassword = 'teacher123'; // Change this to your preferred default
        $user->password = Hash::make($defaultPassword);
        $user->save();
    }

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

        // Get school information to check if streams are enabled
        $school = School::find($user->school_id);
        $hasStreams = $school ? $school->has_streams : false;

        $query = Teacher::with(['user', 'school']);

        // Load relationships based on school configuration
        if ($hasStreams) {
            $query->with(['classTeacherStreams', 'teachingStreams']);
        } else {
            $query->with(['classrooms' => function($query) {
                $query->withPivot('is_class_teacher');
            }]);
        }

        $query->where('school_id', $user->school_id);

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
            'has_streams' => $hasStreams,
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

        // Get school information to check if streams are enabled
        $school = School::find($user->school_id);
        $hasStreams = $school ? $school->has_streams : false;

        // Adjust validation based on school configuration
        $validationRules = [
            'user_id' => 'required|exists:users,id',
            'qualification' => 'nullable|string',
            'employment_type' => 'nullable|string',
            'tsc_number' => 'nullable|string',
            'specialization' => 'nullable|string',
            'max_subjects' => 'nullable|integer|min:1',
            'max_classes' => 'nullable|integer|min:1',
        ];

        // Only require curriculum_specialization if school has both curriculums
        if ($school && $school->primary_curriculum === 'Both') {
            $validationRules['curriculum_specialization'] = 'required|in:CBC,8-4-4,Both';
        } else {
            $validationRules['curriculum_specialization'] = 'nullable|in:CBC,8-4-4,Both';
        }

        $validated = $request->validate($validationRules);

        // Set curriculum based on school's primary curriculum if not specified
        if (empty($validated['curriculum_specialization']) && $school && $school->primary_curriculum !== 'Both') {
            $validated['curriculum_specialization'] = $school->primary_curriculum;
        }

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

        // Set default password for teacher user
        $this->setDefaultPassword($teacherUser);

        return response()->json([
            'status' => 'success',
            'message' => 'Teacher created successfully.',
            'has_streams' => $hasStreams,
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

        // Get school information to check if streams are enabled
        $school = School::find($user->school_id);
        $hasStreams = $school ? $school->has_streams : false;

        try {
            $query = Teacher::with(['user', 'school']);
            
            // Load relationships based on school configuration
            if ($hasStreams) {
                $query->with(['classTeacherStreams', 'teachingStreams']);
            } else {
                $query->with(['classrooms' => function($query) {
                    $query->withPivot('is_class_teacher');
                }]);
            }
            
            $teacher = $query->findOrFail($id);
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
            'has_streams' => $hasStreams,
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

        // Get school information to check if streams are enabled
        $school = School::find($user->school_id);
        $hasStreams = $school ? $school->has_streams : false;

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

        // Adjust validation based on school configuration
        $validationRules = [
            'qualification' => 'nullable|string',
            'employment_type' => 'nullable|string',
            'tsc_number' => 'nullable|string',
            'specialization' => 'nullable|string',
            'max_subjects' => 'nullable|integer|min:1',
            'max_classes' => 'nullable|integer|min:1',
        ];

        // Only allow curriculum_specialization if school has both curriculums
        if ($school && $school->primary_curriculum === 'Both') {
            $validationRules['curriculum_specialization'] = 'nullable|in:CBC,8-4-4,Both';
        }

        $validated = $request->validate($validationRules);

        $teacher->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Teacher updated successfully.',
            'has_streams' => $hasStreams,
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

        // Get school information to check if streams are enabled
        $school = School::find($schoolId);
        $hasStreams = $school ? $school->has_streams : false;

        $query = Teacher::with(['user', 'school']);
        
        // Load relationships based on school configuration
        if ($hasStreams) {
            $query->with(['classTeacherStreams', 'teachingStreams']);
        } else {
            $query->with(['classrooms' => function($query) {
                $query->withPivot('is_class_teacher');
            }]);
        }
        
        $teachers = $query->where('school_id', $schoolId)->get();

        return response()->json([
            'status' => 'success',
            'has_streams' => $hasStreams,
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

    /**
     * Get classrooms for a teacher (for schools without streams).
     */
    public function getClassrooms(Request $request, $teacherId)
    {
        $user = $this->getUser($request);
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        try {
            $teacher = Teacher::findOrFail($teacherId);
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

        // Check if school has streams enabled
        $school = School::find($teacher->school_id);
        if ($school && $school->has_streams) {
            return response()->json([
                'message' => 'Your school has streams enabled. Teachers are assigned to streams, not classrooms.'
            ], 403);
        }

        $classrooms = $teacher->classrooms()->withPivot('is_class_teacher')->get();

        return response()->json([
            'status' => 'success',
            'data' => $classrooms
        ]);
    }

    /**
     * Assign a teacher to a classroom (for schools without streams).
     */
    public function assignToClassroom(Request $request, $teacherId)
    {
        $user = $this->getUser($request);
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        try {
            $teacher = Teacher::findOrFail($teacherId);
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

        // Check if school has streams enabled
        $school = School::find($teacher->school_id);
        if ($school && $school->has_streams) {
            return response()->json([
                'message' => 'Your school has streams enabled. Teachers should be assigned to streams, not classrooms.'
            ], 403);
        }

        $validated = $request->validate([
            'classroom_id' => 'required|integer|exists:classrooms,id',
            'is_class_teacher' => 'nullable|boolean',
        ]);

        $classroom = Classroom::findOrFail($validated['classroom_id']);

        // Check if classroom belongs to the same school
        if ($classroom->school_id !== $teacher->school_id) {
            return response()->json([
                'status' => 'error',
                'message' => 'The classroom does not belong to the same school as the teacher.'
            ], 403);
        }

        // If assigning as class teacher, check if teacher is already a class teacher for another classroom
        if ($validated['is_class_teacher']) {
            $existingClassroom = Classroom::whereHas('teachers', function ($query) use ($teacherId) {
                    $query->where('teacher_id', $teacherId)
                          ->where('is_class_teacher', true);
                })
                ->where('id', '!=', $classroom->id)
                ->first();

            if ($existingClassroom) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This teacher is already assigned as a class teacher to another classroom.',
                    'existing_classroom' => $existingClassroom->class_name
                ], 422);
            }
        }

        // Assign teacher to classroom
        $teacher->classrooms()->syncWithoutDetaching([
            $classroom->id => [
                'is_class_teacher' => $validated['is_class_teacher'] ?? false
            ]
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Teacher assigned to classroom successfully.',
            'data' => $teacher->load('classrooms')
        ]);
    }

    /**
     * Remove a teacher from a classroom (for schools without streams).
     */
    public function removeFromClassroom(Request $request, $teacherId, $classroomId)
    {
        $user = $this->getUser($request);
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        try {
            $teacher = Teacher::findOrFail($teacherId);
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

        // Check if school has streams enabled
        $school = School::find($teacher->school_id);
        if ($school && $school->has_streams) {
            return response()->json([
                'message' => 'Your school has streams enabled. Teachers should be removed from streams, not classrooms.'
            ], 403);
        }

        try {
            $classroom = Classroom::findOrFail($classroomId);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'No classroom found with the specified ID.'
            ], 404);
        }

        // Check if classroom belongs to the same school
        if ($classroom->school_id !== $teacher->school_id) {
            return response()->json([
                'status' => 'error',
                'message' => 'The classroom does not belong to the same school as the teacher.'
            ], 403);
        }

        // Remove teacher from classroom
        $teacher->classrooms()->detach($classroom->id);

        return response()->json([
            'status' => 'success',
            'message' => 'Teacher removed from classroom successfully.'
        ]);
    }

    /**
     * Get all class teachers (for schools without streams).
     */
    public function getAllClassTeachers(Request $request)
    {
        $user = $this->getUser($request);
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        // Check if school has streams enabled
        $school = School::find($user->school_id);
        if ($school && $school->has_streams) {
            return response()->json([
                'message' => 'Your school has streams enabled. Class teachers are assigned to streams, not classrooms.'
            ], 403);
        }

        $classTeachers = Teacher::whereHas('classrooms', function ($query) {
                $query->where('is_class_teacher', true);
            })
            ->with(['user', 'classrooms' => function($query) {
                $query->wherePivot('is_class_teacher', true);
            }])
            ->where('school_id', $user->school_id)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $classTeachers
        ]);
    }

    /**
     * Get streams where teacher is class teacher.
     */
    public function getStreamsAsClassTeacher(Request $request, $teacherId)
    {
        $user = $this->getUser($request);
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        try {
            $teacher = Teacher::findOrFail($teacherId);
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

        // Check if school has streams enabled
        $school = School::find($teacher->school_id);
        if (!$school || !$school->has_streams) {
            return response()->json([
                'message' => 'Your school does not have streams enabled.'
            ], 403);
        }

        $streams = $teacher->getStreamsAsClassTeacher();

        return response()->json([
            'status' => 'success',
            'data' => $streams
        ]);
    }

    /**
     * Get streams where teacher teaches.
     */
    public function getStreamsAsTeacher(Request $request, $teacherId)
    {
        $user = $this->getUser($request);
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        try {
            $teacher = Teacher::findOrFail($teacherId);
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

        // Check if school has streams enabled
        $school = School::find($teacher->school_id);
        if (!$school || !$school->has_streams) {
            return response()->json([
                'message' => 'Your school does not have streams enabled.'
            ], 403);
        }

        $streams = $teacher->getStreamsAsTeacher();

        return response()->json([
            'status' => 'success',
            'data' => $streams
        ]);
    }

    /**
     * Get teachers by stream ID.
     */
    public function getTeachersByStream(Request $request, $streamId)
    {
        $user = $this->getUser($request);
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        try {
            $stream = Stream::findOrFail($streamId);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'No stream found with the specified ID.'
            ], 404);
        }

        if ($stream->school_id !== $user->school_id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        // Check if school has streams enabled
        $school = School::find($user->school_id);
        if (!$school || !$school->has_streams) {
            return response()->json([
                'message' => 'Your school does not have streams enabled.'
            ], 403);
        }

        $teachers = $stream->teachers()->with('user')->get();

        return response()->json([
            'status' => 'success',
            'data' => $teachers
        ]);
    }

    /**
     * NEW METHOD: Get teachers with their teaching assignments (classrooms or streams).
     * For schools without streams: returns teachers with classrooms they teach
     * For schools with streams: returns teachers with streams they teach
     */
    public function getTeachersWithAssignments(Request $request)
    {
        $user = $this->getUser($request);
        
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        // Get school information to check if streams are enabled
        $school = School::find($user->school_id);
        $hasStreams = $school ? $school->has_streams : false;

        $query = Teacher::with(['user', 'school']);

        // Load appropriate relationships based on school configuration
        if ($hasStreams) {
            // For schools with streams
            $query->with([
                'classTeacherStreams.classroom',
                'teachingStreams.classroom'
            ]);
        } else {
            // For schools without streams
            $query->with([
                'classrooms' => function($query) {
                    $query->withPivot('is_class_teacher');
                }
            ]);
        }

        $query->where('school_id', $user->school_id);

        // Optionally filter by curriculum specialization
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
        
        $teachers = $query->get()->map(function($teacher) use ($hasStreams) {
            // Get the user data and handle cases where user might not exist
            $userName = $teacher->user ? $teacher->user->full_name : 'N/A';
            $userEmail = $teacher->user ? $teacher->user->email : 'N/A';
            $userPhone = $teacher->user ? $teacher->user->phone : 'N/A';
            
            $teacherData = [
                'id' => $teacher->id,
                'name' => $userName,
                'email' => $userEmail,
                'phone' => $userPhone,
                'qualification' => $teacher->qualification,
                'employment_type' => $teacher->employment_type,
                'tsc_number' => $teacher->tsc_number,
                'specialization' => $teacher->specialization,
                'curriculum_specialization' => $teacher->curriculum_specialization,
                'max_subjects' => $teacher->max_subjects,
                'max_classes' => $teacher->max_classes,
            ];

            if ($hasStreams) {
                // For schools with streams
                $teacherData['class_teacher_streams'] = $teacher->classTeacherStreams->map(function($stream) {
                    return [
                        'stream_id' => $stream->id,
                        'stream_name' => $stream->stream_name,
                        'classroom_name' => $stream->classroom->class_name ?? 'N/A',
                        'full_name' => ($stream->classroom->class_name ?? 'N/A') . ' - ' . $stream->stream_name,
                    ];
                });

                $teacherData['teaching_streams'] = $teacher->teachingStreams->map(function($stream) {
                    return [
                        'stream_id' => $stream->id,
                        'stream_name' => $stream->stream_name,
                        'classroom_name' => $stream->classroom->class_name ?? 'N/A',
                        'full_name' => ($stream->classroom->class_name ?? 'N/A') . ' - ' . $stream->stream_name,
                    ];
                });
            } else {
                // For schools without streams
                $teacherData['classrooms'] = $teacher->classrooms->map(function($classroom) {
                    return [
                        'classroom_id' => $classroom->id,
                        'classroom_name' => $classroom->class_name,
                        'is_class_teacher' => (bool)$classroom->pivot->is_class_teacher,
                    ];
                });

                // Separate class teacher classrooms
                $teacherData['class_teacher_classrooms'] = $teacher->classrooms
                    ->where('pivot.is_class_teacher', true)
                    ->map(function($classroom) {
                        return [
                            'classroom_id' => $classroom->id,
                            'classroom_name' => $classroom->class_name,
                        ];
                    })->values();
            }

            return $teacherData;
        });

        return response()->json([
            'status' => 'success',
            'has_streams' => $hasStreams,
            'school_name' => $school->school_name ?? 'N/A',
            'data' => $teachers
        ]);
    }
}