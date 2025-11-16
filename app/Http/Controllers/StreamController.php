<?php

namespace App\Http\Controllers;

use App\Models\Stream;
use App\Models\Classroom;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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
     * GET all streams for user's school
     */
    public function index(Request $request)
    {
        $user = $this->getUser($request);
        if (!$user || !$user->school_id) {
            return response()->json(['message' => 'Unauthorized. Provide school_id for testing.'], 401);
        }

        $streams = Stream::with(['school', 'classroom', 'classTeacher', 'teachers'])
            ->where('school_id', $user->school_id)
            ->get();

        return response()->json([
            'message' => 'Streams fetched successfully',
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
            return response()->json(['message' => 'Unauthorized. Provide school_id for testing.'], 401);
        }

        $stream = Stream::with(['school', 'classroom', 'classTeacher', 'teachers'])
            ->where('id', $id)
            ->where('school_id', $user->school_id)
            ->first();

        if (!$stream) {
            return response()->json(['message' => 'Stream not found or unauthorized'], 404);
        }

        return response()->json($stream);
    }

    /**
     * POST - Create a new stream
     */
    public function store(Request $request)
    {
        $user = $this->getUser($request);
        if (!$user || !$user->school_id) {
            return response()->json(['message' => 'Unauthorized. Provide school_id for testing.'], 401);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'class_id' => 'nullable|exists:classrooms,id',
        ]);

        // Verify classroom belongs to same school
        if (isset($validated['class_id'])) {
            $classroom = Classroom::find($validated['class_id']);
            if (!$classroom || $classroom->school_id !== $user->school_id) {
                return response()->json([
                    'message' => 'Invalid classroom: it must belong to your school.'
                ], 422);
            }
        }

        $validated['school_id'] = $user->school_id;

        $stream = Stream::create($validated);

        return response()->json([
            'message' => 'Stream created successfully',
            'data' => $stream->load(['school', 'classroom'])
        ], 201);
    }

    /**
     * PUT - Update a stream
     */
    public function update(Request $request, $id)
    {
        $user = $this->getUser($request);
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Provide school_id for testing.'], 401);
        }

        $stream = Stream::where('id', $id)
            ->where('school_id', $user->school_id)
            ->first();

        if (!$stream) {
            return response()->json(['message' => 'Stream not found or unauthorized'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'class_id' => 'nullable|exists:classrooms,id',
        ]);

        // Optional: verify new class belongs to same school
        if (isset($validated['class_id'])) {
            $classroom = Classroom::find($validated['class_id']);
            if (!$classroom || $classroom->school_id !== $user->school_id) {
                return response()->json([
                    'message' => 'Invalid classroom: must belong to same school.'
                ], 422);
            }
        }

        $stream->update($validated);

        return response()->json([
            'message' => 'Stream updated successfully',
            'data' => $stream->load(['school', 'classroom'])
        ]);
    }

    /**
     * DELETE - Remove a stream
     */
    public function destroy(Request $request, $id)
    {
        $user = $this->getUser($request);
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Provide school_id for testing.'], 401);
        }

        $stream = Stream::where('id', $id)
            ->where('school_id', $user->school_id)
            ->first();

        if (!$stream) {
            return response()->json(['message' => 'Stream not found or unauthorized'], 404);
        }

        $stream->delete();

        return response()->json(['message' => 'Stream deleted successfully']);
    }

    /**
     * GET - Streams by Classroom
     */
    public function getStreamsByClassroom(Request $request, $classroomId)
    {
        $user = $this->getUser($request);
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Provide school_id for testing.'], 401);
        }

        $classroom = Classroom::find($classroomId);
        if (!$classroom || $classroom->school_id !== $user->school_id) {
            return response()->json(['message' => 'Classroom not found or unauthorized'], 404);
        }

        $streams = Stream::where('class_id', $classroomId)
            ->with(['school', 'classTeacher', 'teachers'])
            ->get();

        return response()->json([
            'message' => 'Streams for classroom fetched successfully',
            'data' => $streams
        ]);
    }

    /**
     * Assign class teacher to a stream
     */
    public function assignClassTeacher(Request $request, $streamId)
    {
        $user = $this->getUser($request);
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Provide school_id for testing.'], 401);
        }

        $stream = Stream::where('id', $streamId)
            ->where('school_id', $user->school_id)
            ->first();

        if (!$stream) {
            return response()->json(['message' => 'Stream not found or unauthorized'], 404);
        }

        $validated = $request->validate([
            'teacher_id' => 'required|exists:teachers,id',
        ]);

        $teacher = Teacher::find($validated['teacher_id']);
        if (!$teacher || $teacher->school_id !== $user->school_id) {
            return response()->json([
                'message' => 'Teacher must belong to the same school as the stream.'
            ], 422);
        }

        $stream->class_teacher_id = $teacher->id;
        $stream->save();

        return response()->json([
            'message' => 'Class teacher assigned successfully',
            'data' => $stream->load(['classTeacher'])
        ]);
    }

    /**
     * Assign multiple teachers to a stream
     */
    public function assignTeachers(Request $request, $streamId)
    {
        $user = $this->getUser($request);
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Provide school_id for testing.'], 401);
        }

        $stream = Stream::where('id', $streamId)
            ->where('school_id', $user->school_id)
            ->first();

        if (!$stream) {
            return response()->json(['message' => 'Stream not found or unauthorized'], 404);
        }

        $validated = $request->validate([
            'teacher_ids' => 'required|array',
            'teacher_ids.*' => 'exists:teachers,id',
        ]);

        $teachers = Teacher::whereIn('id', $validated['teacher_ids'])->get();

        foreach ($teachers as $teacher) {
            if ($teacher->school_id !== $user->school_id) {
                return response()->json([
                    'message' => 'All teachers must belong to the same school as the stream.'
                ], 422);
            }
        }

        $stream->teachers()->sync($validated['teacher_ids']);

        return response()->json([
            'message' => 'Teachers assigned successfully',
            'data' => $stream->load('teachers')
        ]);
    }

    /**
     * Get all class teachers with their streams and classrooms
     */
    public function getAllClassTeachers(Request $request)
    {
        $user = $this->getUser($request);
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Provide school_id for testing.'], 401);
        }

        $streams = Stream::where('school_id', $user->school_id)
            ->whereNotNull('class_teacher_id')
            ->with(['classTeacher', 'classroom'])
            ->get();

        return response()->json([
            'message' => 'Class teachers fetched successfully',
            'data' => $streams
        ]);
    }

    /**
     * Get all teachers teaching a specific stream
     */
    public function getTeachersByStream(Request $request, $streamId)
    {
        $user = $this->getUser($request);
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Provide school_id for testing.'], 401);
        }

        $stream = Stream::with(['teachers', 'classroom'])
            ->where('id', $streamId)
            ->where('school_id', $user->school_id)
            ->first();

        if (!$stream) {
            return response()->json(['message' => 'Stream not found or unauthorized'], 404);
        }

        return response()->json([
            'stream' => $stream,
            'teachers' => $stream->teachers
        ]);
    }
}
