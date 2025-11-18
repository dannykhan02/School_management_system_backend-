<?php

namespace App\Http\Controllers;

use App\Models\Stream;
use App\Models\Classroom;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class StreamController extends Controller
{
    /**
     * Get the current user (supports Sanctum or manual testing).
     */
    private function getUser(Request $request)
    {
        $user = Auth::user();

        // Allow fallback for Postman testing without login
        if (!$user && $request->has('school_id')) {
            $user = User::where('school_id', $request->school_id)->first();
        }

        return $user;
    }

    /**
     * Helper to check if a teacher is already a class teacher for another stream.
     * Returns the existing stream if teacher is already assigned, null otherwise.
     */
    private function checkTeacherClassTeacherAssignment(Request $request, $teacherId, $currentStreamId = null)
    {
        $user = $this->getUser($request);
        if (!$user) {
            return null;
        }

        $query = Stream::where('class_teacher_id', $teacherId)
                       ->where('school_id', $user->school_id);

        // If we are updating an existing stream, exclude it from the check.
        // This allows re-assigning the same teacher or changing to a new one correctly.
        if ($currentStreamId) {
            $query->where('id', '!=', $currentStreamId);
        }

        return $query->first();
    }

    /**
     * Validate that a teacher can be assigned as class teacher
     */
    private function validateClassTeacherAssignment(Request $request, $teacherId, $currentStreamId = null)
    {
        $existingStream = $this->checkTeacherClassTeacherAssignment($request, $teacherId, $currentStreamId);
        
        if ($existingStream) {
            return [
                'valid' => false,
                'message' => 'This teacher is already assigned as a class teacher to another stream.',
                'error' => 'A teacher can only be a class teacher for one stream.',
                'existing_stream' => $existingStream->name
            ];
        }

        return ['valid' => true];
    }

    /**
     * GET all streams for user's school
     */
    public function index(Request $request)
    {
        $user = $this->getUser($request);
        
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        if (!$user->school_id) {
            return response()->json(['message' => 'User is not associated with any school.'], 400);
        }

        $streams = Stream::with(['school', 'classroom', 'classTeacher.user', 'teachers.user'])
            ->where('school_id', $user->school_id)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $streams
        ]);
    }

    /**
     * GET one stream by ID
     */
    public function show(Request $request, $id)
    {
        $user = $this->getUser($request);
        
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        try {
            $stream = Stream::with(['school', 'classroom', 'classTeacher.user', 'teachers.user'])->findOrFail($id);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'No stream found with the specified ID.'
            ], 404);
        }

        if ($stream->school_id !== $user->school_id) {
            return response()->json(['message' => 'Unauthorized access to this stream.'], 403);
        }

        return response()->json([
            'status' => 'success',
            'data' => $stream
        ]);
    }

    /**
     * POST - Create a new stream
     */
    public function store(Request $request)
    {
        $user = $this->getUser($request);
        
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        if (!$user->school_id) {
            return response()->json(['message' => 'User is not associated with any school.'], 400);
        }

        Log::info('Stream Creation Request', [
            'user_id' => $user->id,
            'school_id' => $user->school_id,
            'request_data' => $request->all()
        ]);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'class_id' => 'required|integer',
            'capacity' => 'required|integer|min:1',
            'class_teacher_id' => 'nullable|integer',
        ]);

        // Verify classroom exists and belongs to same school
        $classroom = Classroom::find($validated['class_id']);
        
        if (!$classroom || $classroom->school_id !== $user->school_id) {
            return response()->json([
                'status' => 'error',
                'message' => 'The classroom must belong to the same school.',
                'errors' => ['class_id' => ['The selected classroom is invalid or does not belong to your school.']]
            ], 422);
        }

        // If class_teacher_id is provided, verify the teacher exists and belongs to same school
        if (!empty($validated['class_teacher_id'])) {
            $teacher = Teacher::find($validated['class_teacher_id']);
            
            if (!$teacher || $teacher->school_id !== $user->school_id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'The teacher must belong to the same school.',
                    'errors' => ['class_teacher_id' => ['The selected teacher is invalid or does not belong to your school.']]
                ], 422);
            }

            // Check if teacher is already a class teacher for another stream
            $validation = $this->validateClassTeacherAssignment($request, $validated['class_teacher_id']);
            if (!$validation['valid']) {
                return response()->json([
                    'status' => 'error',
                    'message' => $validation['message'],
                    'errors' => ['class_teacher_id' => [$validation['error']]],
                    'existing_stream' => $validation['existing_stream']
                ], 422);
            }
        }

        $stream = Stream::create([
            'name' => $validated['name'],
            'capacity' => $validated['capacity'],
            'class_teacher_id' => $validated['class_teacher_id'] ?? null,
            'class_id' => $validated['class_id'],
            'school_id' => $user->school_id,
        ]);

        // If a class teacher is assigned, automatically add them to teaching staff
        if (!empty($validated['class_teacher_id'])) {
            $stream->teachers()->attach($validated['class_teacher_id']);
        }

        Log::info('Stream created successfully', [
            'stream_id' => $stream->id,
            'stream_data' => $validated
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Stream created successfully.',
            'data' => $stream->load(['school', 'classroom', 'classTeacher.user', 'teachers.user'])
        ], 201);
    }

    /**
     * PUT - Update a stream
     */
    public function update(Request $request, $id)
    {
        $user = $this->getUser($request);
        
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        try {
            $stream = Stream::findOrFail($id);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'No stream found with the specified ID.'
            ], 404);
        }

        if ($stream->school_id !== $user->school_id) {
            return response()->json(['message' => 'Unauthorized. This stream does not belong to your school.'], 403);
        }

        Log::info('Stream Update Request', [
            'stream_id' => $id,
            'request_data' => $request->all()
        ]);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'class_id' => 'sometimes|required|integer',
            'capacity' => 'sometimes|required|integer|min:1',
            'class_teacher_id' => 'nullable|integer',
        ]);

        if (isset($validated['class_id'])) {
            $classroom = Classroom::find($validated['class_id']);
            if (!$classroom || $classroom->school_id !== $user->school_id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'The classroom must belong to the same school.',
                    'errors' => ['class_id' => ['The selected classroom is invalid or does not belong to your school.']]
                ], 422);
            }
        }

        if (isset($validated['class_teacher_id']) && !empty($validated['class_teacher_id'])) {
            $teacher = Teacher::find($validated['class_teacher_id']);
            
            if (!$teacher || $teacher->school_id !== $user->school_id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'The teacher must belong to the same school.',
                    'errors' => ['class_teacher_id' => ['The selected teacher is invalid or does not belong to your school.']]
                ], 422);
            }

            // Check if teacher is already a class teacher for another stream
            $validation = $this->validateClassTeacherAssignment($request, $validated['class_teacher_id'], $id);
            if (!$validation['valid']) {
                return response()->json([
                    'status' => 'error',
                    'message' => $validation['message'],
                    'errors' => ['class_teacher_id' => [$validation['error']]],
                    'existing_stream' => $validation['existing_stream']
                ], 422);
            }
        }

        // Check if class_teacher_id is being changed
        $classTeacherChanged = isset($validated['class_teacher_id']) && 
                               $validated['class_teacher_id'] != $stream->class_teacher_id;
        
        $oldClassTeacherId = $stream->class_teacher_id;
        
        $stream->update($validated);

        // If class teacher was changed, update teaching staff accordingly
        if ($classTeacherChanged) {
            // Remove old class teacher from teaching staff if they were there
            if ($oldClassTeacherId) {
                $stream->teachers()->detach($oldClassTeacherId);
            }
            
            // Add new class teacher to teaching staff if assigned
            if (!empty($validated['class_teacher_id'])) {
                // Use syncWithoutDetaching to avoid removing other teachers
                $stream->teachers()->syncWithoutDetaching([$validated['class_teacher_id']]);
            }
        }

        Log::info('Stream updated successfully', [
            'stream_id' => $stream->id,
            'class_teacher_changed' => $classTeacherChanged
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Stream updated successfully.',
            'data' => $stream->load(['school', 'classroom', 'classTeacher.user', 'teachers.user'])
        ]);
    }

    /**
     * DELETE - Remove a stream
     */
    public function destroy(Request $request, $id)
    {
        $user = $this->getUser($request);
        
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        try {
            $stream = Stream::findOrFail($id);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'No stream found with the specified ID.'
            ], 404);
        }

        if ($stream->school_id !== $user->school_id) {
            return response()->json(['message' => 'Unauthorized. This stream does not belong to your school.'], 403);
        }

        $stream->delete();

        Log::info('Stream deleted successfully', [
            'stream_id' => $id
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Stream deleted successfully.'
        ]);
    }

    /**
     * GET streams by classroom
     */
    public function getStreamsByClassroom(Request $request, $classroomId)
    {
        $user = $this->getUser($request);
        
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        try {
            $classroom = Classroom::findOrFail($classroomId);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'No classroom found with the specified ID.'
            ], 404);
        }

        if ($classroom->school_id !== $user->school_id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $streams = Stream::where('class_id', $classroomId)
            ->with(['school', 'classTeacher.user', 'teachers.user'])
            ->get();

        return response()->json([
            'status' => 'success',
            'classroom' => $classroom,
            'streams' => $streams
        ]);
    }

    /**
     * GET teachers assigned to a specific stream
     */
    public function getStreamTeachers(Request $request, $streamId)
    {
        $user = $this->getUser($request);
        
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        try {
            $stream = Stream::with(['teachers.user', 'classroom', 'classTeacher.user'])
                ->findOrFail($streamId);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'No stream found with the specified ID.'
            ], 404);
        }

        if ($stream->school_id !== $user->school_id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return response()->json([
            'status' => 'success',
            'stream' => $stream,
            'teachers' => $stream->teachers
        ]);
    }

    /**
     * Assign class teacher to a stream
     */
    public function assignClassTeacher(Request $request, $streamId)
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
            return response()->json(['message' => 'Unauthorized. You cannot modify a stream from another school.'], 403);
        }

        $validated = $request->validate([
            'teacher_id' => 'required|integer',
        ]);

        $teacher = Teacher::find($validated['teacher_id']);
        
        if (!$teacher || $teacher->school_id !== $stream->school_id) {
            return response()->json([
                'status' => 'error',
                'message' => 'The teacher must belong to the same school.',
                'errors' => ['teacher_id' => ['The selected teacher is invalid or does not belong to the same school.']]
            ], 422);
        }
        
        // Check if teacher is already a class teacher for another stream
        $validation = $this->validateClassTeacherAssignment($request, $validated['teacher_id'], $streamId);
        if (!$validation['valid']) {
            return response()->json([
                'status' => 'error',
                'message' => $validation['message'],
                'errors' => ['teacher_id' => [$validation['error']]],
                'existing_stream' => $validation['existing_stream']
            ], 422);
        }

        $stream->class_teacher_id = $validated['teacher_id'];
        $stream->save();

        // Automatically add the class teacher to teaching staff
        $stream->teachers()->syncWithoutDetaching([$validated['teacher_id']]);

        Log::info('Class teacher assigned successfully', [
            'stream_id' => $streamId,
            'teacher_id' => $validated['teacher_id']
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Class teacher assigned successfully.',
            'data' => $stream->load(['school', 'classroom', 'classTeacher.user', 'teachers.user'])
        ]);
    }

    /**
     * Remove class teacher from a stream
     */
    public function removeClassTeacher(Request $request, $streamId)
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

        $oldClassTeacherId = $stream->class_teacher_id;
        
        $stream->class_teacher_id = null;
        $stream->save();

        // Optionally remove the old class teacher from teaching staff
        // Uncomment the line below if you want to remove them automatically
        // if ($oldClassTeacherId) {
        //     $stream->teachers()->detach($oldClassTeacherId);
        // }

        Log::info('Class teacher removed successfully', [
            'stream_id' => $streamId,
            'removed_teacher_id' => $oldClassTeacherId
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Class teacher removed successfully.',
            'data' => $stream->load(['school', 'classroom', 'classTeacher', 'teachers.user'])
        ]);
    }

    /**
     * GET all class teachers with their streams
     */
    public function getAllClassTeachers(Request $request)
    {
        $user = $this->getUser($request);
        
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        if (!$user->school_id) {
            return response()->json(['message' => 'User is not associated with any school.'], 400);
        }

        $streams = Stream::with(['classroom', 'classTeacher.user', 'school'])
            ->where('school_id', $user->school_id)
            ->whereNotNull('class_teacher_id')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $streams
        ]);
    }

    /**
     * Assign teaching staff to a stream
     */
    public function assignTeachers(Request $request, $streamId)
    {
        $user = $this->getUser($request);
        
        if (!$user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
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

        $validated = $request->validate([
            'teacher_ids' => 'required|array',
            'teacher_ids.*' => 'integer|exists:teachers,id',
        ]);

        // Verify all teachers belong to the same school
        $teachers = Teacher::whereIn('id', $validated['teacher_ids'])->get();
        foreach ($teachers as $teacher) {
            if ($teacher->school_id !== $user->school_id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'All teachers must belong to the same school.',
                    'errors' => ['teacher_ids' => ['Teacher with ID ' . $teacher->id . ' does not belong to your school.']]
                ], 422);
            }
        }

        // Ensure class teacher is always included in teaching staff
        $teacherIds = $validated['teacher_ids'];
        if ($stream->class_teacher_id && !in_array($stream->class_teacher_id, $teacherIds)) {
            $teacherIds[] = $stream->class_teacher_id;
        }

        // Sync teachers (attach new, detach removed, but keep class teacher)
        $stream->teachers()->sync($teacherIds);

        Log::info('Teaching staff assigned successfully', [
            'stream_id' => $streamId,
            'teacher_ids' => $teacherIds,
            'class_teacher_auto_included' => $stream->class_teacher_id
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Teaching staff updated successfully.',
            'data' => $stream->load(['teachers.user', 'classTeacher.user'])
        ]);
    }
}