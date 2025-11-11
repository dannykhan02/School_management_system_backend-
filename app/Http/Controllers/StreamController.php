<?php

namespace App\Http\Controllers;

use App\Models\Stream;
use App\Models\Classroom;
use App\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StreamController extends Controller
{
    // GET all streams for user's school
    public function index()
    {
        $user = auth()->user();

        if (!$user || !$user->school_id) {
            return response()->json(['message' => 'No school found for this user'], 404);
        }

        $streams = Stream::with(['school', 'classroom', 'classTeacher', 'teachers'])
                         ->where('school_id', $user->school_id)
                         ->get();

        return response()->json([
            'message' => 'Streams fetched successfully',
            'data' => $streams
        ]);
    }

    // GET one stream by ID
    public function show($id)
    {
        $user = auth()->user();

        $stream = Stream::with(['school', 'classroom', 'classTeacher', 'teachers'])
                        ->where('id', $id)
                        ->where('school_id', $user->school_id)
                        ->first();

        if (!$stream) {
            return response()->json(['message' => 'Stream not found or unauthorized'], 404);
        }

        return response()->json($stream);
    }

    // POST - Create a new stream
    public function store(Request $request)
    {
        $user = auth()->user();

        if (!$user || !$user->school_id) {
            return response()->json(['message' => 'No school found for this user'], 404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $validated['school_id'] = $user->school_id;

        $stream = Stream::create($validated);

        return response()->json([
            'message' => 'Stream created successfully',
            'data' => $stream->load(['school', 'classroom'])
        ], 201);
    }

    // PUT - Update a stream
    public function update(Request $request, $id)
    {
        $user = auth()->user();
        $stream = Stream::where('id', $id)
                        ->where('school_id', $user->school_id)
                        ->first();

        if (!$stream) {
            return response()->json(['message' => 'Stream not found or unauthorized'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
        ]);

        $stream->update($validated);
        
        return response()->json([
            'message' => 'Stream updated successfully',
            'data' => $stream->load(['school', 'classroom'])
        ]);
    }

    // DELETE - Remove a stream
    public function destroy($id)
    {
        $user = auth()->user();
        $stream = Stream::where('id', $id)
                        ->where('school_id', $user->school_id)
                        ->first();

        if (!$stream) {
            return response()->json(['message' => 'Stream not found or unauthorized'], 404);
        }

        $stream->delete();

        return response()->json(['message' => 'Stream deleted successfully']);
    }

    // GET streams by classroom
    public function getStreamsByClassroom($classroomId)
    {
        $classroom = Classroom::find($classroomId);
        if (!$classroom) {
            return response()->json(['message' => 'Classroom not found'], 404);
        }

        $streams = Stream::where('class_id', $classroomId)
            ->with(['school', 'classTeacher', 'teachers'])
            ->get();

        return response()->json($streams);
    }

    // Assign a teacher as class teacher to a stream
    public function assignClassTeacher(Request $request, $streamId)
    {
        $stream = Stream::find($streamId);
        if (!$stream) {
            return response()->json(['message' => 'Stream not found'], 404);
        }

        $validated = $request->validate([
            'teacher_id' => 'required|exists:teachers,id',
        ]);

        $teacher = Teacher::find($validated['teacher_id']);
        if (!$teacher) {
            return response()->json(['message' => 'Teacher not found'], 404);
        }

        // Verify teacher belongs to the same school as the stream
        if ($teacher->school_id !== $stream->school_id) {
            return response()->json([
                'message' => 'Teacher and stream must belong to the same school'
            ], 422);
        }

        $stream->class_teacher_id = $teacher->id;
        $stream->save();

        return response()->json([
            'message' => 'Class teacher assigned successfully',
            'data' => $stream->load('classTeacher')
        ]);
    }

    // Assign teachers to teach a stream
    public function assignTeachers(Request $request, $streamId)
    {
        $stream = Stream::find($streamId);
        if (!$stream) {
            return response()->json(['message' => 'Stream not found'], 404);
        }

        $validated = $request->validate([
            'teacher_ids' => 'required|array',
            'teacher_ids.*' => 'exists:teachers,id',
        ]);

        // Verify all teachers belong to the same school as the stream
        $teachers = Teacher::whereIn('id', $validated['teacher_ids'])->get();
        foreach ($teachers as $teacher) {
            if ($teacher->school_id !== $stream->school_id) {
                return response()->json([
                    'message' => 'All teachers must belong to the same school as the stream'
                ], 422);
            }
        }

        $stream->teachers()->sync($validated['teacher_ids']);

        return response()->json([
            'message' => 'Teachers assigned to stream successfully',
            'data' => $stream->load('teachers')
        ]);
    }

    // Get all class teachers with their streams and classrooms
    public function getAllClassTeachers()
    {
        $streams = Stream::whereNotNull('class_teacher_id')
            ->with(['classTeacher', 'classroom'])
            ->get();

        return response()->json($streams);
    }

    // Get all teachers teaching a specific stream
    public function getTeachersByStream($streamId)
    {
        $stream = Stream::with(['teachers', 'classroom'])->find($streamId);
        if (!$stream) {
            return response()->json(['message' => 'Stream not found'], 404);
        }

        return response()->json([
            'stream' => $stream,
            'teachers' => $stream->teachers
        ]);
    }
}