<?php

namespace App\Http\Controllers;

use App\Models\Classroom;
use App\Models\Teacher;
use App\Models\School;
use App\Models\Stream;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ClassroomController extends Controller
{
    // ──────────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ──────────────────────────────────────────────────────────────────────────

    private function getUser(Request $request): ?User
    {
        $user = Auth::user();
        if (!$user && $request->has('school_id')) {
            $user = User::where('school_id', $request->school_id)->first();
        }
        return $user;
    }

    private function checkSchoolStreamsEnabled(Request $request): bool
    {
        $user = $this->getUser($request);
        if (!$user) return false;
        $school = School::find($user->school_id);
        if (!$school) return false;
        return $school->has_streams;
    }

    /**
     * How many classrooms is this teacher currently assigned to?
     * Reads directly from the pivot table (avoids Eloquent collection count bugs).
     */
    private function getTeacherClassCount(int $teacherId): int
    {
        return DB::table('classroom_teacher')
            ->where('teacher_id', $teacherId)
            ->count();
    }

    /**
     * Is this teacher already assigned to this specific classroom?
     */
    private function isTeacherInClassroom(int $teacherId, int $classroomId): bool
    {
        return DB::table('classroom_teacher')
            ->where('teacher_id', $teacherId)
            ->where('classroom_id', $classroomId)
            ->exists();
    }

    /**
     * Check that assigning this teacher to one more classroom won't breach max_classes.
     * Returns null on success or a JsonResponse 422 on failure.
     *
     * @param  Teacher  $teacher
     * @param  int      $classroomId    The classroom being considered (used to detect existing)
     * @param  int      $addingCount    How many NEW classrooms are being added in this request
     */
    private function validateTeacherClassCapacity(
        Teacher $teacher,
        int $classroomId,
        int $addingCount = 1
    ): ?JsonResponse {
        $currentCount = $this->getTeacherClassCount($teacher->id);
        $maxClasses   = $teacher->max_classes ?? 10;

        // If already in this classroom, the count won't grow
        $effectiveNew = $this->isTeacherInClassroom($teacher->id, $classroomId) ? 0 : $addingCount;

        if (($currentCount + $effectiveNew) > $maxClasses) {
            return response()->json([
                'status'  => 'error',
                'message' => 'This teacher has reached their maximum number of classes.',
                'errors'  => ['teacher_id' => [
                    "Teacher is already assigned to {$currentCount} classes, " .
                    "which is the maximum allowed ({$maxClasses}).",
                ]],
            ], 422);
        }

        return null;
    }

    /**
     * Verify that this teacher is not already a class teacher for a DIFFERENT classroom.
     * Returns null on success or a JsonResponse 422 on failure.
     *
     * @param  int  $teacherId
     * @param  int  $excludeClassroomId   The classroom currently being assigned (exclude from check)
     */
    private function validateSingleClassTeacher(int $teacherId, int $excludeClassroomId): ?JsonResponse
    {
        $existing = Classroom::whereHas('teachers', fn($q) =>
            $q->where('teacher_id', $teacherId)
              ->where('is_class_teacher', true)
        )
        ->where('id', '!=', $excludeClassroomId)
        ->first();

        if ($existing) {
            return response()->json([
                'status'             => 'error',
                'message'            => 'This teacher is already assigned as a class teacher to another classroom.',
                'errors'             => ['is_class_teacher' => ['A teacher can only be a class teacher for one classroom.']],
                'existing_classroom' => $existing->class_name,
            ], 422);
        }

        return null;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // CRUD
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * GET /api/classrooms
     */
    public function index(Request $request): JsonResponse
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
            // ✅ NEW: include combination so frontend can display teacher B.Ed profile
            $classrooms->with(['teachers', 'teachers.user', 'teachers.combination']);
        }

        return response()->json([
            'status'      => 'success',
            'has_streams' => $hasStreams,
            'data'        => $classrooms->get(),
        ]);
    }

    /**
     * POST /api/classrooms
     *
     * BUG FIX (original): The multi-class-teacher guard was placed AFTER the attach()
     * loop, meaning bad data could already be written by the time the check fired.
     * Fixed by running all validations upfront before any DB write.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        $hasStreams = $this->checkSchoolStreamsEnabled($request);

        $validationRules = [
            'class_name' => 'required|string|max:255',
            'school_id'  => 'required|integer|exists:schools,id',
        ];

        if ($hasStreams) {
            $validationRules['streams']                       = 'nullable|array';
            $validationRules['streams.*.name']                = 'required|string|max:255';
            $validationRules['streams.*.capacity']            = 'required|integer|min:1';
            $validationRules['streams.*.class_teacher_id']    = 'nullable|integer';
        } else {
            $validationRules['capacity']                      = 'required|integer|min:1';
            $validationRules['teachers']                      = 'nullable|array';
            $validationRules['teachers.*.teacher_id']         = 'required|integer|exists:teachers,id';
            $validationRules['teachers.*.is_class_teacher']   = 'nullable|boolean';
        }

        $validated = $request->validate($validationRules);

        if ($validated['school_id'] != $user->school_id) {
            return response()->json([
                'status'  => 'error',
                'message' => 'You can only create classrooms for your own school.',
            ], 403);
        }

        $classroomData = [
            'class_name' => $validated['class_name'],
            'school_id'  => $validated['school_id'],
        ];
        if (!$hasStreams) {
            $classroomData['capacity'] = $validated['capacity'];
        }

        // ── Non-stream: validate ALL teachers BEFORE any DB write ─────────────
        // (Fixes original race-condition bug where attach() fired before the
        //  classTeacherIds > 1 guard was checked.)
        if (!$hasStreams && !empty($validated['teachers'])) {
            $classTeacherCount = 0;
            $tempClassroomId   = 0; // placeholder — classroom not created yet

            foreach ($validated['teachers'] as $teacherData) {
                $teacher = Teacher::find($teacherData['teacher_id']);

                if (!$teacher || $teacher->school_id !== $user->school_id) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'One or more teachers do not belong to your school.',
                    ], 422);
                }

                $isClassTeacher = $teacherData['is_class_teacher'] ?? false;

                if ($isClassTeacher) {
                    $classTeacherCount++;

                    // Guard: only one class teacher allowed
                    if ($classTeacherCount > 1) {
                        return response()->json([
                            'status'  => 'error',
                            'message' => 'Only one teacher can be assigned as a class teacher.',
                            'errors'  => ['is_class_teacher' => ['Multiple teachers cannot be marked as class teachers.']],
                        ], 422);
                    }

                    // Guard: teacher must not be class teacher in another classroom
                    $existingClassroom = Classroom::whereHas('teachers', fn($q) =>
                        $q->where('teacher_id', $teacher->id)
                          ->where('is_class_teacher', true)
                    )
                    ->where('school_id', $user->school_id)
                    ->first();

                    if ($existingClassroom) {
                        return response()->json([
                            'status'             => 'error',
                            'message'            => 'This teacher is already assigned as a class teacher to another classroom.',
                            'errors'             => ['is_class_teacher' => ['A teacher can only be a class teacher for one classroom.']],
                            'existing_classroom' => $existingClassroom->class_name,
                        ], 422);
                    }
                }

                // Guard: max_classes limit (use 0 as classroom ID since it's not created yet)
                $currentCount = $this->getTeacherClassCount($teacher->id);
                $maxClasses   = $teacher->max_classes ?? 10;
                if (($currentCount + 1) > $maxClasses) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'This teacher has reached their maximum number of classes.',
                        'errors'  => ['teacher_id' => [
                            "Teacher is already assigned to {$currentCount} classes, " .
                            "which is the maximum allowed ({$maxClasses}).",
                        ]],
                    ], 422);
                }
            }
        }

        // ── Stream school: validate class teacher assignments upfront ──────────
        if ($hasStreams && !empty($validated['streams'])) {
            foreach ($validated['streams'] as $streamData) {
                if (!empty($streamData['class_teacher_id'])) {
                    $teacher = Teacher::find($streamData['class_teacher_id']);
                    if (!$teacher || $teacher->school_id !== $user->school_id) {
                        return response()->json([
                            'status'  => 'error',
                            'message' => 'One or more class teachers do not belong to your school.',
                        ], 422);
                    }

                    $existingStream = Stream::where('class_teacher_id', $streamData['class_teacher_id'])
                                            ->where('school_id', $user->school_id)
                                            ->first();
                    if ($existingStream) {
                        return response()->json([
                            'status'          => 'error',
                            'message'         => 'This teacher is already assigned as a class teacher to another stream.',
                            'errors'          => ['class_teacher_id' => ['A teacher can only be a class teacher for one stream.']],
                            'existing_stream' => $existingStream->name,
                        ], 422);
                    }
                }
            }
        }

        // ── All validations passed — write to DB ──────────────────────────────
        DB::beginTransaction();
        try {
            $classroom = Classroom::create($classroomData);

            if ($hasStreams && !empty($validated['streams'])) {
                foreach ($validated['streams'] as $streamData) {
                    Stream::create([
                        'name'             => $streamData['name'],
                        'capacity'         => $streamData['capacity'],
                        'class_teacher_id' => $streamData['class_teacher_id'] ?? null,
                        'class_id'         => $classroom->id,
                        'school_id'        => $user->school_id,
                    ]);
                }
            }

            if (!$hasStreams && !empty($validated['teachers'])) {
                foreach ($validated['teachers'] as $teacherData) {
                    $teacher        = Teacher::find($teacherData['teacher_id']);
                    $isClassTeacher = $teacherData['is_class_teacher'] ?? false;

                    // Guard: skip if somehow already assigned (defensive)
                    if ($this->isTeacherInClassroom($teacher->id, $classroom->id)) {
                        continue;
                    }

                    $classroom->teachers()->attach($teacher->id, [
                        'is_class_teacher' => $isClassTeacher,
                    ]);
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to create classroom: ' . $e->getMessage(),
            ], 500);
        }

        $loadRelations = ['school'];
        $loadRelations[] = $hasStreams ? 'streams' : 'teachers.user';
        if (!$hasStreams) $loadRelations[] = 'teachers.combination';

        return response()->json([
            'status'      => 'success',
            'message'     => 'Classroom created successfully.',
            'has_streams' => $hasStreams,
            'data'        => $classroom->load($loadRelations),
        ], 201);
    }

    /**
     * GET /api/classrooms/{id}
     */
    public function show(Request $request, $id): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        try {
            $hasStreams = $this->checkSchoolStreamsEnabled($request);

            $loadRelations = ['school'];
            $loadRelations = $hasStreams
                // ✅ NEW: include combination on class teacher
                ? array_merge($loadRelations, ['streams', 'streams.students', 'streams.classTeacher.user', 'streams.classTeacher.combination'])
                : array_merge($loadRelations, ['teachers.user', 'teachers.combination']);

            $classroom = Classroom::with($loadRelations)->findOrFail($id);

            if ($classroom->school_id !== $user->school_id)
                return response()->json(['message' => 'Unauthorized access to this classroom.'], 403);

        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'No classroom found with specified ID.'], 404);
        }

        return response()->json([
            'status'      => 'success',
            'has_streams' => $hasStreams,
            'data'        => $classroom,
        ]);
    }

    /**
     * PUT/PATCH /api/classrooms/{id}
     */
    public function update(Request $request, $id): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        try {
            $classroom = Classroom::findOrFail($id);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'No classroom found with the specified ID.'], 404);
        }

        if ($classroom->school_id !== $user->school_id)
            return response()->json(['message' => 'Unauthorized. This classroom does not belong to your school.'], 403);

        $hasStreams = $this->checkSchoolStreamsEnabled($request);

        $validationRules = [
            'class_name' => 'sometimes|required|string|max:255',
            'school_id'  => 'sometimes|required|integer|exists:schools,id',
        ];
        if (!$hasStreams) {
            $validationRules['capacity'] = 'sometimes|required|integer|min:1';
        }

        $validated = $request->validate($validationRules);

        if (isset($validated['school_id']) && $validated['school_id'] != $user->school_id)
            return response()->json(['status' => 'error', 'message' => 'You cannot change the school of a classroom.'], 403);

        if ($hasStreams && $request->has('capacity')) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Cannot update classroom capacity. Your school has streams enabled. Update individual stream capacities instead.',
            ], 403);
        }

        $classroom->update($validated);

        return response()->json([
            'status'      => 'success',
            'message'     => 'Classroom updated successfully.',
            'has_streams' => $hasStreams,
            // ✅ NEW: include combination in response
            'data'        => $classroom->load(['school', $hasStreams ? 'streams' : 'teachers.user', 'teachers.combination']),
        ]);
    }

    /**
     * DELETE /api/classrooms/{id}
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        try {
            $classroom = Classroom::findOrFail($id);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'No classroom found with the specified ID.'], 404);
        }

        if ($classroom->school_id !== $user->school_id)
            return response()->json(['message' => 'Unauthorized. This classroom does not belong to your school.'], 403);

        $classroom->delete();

        return response()->json(['status' => 'success', 'message' => 'Classroom deleted successfully.']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // STREAMS
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * GET /api/classrooms/{classroomId}/streams
     */
    public function getStreams(Request $request, $classroomId): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        if (!$this->checkSchoolStreamsEnabled($request))
            return response()->json(['message' => 'Your school does not have streams enabled.'], 403);

        try {
            $classroom = Classroom::findOrFail($classroomId);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'No classroom found with the specified ID.'], 404);
        }

        if ($classroom->school_id !== $user->school_id)
            return response()->json(['message' => 'Unauthorized.'], 403);

        // ✅ NEW: include combination on all teachers in the stream
        $streams = Stream::where('class_id', $classroomId)
            ->with(['school', 'classTeacher.user', 'classTeacher.combination', 'teachers.user', 'teachers.combination', 'students'])
            ->get();

        return response()->json([
            'status'    => 'success',
            'classroom' => $classroom,
            'streams'   => $streams,
        ]);
    }

    /**
     * POST /api/classrooms/{classroomId}/streams
     */
    public function addStream(Request $request, $classroomId): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        if (!$this->checkSchoolStreamsEnabled($request))
            return response()->json(['message' => 'Your school does not have streams enabled.'], 403);

        try {
            $classroom = Classroom::findOrFail($classroomId);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'No classroom found with the specified ID.'], 404);
        }

        if ($classroom->school_id !== $user->school_id)
            return response()->json(['message' => 'Unauthorized. You cannot modify a classroom from another school.'], 403);

        $validated = $request->validate([
            'name'             => 'required|string|max:255',
            'capacity'         => 'required|integer|min:1',
            'class_teacher_id' => 'nullable|integer',
        ]);

        if (!empty($validated['class_teacher_id'])) {
            $teacher = Teacher::find($validated['class_teacher_id']);

            if (!$teacher)
                return response()->json(['status' => 'error', 'message' => 'The specified teacher does not exist.'], 422);

            if ($teacher->school_id != $classroom->school_id)
                return response()->json(['status' => 'error', 'message' => 'The class teacher must belong to the same school.'], 422);

            $existingStream = Stream::where('class_teacher_id', $validated['class_teacher_id'])
                                    ->where('school_id', $user->school_id)
                                    ->first();
            if ($existingStream) {
                return response()->json([
                    'status'          => 'error',
                    'message'         => 'This teacher is already assigned as a class teacher to another stream.',
                    'errors'          => ['class_teacher_id' => ['A teacher can only be a class teacher for one stream.']],
                    'existing_stream' => $existingStream->name,
                ], 422);
            }
        }

        $stream = Stream::create([
            'name'             => $validated['name'],
            'capacity'         => $validated['capacity'],
            'class_teacher_id' => $validated['class_teacher_id'] ?? null,
            'class_id'         => $classroom->id,
            'school_id'        => $user->school_id,
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Stream added successfully.',
            // ✅ NEW: include combination on class teacher
            'data'    => $stream->load(['school', 'classroom', 'classTeacher.user', 'classTeacher.combination']),
        ], 201);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // TEACHER MANAGEMENT (non-stream schools)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * GET /api/classrooms/{classroomId}/teachers
     */
    public function getTeachers(Request $request, $classroomId): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        if ($this->checkSchoolStreamsEnabled($request))
            return response()->json(['message' => 'Your school has streams enabled. Teachers are assigned to streams, not classrooms.'], 403);

        try {
            $classroom = Classroom::findOrFail($classroomId);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'No classroom found with the specified ID.'], 404);
        }

        if ($classroom->school_id !== $user->school_id)
            return response()->json(['message' => 'Unauthorized.'], 403);

        // ✅ NEW: include combination so frontend can show teacher's B.Ed profile
        $teachers = $classroom->teachers()->with(['user', 'combination'])->get();

        return response()->json([
            'status'    => 'success',
            'classroom' => $classroom,
            'teachers'  => $teachers,
        ]);
    }

    /**
     * POST /api/classrooms/{classroomId}/teachers
     * Assign one or more teachers to a classroom (non-stream schools).
     */
    public function assignTeachers(Request $request, $classroomId): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        if ($this->checkSchoolStreamsEnabled($request))
            return response()->json(['message' => 'Your school has streams enabled. Teachers should be assigned to streams, not classrooms.'], 403);

        try {
            $classroom = Classroom::findOrFail($classroomId);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'No classroom found with the specified ID.'], 404);
        }

        if ($classroom->school_id !== $user->school_id)
            return response()->json(['message' => 'Unauthorized. You cannot modify a classroom from another school.'], 403);

        $validated = $request->validate([
            'teachers'                    => 'required|array',
            'teachers.*.teacher_id'       => 'required|integer|exists:teachers,id',
            'teachers.*.is_class_teacher' => 'nullable|boolean',
        ]);

        $teacherAssignments = [];
        $classTeacherIds    = [];

        foreach ($validated['teachers'] as $t) {
            $teacher = Teacher::find($t['teacher_id']);
            if (!$teacher || $teacher->school_id !== $user->school_id) {
                return response()->json(['status' => 'error', 'message' => 'One or more teachers do not belong to your school.'], 422);
            }

            $isClassTeacher = $t['is_class_teacher'] ?? false;

            if ($isClassTeacher) {
                $classTeacherIds[] = $teacher->id;

                if (count($classTeacherIds) > 1) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Only one teacher can be assigned as a class teacher.',
                        'errors'  => ['is_class_teacher' => ['Multiple teachers cannot be marked as class teachers.']],
                    ], 422);
                }

                $existingClassroom = Classroom::whereHas('teachers', fn($q) =>
                    $q->where('teacher_id', $teacher->id)
                      ->where('is_class_teacher', true)
                )
                ->where('id', '!=', $classroomId)
                ->where('school_id', $user->school_id)
                ->first();

                if ($existingClassroom) {
                    return response()->json([
                        'status'             => 'error',
                        'message'            => 'This teacher is already assigned as a class teacher to another classroom.',
                        'errors'             => ['is_class_teacher' => ['A teacher can only be a class teacher for one classroom.']],
                        'existing_classroom' => $existingClassroom->class_name,
                    ], 422);
                }
            }

            // Max classes guard
            $currentCount = $this->getTeacherClassCount($teacher->id);
            $maxClasses   = $teacher->max_classes ?? 10;
            $alreadyHere  = $this->isTeacherInClassroom($teacher->id, $classroomId);
            $newCount     = $alreadyHere ? $currentCount : $currentCount + 1;

            if ($newCount > $maxClasses) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'This teacher has reached their maximum number of classes.',
                    'errors'  => ['teacher_id' => [
                        "Teacher is already assigned to {$currentCount} classes, " .
                        "which is the maximum allowed ({$maxClasses}).",
                    ]],
                ], 422);
            }

            $teacherAssignments[$teacher->id] = ['is_class_teacher' => $isClassTeacher ? 1 : 0];
        }

        $classroom->teachers()->syncWithoutDetaching($teacherAssignments);

        return response()->json([
            'status'  => 'success',
            'message' => 'Teachers assigned successfully.',
            // ✅ NEW: include combination in response
            'data'    => $classroom->load(['teachers.user', 'teachers.combination']),
        ]);
    }

    /**
     * POST /api/classrooms/{classroomId}/class-teacher
     * Set the class teacher for a classroom (non-stream schools).
     */
    public function assignClassTeacher(Request $request, $classroomId): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        if ($this->checkSchoolStreamsEnabled($request))
            return response()->json(['message' => 'Your school has streams enabled. Class teachers should be assigned to streams, not classrooms.'], 403);

        try {
            $classroom = Classroom::findOrFail($classroomId);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'No classroom found with the specified ID.'], 404);
        }

        if ($classroom->school_id !== $user->school_id)
            return response()->json(['message' => 'Unauthorized. You cannot modify a classroom from another school.'], 403);

        $validated = $request->validate(['teacher_id' => 'required|integer|exists:teachers,id']);

        $teacher   = Teacher::find($validated['teacher_id']);
        $teacherId = $validated['teacher_id'];

        if (!$teacher || $teacher->school_id !== $user->school_id) {
            return response()->json([
                'status'  => 'error',
                'message' => 'The teacher must belong to the same school.',
                'errors'  => ['teacher_id' => ['The selected teacher is invalid or does not belong to your school.']],
            ], 422);
        }

        $existingClassroom = Classroom::whereHas('teachers', fn($q) =>
            $q->where('teacher_id', $teacherId)
              ->where('is_class_teacher', true)
        )
        ->where('id', '!=', $classroomId)
        ->where('school_id', $user->school_id)
        ->first();

        if ($existingClassroom) {
            return response()->json([
                'status'             => 'error',
                'message'            => 'This teacher is already assigned as a class teacher to another classroom.',
                'errors'             => ['teacher_id' => ['A teacher can only be a class teacher for one classroom.']],
                'existing_classroom' => $existingClassroom->class_name,
            ], 422);
        }

        // Max classes guard
        $currentCount = $this->getTeacherClassCount($teacherId);
        $maxClasses   = $teacher->max_classes ?? 10;
        $alreadyHere  = $this->isTeacherInClassroom($teacherId, $classroomId);
        $newCount     = $alreadyHere ? $currentCount : $currentCount + 1;

        if ($newCount > $maxClasses) {
            return response()->json([
                'status'  => 'error',
                'message' => 'This teacher has reached their maximum number of classes.',
                'errors'  => ['teacher_id' => [
                    "Teacher is already assigned to {$currentCount} classes, " .
                    "which is the maximum allowed ({$maxClasses}).",
                ]],
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Demote any existing class teacher in this classroom
            DB::table('classroom_teacher')
                ->where('classroom_id', $classroomId)
                ->where('is_class_teacher', 1)
                ->update(['is_class_teacher' => 0]);

            if ($alreadyHere) {
                DB::table('classroom_teacher')
                    ->where('teacher_id', $teacherId)
                    ->where('classroom_id', $classroomId)
                    ->update(['is_class_teacher' => 1, 'updated_at' => now()]);
            } else {
                DB::table('classroom_teacher')->insert([
                    'classroom_id'    => $classroomId,
                    'teacher_id'      => $teacherId,
                    'is_class_teacher' => 1,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();

            if ($e->getCode() == 23000) {
                return response()->json(['status' => 'error', 'message' => 'This teacher is already assigned to this classroom.'], 422);
            }

            return response()->json(['status' => 'error', 'message' => 'Failed to assign class teacher.', 'error' => $e->getMessage()], 500);
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'Class teacher assigned successfully.',
            // ✅ NEW: include combination in response
            'data'    => $classroom->load(['teachers.user', 'teachers.combination']),
        ]);
    }

    /**
     * DELETE /api/classrooms/{classroomId}/class-teacher
     * Remove the class teacher flag from all teachers in this classroom.
     */
    public function removeClassTeacher(Request $request, $classroomId): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        if ($this->checkSchoolStreamsEnabled($request))
            return response()->json(['message' => 'Your school has streams enabled. Class teachers should be removed from streams, not classrooms.'], 403);

        try {
            $classroom = Classroom::findOrFail($classroomId);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'No classroom found with the specified ID.'], 404);
        }

        if ($classroom->school_id !== $user->school_id)
            return response()->json(['message' => 'Unauthorized.'], 403);

        DB::table('classroom_teacher')
            ->where('classroom_id', $classroomId)
            ->where('is_class_teacher', 1)
            ->update(['is_class_teacher' => 0]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Class teacher removed successfully.',
            // ✅ NEW: include combination in response
            'data'    => $classroom->load(['teachers.user', 'teachers.combination']),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // BULK CLASSROOM ASSIGNMENT
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * POST /api/teachers/assign-to-multiple-classrooms
     *
     * Assign one teacher to multiple classrooms at once.
     * Validates total capacity before any insert.
     *
     * Body: { "teacher_id": 5, "classroom_ids": [1, 2, 3] }
     */
    public function assignToMultipleClassrooms(Request $request): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        if ($this->checkSchoolStreamsEnabled($request))
            return response()->json(['message' => 'Your school has streams enabled. Teachers should be assigned to streams, not classrooms.'], 403);

        $validated = $request->validate([
            'teacher_id'      => 'required|integer|exists:teachers,id',
            'classroom_ids'   => 'required|array',
            'classroom_ids.*' => 'integer|exists:classrooms,id',
        ]);

        $teacher = Teacher::find($validated['teacher_id']);
        if (!$teacher || $teacher->school_id !== $user->school_id) {
            return response()->json([
                'status'  => 'error',
                'message' => 'The teacher must belong to the same school.',
                'errors'  => ['teacher_id' => ['The selected teacher is invalid or does not belong to your school.']],
            ], 422);
        }

        $classrooms = Classroom::whereIn('id', $validated['classroom_ids'])->get();
        foreach ($classrooms as $classroom) {
            if ($classroom->school_id !== $user->school_id) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'All classrooms must belong to the same school.',
                    'errors'  => ['classroom_ids' => ['Classroom with ID ' . $classroom->id . ' does not belong to your school.']],
                ], 422);
            }
        }

        $teacherId        = $validated['teacher_id'];
        $currentCount     = $this->getTeacherClassCount($teacherId);
        $maxClasses       = $teacher->max_classes ?? 10;
        $availableSlots   = $maxClasses - $currentCount;

        if ($availableSlots <= 0) {
            return response()->json([
                'status'  => 'error',
                'message' => 'This teacher has reached their maximum number of classes.',
                'errors'  => ['teacher_id' => [
                    "Teacher is already assigned to {$currentCount} classes, " .
                    "which is the maximum allowed ({$maxClasses}).",
                ]],
            ], 422);
        }

        if (count($validated['classroom_ids']) > $availableSlots) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Cannot assign teacher to all requested classrooms.',
                'errors'  => ['classroom_ids' => [
                    "Teacher can only be assigned to {$availableSlots} more class(es), " .
                    "but " . count($validated['classroom_ids']) . " were requested.",
                ]],
            ], 422);
        }

        $assignedClassrooms = [];
        $skippedClassrooms  = [];

        DB::beginTransaction();
        try {
            foreach ($validated['classroom_ids'] as $classroomId) {
                if ($this->isTeacherInClassroom($teacherId, $classroomId)) {
                    $skippedClassrooms[] = $classroomId;
                    continue;
                }

                DB::table('classroom_teacher')->insert([
                    'classroom_id'    => $classroomId,
                    'teacher_id'      => $teacherId,
                    'is_class_teacher' => 0,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);

                $assignedClassrooms[] = $classroomId;
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => 'Failed to assign teacher to classrooms.', 'error' => $e->getMessage()], 500);
        }

        $message = 'Teacher assigned to classrooms successfully.';
        if (count($skippedClassrooms) > 0) {
            $message .= ' Some classrooms were skipped because the teacher is already assigned to them.';
        }

        return response()->json([
            'status'  => 'success',
            'message' => $message,
            'data'    => [
                'teacher_id'        => $teacherId,
                'combination'       => $teacher->combination?->name,
                'assigned_to'       => $assignedClassrooms,
                'already_assigned'  => $skippedClassrooms,
                'total_requested'   => count($validated['classroom_ids']),
                'newly_assigned'    => count($assignedClassrooms),
                'skipped'           => count($skippedClassrooms),
                'max_classes'       => $maxClasses,
                'current_class_count' => $currentCount + count($assignedClassrooms),
            ],
        ]);
    }

    /**
     * GET /api/teachers/{teacherId}/available-classrooms
     * Returns classrooms this teacher is NOT yet assigned to.
     */
    public function getAvailableClassroomsForTeacher(Request $request, $teacherId): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        if ($this->checkSchoolStreamsEnabled($request))
            return response()->json(['message' => 'Your school has streams enabled. Teachers are assigned to streams, not classrooms.'], 403);

        try {
            $teacher = Teacher::findOrFail($teacherId);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'No teacher found with the specified ID.'], 404);
        }

        if ($teacher->school_id !== $user->school_id)
            return response()->json(['message' => 'Unauthorized.'], 403);

        $currentCount = $this->getTeacherClassCount($teacherId);
        $maxClasses   = $teacher->max_classes ?? 10;

        $availableClassrooms = Classroom::where('school_id', $user->school_id)
            ->whereNotIn('id', fn($q) =>
                $q->select('classroom_id')
                  ->from('classroom_teacher')
                  ->where('teacher_id', $teacherId)
            )
            ->get();

        return response()->json([
            'status' => 'success',
            'data'   => [
                'teacher_id'          => (int) $teacherId,
                // ✅ NEW: surface combination name for context
                'combination'         => $teacher->combination?->name,
                'current_class_count' => $currentCount,
                'max_classes'         => $maxClasses,
                'available_slots'     => max(0, $maxClasses - $currentCount),
                'available_classrooms' => $availableClassrooms,
            ],
        ]);
    }
}