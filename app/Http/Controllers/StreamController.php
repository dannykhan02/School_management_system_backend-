<?php

namespace App\Http\Controllers;

use App\Models\Stream;
use App\Models\Classroom;
use App\Models\Teacher;
use App\Models\User;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;

class StreamController extends Controller
{
    // ──────────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Get authenticated user, with Postman fallback via school_id.
     */
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
     * Check if a teacher is already a class teacher for another stream.
     *
     * Refactored from the original: no longer passes $request through — takes
     * the resolved $schoolId directly, which is cleaner and avoids re-resolving
     * the user on every call.
     *
     * @param  int       $teacherId
     * @param  int       $schoolId
     * @param  int|null  $excludeStreamId  Exclude this stream (for update checks)
     * @return array     ['valid' => bool, 'message' => string, 'error' => string, 'existing_stream' => string]
     */
    private function validateClassTeacherAssignment(int $teacherId, int $schoolId, ?int $excludeStreamId = null): array
    {
        $query = Stream::where('class_teacher_id', $teacherId)
                       ->where('school_id', $schoolId);

        if ($excludeStreamId) {
            $query->where('id', '!=', $excludeStreamId);
        }

        $existing = $query->first();

        if ($existing) {
            return [
                'valid'           => false,
                'message'         => 'This teacher is already assigned as a class teacher to another stream.',
                'error'           => 'A teacher can only be a class teacher for one stream.',
                'existing_stream' => $existing->name,
            ];
        }

        return ['valid' => true];
    }

