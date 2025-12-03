<?php

namespace App\Http\Controllers;

use App\Models\Classroom;
use App\Models\Teacher;
use App\Models\School;
use App\Models\Stream;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
                    
                    $existingStream = Stream::where('class_teacher_id', $streamData['class_teacher_id'])
                        ->where('school_id', $user->school_id)
                        ->first();
                        
                    if ($existingStream) {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'This teacher is already assigned as a class teacher to another stream.',
                            'errors' => ['class_teacher_id' => ['A teacher can only be a class teacher for one stream.']],
                            'existing_stream' => $existingStream->name
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
            $classTeacherIds = [];
            
            foreach ($validated['teachers'] as $teacherData) {
                $teacher = Teacher::find($teacherData['teacher_id']);

                if (!$teacher || $teacher->school_id !== $user->school_id) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'One or more teachers do not belong to your school.'
                    ], 422);
                }

                $isClassTeacher = $teacherData['is_class_teacher'] ?? false;
                
                if ($isClassTeacher) {
                    $classTeacherIds[] = $teacher->id;
                    
                    $existingClassroom = Classroom::whereHas('teachers', function ($query) use ($teacher) {
                            $query->where('teacher_id', $teacher->id)
                                  ->where('is_class_teacher', true);
                        })
                        ->where('id', '!=', $classroom->id)
                        ->where('school_id', $user->school_id)
                        ->first();

                    if ($existingClassroom) {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'This teacher is already assigned as a class teacher to another classroom.',
                            'errors' => ['is_class_teacher' => ['A teacher can only be a class teacher for one classroom.']],
                            'existing_classroom' => $existingClassroom->class_name
                        ], 422);
                    }
                }

                // Fixed: Get current class count directly from the pivot table
                $currentClassCount = DB::table('classroom_teacher')
                    ->where('teacher_id', $teacher->id)
                    ->count();
                    
                $maxClasses = $teacher->max_classes ?? 10;
                
                // Check if teacher is already assigned to this classroom
                $alreadyAssigned = DB::table('classroom_teacher')
                    ->where('teacher_id', $teacher->id)
                    ->where('classroom_id', $classroom->id)
                    ->first();
                
                // If not already assigned, this will be a new assignment
                $newClassCount = $alreadyAssigned ? $currentClassCount : $currentClassCount + 1;
                
                if ($newClassCount > $maxClasses) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'This teacher has reached their maximum number of classes.',
                        'errors' => ['teacher_id' => [
                            'Teacher is already assigned to ' . $currentClassCount . ' classes, ' .
                            'which is the maximum allowed (' . $maxClasses . ').'
                        ]]
                    ], 422);
                }

                if ($alreadyAssigned) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'This teacher is already assigned to this classroom.',
                        'errors' => ['teacher_id' => ['Teacher is already assigned to this classroom.']]
                    ], 422);
                }

                $classroom->teachers()->attach($teacher->id, [
                    'is_class_teacher' => $isClassTeacher
                ]);
            }
            
            if (count($classTeacherIds) > 1) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only one teacher can be assigned as a class teacher.',
                    'errors' => ['is_class_teacher' => ['Multiple teachers cannot be marked as class teachers.']]
                ], 422);
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
            return response()->json(['status' => 'error', 'message' => 'No classroom found with specified ID.'], 404);
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

        if (!$hasStreams) {
            $validationRules['capacity'] = 'sometimes|required|integer|min:1';
        }

        $validated = $request->validate($validationRules);

        if (isset($validated['school_id']) && $validated['school_id'] != $user->school_id)
            return response()->json(['status' => 'error', 'message' => 'You cannot change the school of a classroom.'], 403);

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
                
            $existingStream = Stream::where('class_teacher_id', $validated['class_teacher_id'])
                ->where('school_id', $user->school_id)
                ->first();
                
            if ($existingStream) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This teacher is already assigned as a class teacher to another stream.',
                    'errors' => ['class_teacher_id' => ['A teacher can only be a class teacher for one stream.']],
                    'existing_stream' => $existingStream->name
                ], 422);
            }
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
                
                $existingClassroom = Classroom::whereHas('teachers', function ($query) use ($teacher) {
                        $query->where('teacher_id', $teacher->id)
                              ->where('is_class_teacher', true);
                    })
                    ->where('id', '!=', $classroomId)
                    ->where('school_id', $user->school_id)
                    ->first();

                if ($existingClassroom) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'This teacher is already assigned as a class teacher to another classroom.',
                        'errors' => ['is_class_teacher' => ['A teacher can only be a class teacher for one classroom.']],
                        'existing_classroom' => $existingClassroom->class_name
                    ], 422);
                }
            }

            // Fixed: Get current class count directly from the pivot table
            $currentClassCount = DB::table('classroom_teacher')
                ->where('teacher_id', $teacher->id)
                ->count();
                
            $maxClasses = $teacher->max_classes ?? 10;
            
            $alreadyAssigned = DB::table('classroom_teacher')
                ->where('teacher_id', $teacher->id)
                ->where('classroom_id', $classroomId)
                ->first();
            
            // If not already assigned, this will be a new assignment
            $newClassCount = $alreadyAssigned ? $currentClassCount : $currentClassCount + 1;
            
            if ($newClassCount > $maxClasses) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This teacher has reached their maximum number of classes.',
                    'errors' => ['teacher_id' => [
                        'Teacher is already assigned to ' . $currentClassCount . ' classes, ' .
                        'which is the maximum allowed (' . $maxClasses . ').'
                    ]]
                ], 422);
            }

            $teacherAssignments[$teacher->id] = [
                'is_class_teacher' => $isClassTeacher ? 1 : 0
            ];
        }

        if (count($classTeacherIds) > 1) {
            return response()->json([
                'status' => 'error',
                'message' => 'Only one teacher can be assigned as a class teacher.',
                'errors' => ['is_class_teacher' => ['Multiple teachers cannot be marked as class teachers.']]
            ], 422);
        }

        $classroom->teachers()->syncWithoutDetaching($teacherAssignments);

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

        $teacherId = $validated['teacher_id'];

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

        // Fixed: Get current class count directly from the pivot table
        $currentClassCount = DB::table('classroom_teacher')
            ->where('teacher_id', $teacherId)
            ->count();
            
        $maxClasses = $teacher->max_classes ?? 10;
        
        $alreadyAssigned = DB::table('classroom_teacher')
            ->where('teacher_id', $teacherId)
            ->where('classroom_id', $classroomId)
            ->first();
        
        // If not already assigned, this will be a new assignment
        $newClassCount = $alreadyAssigned ? $currentClassCount : $currentClassCount + 1;
        
        if ($newClassCount > $maxClasses) {
            return response()->json([
                'status' => 'error',
                'message' => 'This teacher has reached their maximum number of classes.',
                'errors' => ['teacher_id' => [
                    'Teacher is already assigned to ' . $currentClassCount . ' classes, ' .
                    'which is the maximum allowed (' . $maxClasses . ').'
                ]]
            ], 422);
        }

        // Use transaction to ensure data integrity
        DB::beginTransaction();
        try {
            // 1. Find and REMOVE the existing class teacher for this classroom (if any)
            $existingClassTeacher = DB::table('classroom_teacher')
                ->where('classroom_id', $classroomId)
                ->where('is_class_teacher', 1)
                ->first();
            
            if ($existingClassTeacher) {
                // Demote the existing teacher by setting is_class_teacher to 0
                // This will AUTOMATICALLY set is_class_teacher_unique to NULL
                DB::table('classroom_teacher')
                    ->where('id', $existingClassTeacher->id)
                    ->update(['is_class_teacher' => 0]);
            }
            
            // 2. Check if the new teacher is already assigned to this classroom
            $existingAssignment = DB::table('classroom_teacher')
                ->where('teacher_id', $teacherId)
                ->where('classroom_id', $classroomId)
                ->first();
            
            if ($existingAssignment) {
                // Promote the existing assignment to be the class teacher
                // This will AUTOMATICALLY set is_class_teacher_unique to 1
                DB::table('classroom_teacher')
                    ->where('id', $existingAssignment->id)
                    ->update([
                        'is_class_teacher' => 1,
                        'updated_at' => now()
                    ]);
            } else {
                // 3. Insert a new record for the new class teacher
                // is_class_teacher_unique will be AUTOMATICALLY calculated by the DB
                DB::table('classroom_teacher')->insert([
                    'classroom_id' => $classroomId,
                    'teacher_id' => $teacherId,
                    'is_class_teacher' => 1,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            
            if ($e->getCode() == 23000 && strpos($e->getMessage(), 'Duplicate entry') !== false) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This teacher is already assigned to this classroom.',
                    'errors' => ['teacher_id' => ['Teacher is already assigned to this classroom.']]
                ], 422);
            }
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to assign class teacher.',
                'error' => $e->getMessage()
            ], 500);
        }

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

        // Update all class teachers to regular teachers by changing is_class_teacher to 0
        // This will AUTOMATICALLY update is_class_teacher_unique to NULL
        DB::table('classroom_teacher')
            ->where('classroom_id', $classroomId)
            ->where('is_class_teacher', 1)
            ->update(['is_class_teacher' => 0]);

        return response()->json([
            'status' => 'success',
            'message' => 'Class teacher removed successfully.',
            'data' => $classroom->load(['teachers'])
        ]);
    }
    
    /**
     * Assign a teacher to multiple classrooms as a regular teacher
     * Only available for schools without streams enabled.
     */
    public function assignToMultipleClassrooms(Request $request)
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        if ($this->checkSchoolStreamsEnabled($request)) {
            return response()->json([
                'message' => 'Your school has streams enabled. Teachers should be assigned to streams, not classrooms.'
            ], 403);
        }

        $validated = $request->validate([
            'teacher_id' => 'required|integer|exists:teachers,id',
            'classroom_ids' => 'required|array',
            'classroom_ids.*' => 'integer|exists:classrooms,id',
        ]);

        $teacher = Teacher::find($validated['teacher_id']);
        
        if (!$teacher || $teacher->school_id !== $user->school_id) {
            return response()->json([
                'status' => 'error',
                'message' => 'The teacher must belong to the same school.',
                'errors' => ['teacher_id' => ['The selected teacher is invalid or does not belong to your school.']]
            ], 422);
        }

        $classrooms = Classroom::whereIn('id', $validated['classroom_ids'])->get();
        foreach ($classrooms as $classroom) {
            if ($classroom->school_id !== $user->school_id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'All classrooms must belong to the same school.',
                    'errors' => ['classroom_ids' => ['Classroom with ID ' . $classroom->id . ' does not belong to your school.']]
                ], 422);
            }
        }

        $teacherId = $validated['teacher_id'];
        $assignedClassrooms = [];
        $skippedClassrooms = [];
        
        // Fixed: Get current class count directly from the pivot table
        $currentClassCount = DB::table('classroom_teacher')
            ->where('teacher_id', $teacherId)
            ->count();
            
        $maxClasses = $teacher->max_classes ?? 10;
        $availableSlots = $maxClasses - $currentClassCount;
        
        if ($availableSlots <= 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'This teacher has reached their maximum number of classes.',
                'errors' => ['teacher_id' => [
                    'Teacher is already assigned to ' . $currentClassCount . ' classes, ' .
                    'which is the maximum allowed (' . $maxClasses . ').'
                ]]
            ], 422);
        }
        
        $requestedClassrooms = $validated['classroom_ids'];
        if (count($requestedClassrooms) > $availableSlots) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot assign teacher to all requested classrooms.',
                'errors' => ['classroom_ids' => [
                    'Teacher can only be assigned to ' . $availableSlots . ' more classes, ' .
                    'but ' . count($requestedClassrooms) . ' were requested.'
                ]]
            ], 422);
        }
        
        DB::beginTransaction();
        try {
            foreach ($validated['classroom_ids'] as $classroomId) {
                $existingAssignment = DB::table('classroom_teacher')
                    ->where('teacher_id', $teacherId)
                    ->where('classroom_id', $classroomId)
                    ->first();
                
                if ($existingAssignment) {
                    $skippedClassrooms[] = $classroomId;
                    continue;
                }
                
                // Insert new record
                // is_class_teacher_unique will be AUTOMATICALLY calculated based on is_class_teacher
                DB::table('classroom_teacher')->insert([
                    'classroom_id' => $classroomId,
                    'teacher_id' => $teacherId,
                    'is_class_teacher' => 0,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
                
                $assignedClassrooms[] = $classroomId;
            }
            
            DB::commit();
            
            $message = 'Teacher assigned to classrooms successfully.';
            if (count($skippedClassrooms) > 0) {
                $message .= ' Some classrooms were skipped because the teacher is already assigned to them.';
            }
            
            return response()->json([
                'status' => 'success',
                'message' => $message,
                'data' => [
                    'teacher_id' => $teacherId,
                    'assigned_to' => $assignedClassrooms,
                    'already_assigned' => $skippedClassrooms,
                    'total_requested' => count($validated['classroom_ids']),
                    'newly_assigned' => count($assignedClassrooms),
                    'skipped' => count($skippedClassrooms),
                    'max_classes' => $maxClasses,
                    'current_class_count' => $currentClassCount + count($assignedClassrooms)
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to assign teacher to classrooms.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get available classrooms for a teacher based on their max_classes limit
     */
    public function getAvailableClassroomsForTeacher(Request $request, $teacherId)
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        if ($this->checkSchoolStreamsEnabled($request)) {
            return response()->json([
                'message' => 'Your school has streams enabled. Teachers are assigned to streams, not classrooms.'
            ], 403);
        }

        try {
            $teacher = Teacher::findOrFail($teacherId);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'No teacher found with the specified ID.'
            ], 404);
        }

        if ($teacher->school_id !== $user->school_id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        // Fixed: Get current class count directly from the pivot table
        $currentClassCount = DB::table('classroom_teacher')
            ->where('teacher_id', $teacherId)
            ->count();
            
        $maxClasses = $teacher->max_classes ?? 10;
        $availableSlots = $maxClasses - $currentClassCount;
        
        $availableClassrooms = Classroom::where('school_id', $user->school_id)
            ->whereNotIn('id', function($query) use ($teacherId) {
                $query->select('classroom_id')
                    ->from('classroom_teacher')
                    ->where('teacher_id', $teacherId);
            })
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'teacher_id' => $teacherId,
                'current_class_count' => $currentClassCount,
                'max_classes' => $maxClasses,
                'available_slots' => $availableSlots,
                'available_classrooms' => $availableClassrooms
            ]
        ]);
    }
}