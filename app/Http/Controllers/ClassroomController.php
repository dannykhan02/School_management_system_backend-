<?php

namespace App\Http\Controllers;

use App\Models\Classroom;
use App\Models\Teacher;
use App\Models\School;
use App\Models\Stream;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ClassroomController extends Controller
{
    private function getUser(Request $request)
    {
        $user = Auth::user();
        if (!$user && $request->has('school_id')) {
            $user = User::where('school_id', $request->school_id)->first();
        }
        return $user;
    }

    private function checkSchoolStreamsEnabled(Request $request)
    {
        $user = $this->getUser($request);
        if (!$user) return false;

        $school = School::find($user->school_id);
        if (!$school) return false;

        return $school->has_streams;
    }

    public function index(Request $request)
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        if (!$user->school_id)
            return response()->json(['message' => 'User is not associated with any school.'], 400);

        $hasStreams = $this->checkSchoolStreamsEnabled($request);

        $classrooms = Classroom::with(['school'])
            ->where('school_id', $user->school_id);

        if ($hasStreams) {
            $classrooms->with(['streams', 'streams.students']);
        } else {
            $classrooms->with(['teachers']);
        }

        return response()->json([
            'status' => 'success',
            'has_streams' => $hasStreams,
            'data' => $classrooms->get()
        ]);
    }

    public function store(Request $request)
    {
        $user = $this->getUser($request);

        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        $hasStreams = $this->checkSchoolStreamsEnabled($request);

        $validationRules = [
            'class_name' => 'required|string|max:255',
            'school_id' => 'required|integer|exists:schools,id',
        ];

        if ($hasStreams) {
            $validationRules['streams'] = 'nullable|array';
            $validationRules['streams.*.name'] = 'required|string|max:255';
            $validationRules['streams.*.capacity'] = 'required|integer|min:1';
            $validationRules['streams.*.class_teacher_id'] = 'nullable|integer';
        } else {
            $validationRules['capacity'] = 'required|integer|min:1';
            $validationRules['teachers'] = 'nullable|array';
            $validationRules['teachers.*.teacher_id'] = 'required|integer|exists:teachers,id';
            $validationRules['teachers.*.is_class_teacher'] = 'nullable|boolean';
        }

        $validated = $request->validate($validationRules);

        if ($validated['school_id'] != $user->school_id) {
            return response()->json([
                'status' => 'error',
                'message' => 'You can only create classrooms for your own school.'
            ], 403);
        }

        $classroomData = [
            'class_name' => $validated['class_name'],
            'school_id' => $validated['school_id'],
        ];

        // Add capacity only for non-stream schools
        if (!$hasStreams) {
            $classroomData['capacity'] = $validated['capacity'];
        }

        $classroom = Classroom::create($classroomData);

        if ($hasStreams && isset($validated['streams'])) {
            foreach ($validated['streams'] as $streamData) {
                if (!empty($streamData['class_teacher_id'])) {
                    $teacher = Teacher::find($streamData['class_teacher_id']);
                    if (!$teacher || $teacher->school_id !== $user->school_id) {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'One or more class teachers do not belong to your school.'
                        ], 422);
                    }
                }

                Stream::create([
                    'name' => $streamData['name'],
                    'capacity' => $streamData['capacity'],
                    'class_teacher_id' => $streamData['class_teacher_id'] ?? null,
                    'class_id' => $classroom->id,
                    'school_id' => $user->school_id,
                ]);
            }
        }

        if (!$hasStreams && isset($validated['teachers'])) {
            foreach ($validated['teachers'] as $teacherData) {
                $teacher = Teacher::find($teacherData['teacher_id']);

                if (!$teacher || $teacher->school_id !== $user->school_id) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'One or more teachers do not belong to your school.'
                    ], 422);
                }

                $classroom->teachers()->attach($teacher->id, [
                    'is_class_teacher' => $teacherData['is_class_teacher'] ?? false
                ]);
            }
        }

        $loadRelations = ['school'];
        $loadRelations[] = $hasStreams ? 'streams' : 'teachers';

        return response()->json([
            'status' => 'success',
            'message' => 'Classroom created successfully.',
            'has_streams' => $hasStreams,
            'data' => $classroom->load($loadRelations)
        ], 201);
    }

    public function show(Request $request, $id)
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        try {
            $hasStreams = $this->checkSchoolStreamsEnabled($request);

            $loadRelations = ['school'];
            $loadRelations = $hasStreams
                ? array_merge($loadRelations, ['streams', 'streams.students', 'streams.classTeacher'])
                : array_merge($loadRelations, ['teachers']);

            $classroom = Classroom::with($loadRelations)->findOrFail($id);

            if ($classroom->school_id !== $user->school_id)
                return response()->json(['message' => 'Unauthorized access to this classroom.'], 403);

        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'No classroom found with the specified ID.'], 404);
        }

        return response()->json([
            'status' => 'success',
            'has_streams' => $hasStreams,
            'data' => $classroom
        ]);
    }

    public function update(Request $request, $id)
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        try {
            $classroom = Classroom::findOrFail($id);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'No classroom found with the specified ID.'], 404);
        }

        if ($classroom->school_id !== $user->school_id)
            return response()->json(['message' => 'Unauthorized. This classroom does not belong to your school.'], 403);

        $hasStreams = $this->checkSchoolStreamsEnabled($request);

        $validationRules = [
            'class_name' => 'sometimes|required|string|max:255',
            'school_id' => 'sometimes|required|integer|exists:schools,id'
        ];

        // Only allow capacity update for schools without streams
        if (!$hasStreams) {
            $validationRules['capacity'] = 'sometimes|required|integer|min:1';
        }

        $validated = $request->validate($validationRules);

        if (isset($validated['school_id']) && $validated['school_id'] != $user->school_id)
            return response()->json(['status' => 'error', 'message' => 'You cannot change the school of a classroom.'], 403);

        // If capacity is being updated for a school with streams, return error
        if ($hasStreams && $request->has('capacity')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot update classroom capacity. Your school has streams enabled. Update individual stream capacities instead.'
            ], 403);
        }

        $classroom->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Classroom updated successfully.',
            'has_streams' => $hasStreams,
            'data' => $classroom->load(['school', $hasStreams ? 'streams' : 'teachers'])
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        try {
            $classroom = Classroom::findOrFail($id);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'No classroom found with the specified ID.'], 404);
        }

        if ($classroom->school_id !== $user->school_id)
            return response()->json(['message' => 'Unauthorized. This classroom does not belong to your school.'], 403);

        $classroom->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Classroom deleted successfully.'
        ]);
    }

    public function getStreams(Request $request, $classroomId)
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        if (!$this->checkSchoolStreamsEnabled($request))
            return response()->json(['message' => 'Your school does not have streams enabled.'], 403);

        try {
            $classroom = Classroom::findOrFail($classroomId);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'No classroom found with the specified ID.'], 404);
        }

        if ($classroom->school_id !== $user->school_id)
            return response()->json(['message' => 'Unauthorized.'], 403);

        $streams = Stream::where('class_id', $classroomId)
            ->with(['school', 'classTeacher.user', 'teachers', 'students'])
            ->get();

        return response()->json([
            'status' => 'success',
            'classroom' => $classroom,
            'streams' => $streams
        ]);
    }

    public function addStream(Request $request, $classroomId)
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        if (!$this->checkSchoolStreamsEnabled($request))
            return response()->json(['message' => 'Your school does not have streams enabled.'], 403);

        try {
            $classroom = Classroom::findOrFail($classroomId);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'No classroom found with the specified ID.'], 404);
        }

        if ($classroom->school_id !== $user->school_id)
            return response()->json(['message' => 'Unauthorized. You cannot modify a classroom from another school.'], 403);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'capacity' => 'required|integer|min:1',
            'class_teacher_id' => 'nullable|integer',
        ]);

        if (!empty($validated['class_teacher_id'])) {
            $teacher = Teacher::find($validated['class_teacher_id']);

            if (!$teacher)
                return response()->json([
                    'status' => 'error',
                    'message' => 'The specified teacher does not exist.',
                ], 422);

            if ($teacher->school_id != $classroom->school_id)
                return response()->json([
                    'status' => 'error',
                    'message' => 'The class teacher must belong to the same school.',
                ], 422);
        }

        $stream = Stream::create([
            'name' => $validated['name'],
            'capacity' => $validated['capacity'],
            'class_teacher_id' => $validated['class_teacher_id'] ?? null,
            'class_id' => $classroom->id,
            'school_id' => $user->school_id,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Stream added successfully.',
            'data' => $stream->load(['school', 'classroom', 'classTeacher'])
        ], 201);
    }

    public function getTeachers(Request $request, $classroomId)
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        if ($this->checkSchoolStreamsEnabled($request))
            return response()->json([
                'message' => 'Your school has streams enabled. Teachers are assigned to streams, not classrooms.'
            ], 403);

        try {
            $classroom = Classroom::findOrFail($classroomId);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'No classroom found with the specified ID.'], 404);
        }

        if ($classroom->school_id !== $user->school_id)
            return response()->json(['message' => 'Unauthorized.'], 403);

        $teachers = $classroom->teachers()->with('user')->get();

        return response()->json([
            'status' => 'success',
            'classroom' => $classroom,
            'teachers' => $teachers
        ]);
    }

    public function assignTeachers(Request $request, $classroomId)
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        if ($this->checkSchoolStreamsEnabled($request)) {
            return response()->json([
                'message' => 'Your school has streams enabled. Teachers should be assigned to streams, not classrooms.'
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

        if ($classroom->school_id !== $user->school_id)
            return response()->json(['message' => 'Unauthorized. You cannot modify a classroom from another school.'], 403);

        $validated = $request->validate([
            'teachers' => 'required|array',
            'teachers.*.teacher_id' => 'required|integer|exists:teachers,id',
            'teachers.*.is_class_teacher' => 'nullable|boolean',
        ]);

        $teacherAssignments = [];
        $classTeacherIds = [];

        foreach ($validated['teachers'] as $t) {
            $teacher = Teacher::find($t['teacher_id']);
            if (!$teacher || $teacher->school_id !== $user->school_id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'One or more teachers do not belong to your school.'
                ], 422);
            }

            $isClassTeacher = $t['is_class_teacher'] ?? false;
            if ($isClassTeacher) {
                $classTeacherIds[] = $teacher->id;
            }

            $teacherAssignments[$teacher->id] = [
                'is_class_teacher' => $isClassTeacher
            ];
        }

        // Ensure only one class teacher is assigned
        if (count($classTeacherIds) > 1) {
            return response()->json([
                'status' => 'error',
                'message' => 'Only one teacher can be assigned as a class teacher.',
                'errors' => ['is_class_teacher' => ['Multiple teachers cannot be marked as class teachers.']]
            ], 422);
        }

        $classroom->teachers()->sync($teacherAssignments);

        return response()->json([
            'status' => 'success',
            'message' => 'Teachers assigned successfully.',
            'data' => $classroom->load(['teachers'])
        ]);
    }

    /**
     * Assign a class teacher to a classroom.
     * Only available for schools without streams enabled.
     */
    public function assignClassTeacher(Request $request, $classroomId)
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        if ($this->checkSchoolStreamsEnabled($request)) {
            return response()->json([
                'message' => 'Your school has streams enabled. Class teachers should be assigned to streams, not classrooms.'
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

        if ($classroom->school_id !== $user->school_id)
            return response()->json(['message' => 'Unauthorized. You cannot modify a classroom from another school.'], 403);

        $validated = $request->validate([
            'teacher_id' => 'required|integer|exists:teachers,id',
        ]);

        $teacher = Teacher::find($validated['teacher_id']);
        
        if (!$teacher || $teacher->school_id !== $user->school_id) {
            return response()->json([
                'status' => 'error',
                'message' => 'The teacher must belong to the same school.',
                'errors' => ['teacher_id' => ['The selected teacher is invalid or does not belong to your school.']]
            ], 422);
        }

        // Use a local variable for the closure to avoid array dereference in use()
        $teacherId = $validated['teacher_id'];

        // Check if teacher is already a class teacher for another classroom
        $existingClassroom = Classroom::whereHas('teachers', function ($query) use ($teacherId) {
                $query->where('teacher_id', $teacherId)
                      ->where('is_class_teacher', true);
            })
            ->where('id', '!=', $classroomId)
            ->where('school_id', $user->school_id)
            ->first();

        if ($existingClassroom) {
            return response()->json([
                'status' => 'error',
                'message' => 'This teacher is already assigned as a class teacher to another classroom.',
                'errors' => ['teacher_id' => ['A teacher can only be a class teacher for one classroom.']],
                'existing_classroom' => $existingClassroom->class_name
            ], 422);
        }

        // Remove existing class teacher if any
        $currentClassTeacher = $classroom->teachers()->wherePivot('is_class_teacher', true)->first();
        if ($currentClassTeacher) {
            $classroom->teachers()->updateExistingPivot($currentClassTeacher->id, ['is_class_teacher' => false]);
        }

        // Assign new class teacher
        $classroom->teachers()->syncWithoutDetaching([
            $validated['teacher_id'] => ['is_class_teacher' => true]
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Class teacher assigned successfully.',
            'data' => $classroom->load(['teachers'])
        ]);
    }

    /**
     * Remove class teacher from a classroom.
     * Only available for schools without streams enabled.
     */
    public function removeClassTeacher(Request $request, $classroomId)
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        if ($this->checkSchoolStreamsEnabled($request)) {
            return response()->json([
                'message' => 'Your school has streams enabled. Class teachers should be removed from streams, not classrooms.'
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

        if ($classroom->school_id !== $user->school_id)
            return response()->json(['message' => 'Unauthorized.'], 403);

        // Find and update the class teacher
        $classTeacher = $classroom->teachers()->wherePivot('is_class_teacher', true)->first();
        
        if ($classTeacher) {
            $classroom->teachers()->updateExistingPivot($classTeacher->id, ['is_class_teacher' => false]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Class teacher removed successfully.',
            'data' => $classroom->load(['teachers'])
        ]);
    }
}