    /**
     * Standard set of relations to eager-load on a stream response.
     * ✅ NEW: includes 'combination' on both classTeacher and all teachers so
     * the frontend can display the teacher's B.Ed combination profile without
     * additional requests.
     */
    private function streamRelations(): array
    {
        return [
            'school',
            'classroom',
            'classTeacher.user',
            'classTeacher.combination',
            'teachers.user',
            'teachers.combination',
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // CRUD
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * GET /api/streams
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        if (!$user->school_id) return response()->json(['message' => 'User is not associated with any school.'], 400);

        if (!$this->checkSchoolStreamsEnabled($request))
            return response()->json(['message' => 'Your school does not have streams enabled.'], 403);

        $streams = Stream::with($this->streamRelations())
                         ->where('school_id', $user->school_id)
                         ->get();

        return response()->json(['status' => 'success', 'data' => $streams]);
    }

    /**
     * GET /api/streams/{id}
     */
    public function show(Request $request, $id): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        if (!$this->checkSchoolStreamsEnabled($request))
            return response()->json(['message' => 'Your school does not have streams enabled.'], 403);

        try {
            $stream = Stream::with($this->streamRelations())->findOrFail($id);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'No stream found with the specified ID.'], 404);
        }

        if ($stream->school_id !== $user->school_id)
            return response()->json(['message' => 'Unauthorized access to this stream.'], 403);

        return response()->json(['status' => 'success', 'data' => $stream]);
    }

    /**
     * POST /api/streams
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        if (!$user->school_id) return response()->json(['message' => 'User is not associated with any school.'], 400);

        if (!$this->checkSchoolStreamsEnabled($request))
            return response()->json(['message' => 'Your school does not have streams enabled.'], 403);

        Log::info('Stream Creation Request', ['user_id' => $user->id, 'school_id' => $user->school_id, 'request_data' => $request->all()]);

        $validated = $request->validate([
            'name'             => 'required|string|max:255',
            'class_id'         => 'required|integer',
            'capacity'         => 'required|integer|min:1',
            'class_teacher_id' => 'nullable|integer',
        ]);

        $classroom = Classroom::find($validated['class_id']);
        if (!$classroom || $classroom->school_id !== $user->school_id) {
            return response()->json([
                'status'  => 'error',
                'message' => 'The classroom must belong to the same school.',
                'errors'  => ['class_id' => ['The selected classroom is invalid or does not belong to your school.']],
            ], 422);
        }

        if (!empty($validated['class_teacher_id'])) {
            $teacher = Teacher::find($validated['class_teacher_id']);
            if (!$teacher || $teacher->school_id !== $user->school_id) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'The teacher must belong to the same school.',
                    'errors'  => ['class_teacher_id' => ['The selected teacher is invalid or does not belong to your school.']],
                ], 422);
            }

            // ✅ REFACTORED: pass schoolId directly instead of $request
            $validation = $this->validateClassTeacherAssignment($validated['class_teacher_id'], $user->school_id);
            if (!$validation['valid']) {
                return response()->json([
                    'status'          => 'error',
                    'message'         => $validation['message'],
                    'errors'          => ['class_teacher_id' => [$validation['error']]],
                    'existing_stream' => $validation['existing_stream'],
                ], 422);
            }
        }

        $stream = Stream::create([
            'name'             => $validated['name'],
            'capacity'         => $validated['capacity'],
            'class_teacher_id' => $validated['class_teacher_id'] ?? null,
            'class_id'         => $validated['class_id'],
            'school_id'        => $user->school_id,
        ]);

        // Auto-add class teacher to teaching staff pivot
        if (!empty($validated['class_teacher_id'])) {
            $stream->teachers()->attach($validated['class_teacher_id']);
        }

        Log::info('Stream created successfully', ['stream_id' => $stream->id, 'stream_data' => $validated]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Stream created successfully.',
            'data'    => $stream->load($this->streamRelations()),
        ], 201);
    }

    /**
     * PUT/PATCH /api/streams/{id}
     */
    public function update(Request $request, $id): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        if (!$this->checkSchoolStreamsEnabled($request))
            return response()->json(['message' => 'Your school does not have streams enabled.'], 403);

        try {
            $stream = Stream::findOrFail($id);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'No stream found with the specified ID.'], 404);
        }

        if ($stream->school_id !== $user->school_id)
            return response()->json(['message' => 'Unauthorized. This stream does not belong to your school.'], 403);

        Log::info('Stream Update Request', ['stream_id' => $id, 'request_data' => $request->all()]);

        $validated = $request->validate([
            'name'             => 'sometimes|required|string|max:255',
            'class_id'         => 'sometimes|required|integer',
            'capacity'         => 'sometimes|required|integer|min:1',
            'class_teacher_id' => 'nullable|integer',
        ]);

        if (isset($validated['class_id'])) {
            $classroom = Classroom::find($validated['class_id']);
            if (!$classroom || $classroom->school_id !== $user->school_id) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'The classroom must belong to the same school.',
                    'errors'  => ['class_id' => ['The selected classroom is invalid or does not belong to your school.']],
                ], 422);
            }
        }

        if (isset($validated['class_teacher_id']) && !empty($validated['class_teacher_id'])) {
            $teacher = Teacher::find($validated['class_teacher_id']);
            if (!$teacher || $teacher->school_id !== $user->school_id) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'The teacher must belong to the same school.',
                    'errors'  => ['class_teacher_id' => ['The selected teacher is invalid or does not belong to your school.']],
                ], 422);
            }

            // ✅ REFACTORED: pass schoolId + excludeStreamId directly
            $validation = $this->validateClassTeacherAssignment($validated['class_teacher_id'], $user->school_id, $id);
            if (!$validation['valid']) {
                return response()->json([
                    'status'          => 'error',
                    'message'         => $validation['message'],
                    'errors'          => ['class_teacher_id' => [$validation['error']]],
                    'existing_stream' => $validation['existing_stream'],
                ], 422);
            }
        }

        $oldClassTeacherId   = $stream->class_teacher_id;
        $classTeacherChanged = array_key_exists('class_teacher_id', $validated)
            && $validated['class_teacher_id'] != $oldClassTeacherId;

        $stream->update($validated);

        // Sync class teacher in the teaching staff pivot
        if ($classTeacherChanged) {
            if ($oldClassTeacherId) {
                $stream->teachers()->detach($oldClassTeacherId);
            }
            if (!empty($validated['class_teacher_id'])) {
                $stream->teachers()->syncWithoutDetaching([$validated['class_teacher_id']]);
            }
        }

        Log::info('Stream updated successfully', ['stream_id' => $stream->id, 'class_teacher_changed' => $classTeacherChanged]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Stream updated successfully.',
            'data'    => $stream->load($this->streamRelations()),
        ]);
    }

    /**
     * DELETE /api/streams/{id}
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        if (!$this->checkSchoolStreamsEnabled($request))
            return response()->json(['message' => 'Your school does not have streams enabled.'], 403);

        try {
            $stream = Stream::findOrFail($id);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'No stream found with the specified ID.'], 404);
        }

        if ($stream->school_id !== $user->school_id)
            return response()->json(['message' => 'Unauthorized. This stream does not belong to your school.'], 403);

        $stream->delete();

        Log::info('Stream deleted successfully', ['stream_id' => $id]);

        return response()->json(['status' => 'success', 'message' => 'Stream deleted successfully.']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // STREAM LOOKUPS
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * GET /api/streams/classroom/{classroomId}
     */
    public function getStreamsByClassroom(Request $request, $classroomId): JsonResponse
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

        $streams = Stream::where('class_id', $classroomId)
            ->with($this->streamRelations())
            ->get();

        return response()->json([
            'status'    => 'success',
            'classroom' => $classroom,
            'streams'   => $streams,
        ]);
    }

    /**
     * GET /api/streams/{streamId}/teachers
     */
    public function getStreamTeachers(Request $request, $streamId): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        if (!$this->checkSchoolStreamsEnabled($request))
            return response()->json(['message' => 'Your school does not have streams enabled.'], 403);

        try {
            $stream = Stream::with([
                'teachers.user',
                'teachers.combination',
                'classroom',
                'classTeacher.user',
                'classTeacher.combination',
            ])->findOrFail($streamId);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'No stream found with the specified ID.'], 404);
        }

        if ($stream->school_id !== $user->school_id)
            return response()->json(['message' => 'Unauthorized.'], 403);

        return response()->json([
            'status'   => 'success',
            'stream'   => $stream,
            'teachers' => $stream->teachers,
        ]);
    }

    /**
     * GET /api/streams/class-teachers
     * All streams that have a class teacher assigned.
     */
    public function getAllClassTeachers(Request $request): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        if (!$user->school_id) return response()->json(['message' => 'User is not associated with any school.'], 400);

        if (!$this->checkSchoolStreamsEnabled($request))
            return response()->json(['message' => 'Your school does not have streams enabled.'], 403);

        $streams = Stream::with(['classroom', 'classTeacher.user', 'classTeacher.combination', 'school'])
                         ->where('school_id', $user->school_id)
                         ->whereNotNull('class_teacher_id')
                         ->get();

        return response()->json(['status' => 'success', 'data' => $streams]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // CLASS TEACHER MANAGEMENT
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * POST /api/streams/{streamId}/assign-class-teacher
     */
    public function assignClassTeacher(Request $request, $streamId): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        if (!$this->checkSchoolStreamsEnabled($request))
            return response()->json(['message' => 'Your school does not have streams enabled.'], 403);

        try {
            $stream = Stream::findOrFail($streamId);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'No stream found with the specified ID.'], 404);
        }

        if ($stream->school_id !== $user->school_id)
            return response()->json(['message' => 'Unauthorized. You cannot modify a stream from another school.'], 403);

        $validated = $request->validate(['teacher_id' => 'required|integer']);

        $teacher = Teacher::find($validated['teacher_id']);
        if (!$teacher || $teacher->school_id !== $stream->school_id) {
            return response()->json([
                'status'  => 'error',
                'message' => 'The teacher must belong to the same school.',
                'errors'  => ['teacher_id' => ['The selected teacher is invalid or does not belong to the same school.']],
            ], 422);
        }

        // ✅ REFACTORED: pass schoolId directly
        $validation = $this->validateClassTeacherAssignment($validated['teacher_id'], $user->school_id, $streamId);
        if (!$validation['valid']) {
            return response()->json([
                'status'          => 'error',
                'message'         => $validation['message'],
                'errors'          => ['teacher_id' => [$validation['error']]],
                'existing_stream' => $validation['existing_stream'],
            ], 422);
        }

        $stream->class_teacher_id = $validated['teacher_id'];
        $stream->save();

        // Auto-add to teaching staff
        $stream->teachers()->syncWithoutDetaching([$validated['teacher_id']]);

        Log::info('Class teacher assigned successfully', ['stream_id' => $streamId, 'teacher_id' => $validated['teacher_id']]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Class teacher assigned successfully.',
            'data'    => $stream->load($this->streamRelations()),
        ]);
    }

    /**
     * DELETE /api/streams/{streamId}/remove-class-teacher
     *
     * Removes the class teacher FK from the stream.
     * Does NOT remove the teacher from teaching staff (they may still teach
     * other subjects in this stream) — admin must do that separately.
     */
    public function removeClassTeacher(Request $request, $streamId): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        if (!$this->checkSchoolStreamsEnabled($request))
            return response()->json(['message' => 'Your school does not have streams enabled.'], 403);

        try {
            $stream = Stream::findOrFail($streamId);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'No stream found with the specified ID.'], 404);
        }

        if ($stream->school_id !== $user->school_id)
            return response()->json(['message' => 'Unauthorized.'], 403);

        $removedTeacherId         = $stream->class_teacher_id;
        $stream->class_teacher_id = null;
        $stream->save();

        Log::info('Class teacher removed successfully', ['stream_id' => $streamId, 'removed_teacher_id' => $removedTeacherId]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Class teacher removed successfully.',
            'data'    => $stream->load($this->streamRelations()),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // TEACHING STAFF MANAGEMENT
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * POST /api/streams/{streamId}/assign-teachers
     *
     * Replace (sync) the teaching staff for a stream.
     * The class teacher is always preserved even if not in the submitted list.
     *
     * Body: { "teacher_ids": [1, 2, 3] }
     */
    public function assignTeachers(Request $request, $streamId): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized.'], 401);

        if (!$this->checkSchoolStreamsEnabled($request))
            return response()->json(['message' => 'Your school does not have streams enabled.'], 403);

        try {
            $stream = Stream::findOrFail($streamId);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'No stream found with the specified ID.'], 404);
        }

        if ($stream->school_id !== $user->school_id)
            return response()->json(['message' => 'Unauthorized.'], 403);

        $validated = $request->validate([
            'teacher_ids'   => 'required|array',
            'teacher_ids.*' => 'integer|exists:teachers,id',
        ]);

        $teachers = Teacher::whereIn('id', $validated['teacher_ids'])->get();
        foreach ($teachers as $teacher) {
            if ($teacher->school_id !== $user->school_id) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'All teachers must belong to the same school.',
                    'errors'  => ['teacher_ids' => ['Teacher with ID ' . $teacher->id . ' does not belong to your school.']],
                ], 422);
            }
        }

        // Always preserve the class teacher
        $teacherIds = $validated['teacher_ids'];
        if ($stream->class_teacher_id && !in_array($stream->class_teacher_id, $teacherIds)) {
            $teacherIds[] = $stream->class_teacher_id;
        }

        $stream->teachers()->sync($teacherIds);

        Log::info('Teaching staff assigned successfully', [
            'stream_id'                  => $streamId,
            'teacher_ids'                => $teacherIds,
            'class_teacher_auto_included' => $stream->class_teacher_id,
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Teaching staff updated successfully.',
            'data'    => $stream->load(['teachers.user', 'teachers.combination', 'classTeacher.user', 'classTeacher.combination']),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // BULK STREAM ASSIGNMENT
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * POST /api/teachers/assign-to-multiple-streams
     *
     * Assign one teacher to multiple streams as a regular teaching staff member.
     *
     * ✅ BUG FIX (original): No max_classes capacity check was performed, meaning
     * a teacher could be added to unlimited streams. Fixed: enforces max_classes
     * before any insert and returns clear capacity data in the response.
     *
     * Does NOT override existing class-teacher assignments — if the teacher IS
     * the class teacher for a requested stream, they are confirmed in the pivot
     * without duplication.
     *
     * Body: { "teacher_id": 5, "stream_ids": [1, 2, 3] }
     */
    public function assignToMultipleStreams(Request $request): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        if (!$this->checkSchoolStreamsEnabled($request))
            return response()->json(['message' => 'Your school does not have streams enabled.'], 403);

        $validated = $request->validate([
            'teacher_id'   => 'required|integer|exists:teachers,id',
            'stream_ids'   => 'required|array|min:1',
            'stream_ids.*' => 'integer|exists:streams,id',
        ]);

        $teacher = Teacher::find($validated['teacher_id']);
        if (!$teacher || $teacher->school_id !== $user->school_id) {
            return response()->json([
                'status'  => 'error',
                'message' => 'The teacher must belong to the same school.',
                'errors'  => ['teacher_id' => ['The selected teacher is invalid or does not belong to your school.']],
            ], 422);
        }

        $streams = Stream::whereIn('id', $validated['stream_ids'])->get()->keyBy('id');
        foreach ($streams as $stream) {
            if ($stream->school_id !== $user->school_id) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'All streams must belong to the same school.',
                    'errors'  => ['stream_ids' => ['Stream with ID ' . $stream->id . ' does not belong to your school.']],
                ], 422);
            }
        }

        $teacherId = $validated['teacher_id'];

        // ✅ BUG FIX: enforce max_classes (streams count toward the limit)
        $currentStreamCount = DB::table('stream_teacher')
                               ->where('teacher_id', $teacherId)
                               ->count();
        $maxClasses         = $teacher->max_classes ?? 10;

        // Which of the requested streams is the teacher NOT already in?
        $existingStreamIds = DB::table('stream_teacher')
                               ->where('teacher_id', $teacherId)
                               ->whereIn('stream_id', $validated['stream_ids'])
                               ->pluck('stream_id')
                               ->toArray();

        $newStreamIds     = array_values(array_diff($validated['stream_ids'], $existingStreamIds));
        $availableSlots   = $maxClasses - $currentStreamCount;

        if (count($newStreamIds) > $availableSlots) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Cannot assign teacher to all requested streams.',
                'errors'  => ['stream_ids' => [
                    "Teacher can only be assigned to {$availableSlots} more stream(s), " .
                    "but " . count($newStreamIds) . " new stream(s) were requested.",
                ]],
                'data'    => [
                    'current_count'    => $currentStreamCount,
                    'max_classes'      => $maxClasses,
                    'available_slots'  => $availableSlots,
                    'requested_new'    => count($newStreamIds),
                    'already_assigned' => $existingStreamIds,
                ],
            ], 422);
        }

        $assigned            = [];
        $skippedClassTeacher = [];

        foreach ($newStreamIds as $streamId) {
            $stream = $streams->get($streamId);
            if (!$stream) continue;

            // If teacher IS the class teacher for this stream, just ensure they're
            // in the teaching staff pivot (no duplication) and note it
            if ($stream->class_teacher_id == $teacherId) {
                $stream->teachers()->syncWithoutDetaching([$teacherId]);
                $skippedClassTeacher[] = $streamId;
                continue;
            }

            $stream->teachers()->syncWithoutDetaching([$teacherId]);
            $assigned[] = $streamId;
        }

        $message = 'Teacher assigned to multiple streams successfully.';
        if (!empty($existingStreamIds)) {
            $message .= ' Some streams were skipped (teacher already assigned).';
        }
        if (!empty($skippedClassTeacher)) {
            $message .= ' Class teacher streams were confirmed without duplication.';
        }

        Log::info('Teacher assigned to multiple streams', [
            'teacher_id'            => $teacherId,
            'newly_assigned'        => $assigned,
            'already_assigned'      => $existingStreamIds,
            'skipped_class_teacher' => $skippedClassTeacher,
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => $message,
            'data'    => [
                'teacher_id'            => $teacherId,
                'combination'           => $teacher->combination?->name,
                'stream_ids'            => $validated['stream_ids'],
                'newly_assigned'        => $assigned,
                'already_assigned'      => $existingStreamIds,
                'skipped_class_teacher' => $skippedClassTeacher,
                'total_requested'       => count($validated['stream_ids']),
                'newly_assigned_count'  => count($assigned),
                'new_total_count'       => $currentStreamCount + count($assigned),
                'max_classes'           => $maxClasses,
            ],
        ]);
    }
}