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

        $streams = Stream::with(['school', 'classroom', 'classTeacher', 'teachers'])
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
            $stream = Stream::with(['school', 'classroom', 'classTeacher', 'teachers'])->findOrFail($id);
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

        // Log the incoming request for debugging
        Log::info('Stream Creation Request', [
            'user_id' => $user->id,
            'school_id' => $user->school_id,
            'request_data' => $request->all()
        ]);

        // FIXED: Simplified validation rules to match the update method
        $rules = [
            'name' => 'required|string|max:255',
            'class_id' => 'required|integer',
            'class_teacher_id' => 'nullable|integer',
        ];

        try {
            $validated = $request->validate($rules);
        } catch (ValidationException $e) {
            Log::error('Validation failed for stream creation', [
                'errors' => $e->errors(),
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed. Please check your input.',
                'errors' => $e->errors()
            ], 422);
        }

        // Verify classroom exists and belongs to same school
        $classroom = Classroom::find($validated['class_id']);
        
        if (!$classroom) {
            return response()->json([
                'status' => 'error',
                'message' => 'The specified classroom does not exist.',
                'errors' => [
                    'class_id' => ['The selected classroom is invalid.']
                ]
            ], 422);
        }
        
        if ($classroom->school_id !== $user->school_id) {
            return response()->json([
                'status' => 'error',
                'message' => 'The selected classroom does not belong to your school.',
                'errors' => [
                    'class_id' => ['The classroom must belong to your school.']
                ]
            ], 422);
        }

        // Verify class teacher belongs to same school if provided
        if (isset($validated['class_teacher_id']) && !empty($validated['class_teacher_id'])) {
            $teacher = Teacher::find($validated['class_teacher_id']);
            
            if (!$teacher) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'The specified teacher does not exist.',
                    'errors' => [
                        'class_teacher_id' => ['The selected teacher is invalid.']
                    ]
                ], 422);
            }
            
            if ($teacher->school_id !== $user->school_id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'The class teacher must belong to the same school.',
                    'errors' => [
                        'class_teacher_id' => ['The teacher must belong to your school.']
                    ]
                ], 422);
            }
        }

        // Add school_id to validated data
        $validated['school_id'] = $user->school_id;

        // Remove class_teacher_id if it's empty/null
        if (empty($validated['class_teacher_id'])) {
            $validated['class_teacher_id'] = null;
        }

        Log::info('Creating stream with validated data', [
            'validated' => $validated
        ]);

        $stream = Stream::create($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Stream created successfully.',
            'data' => $stream->load(['school', 'classroom', 'classTeacher'])
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

        // Log incoming request for debugging
        Log::info('Stream Update Request', [
            'stream_id' => $id,
            'request_data' => $request->all(),
            'current_stream' => $stream->toArray()
        ]);

        // Improved validation logic
        $rules = [
            'name' => 'sometimes|required|string|max:255',
            'class_teacher_id' => 'nullable|integer',
        ];

        // Only validate class_id if it's being changed
        if ($request->has('class_id')) {
            if ($request->class_id === null || $request->class_id === '') {
                // Allow null/empty to keep existing value
                $rules['class_id'] = 'nullable';
            } else {
                // Validate if a new value is provided
                $rules['class_id'] = 'required|integer';
            }
        }

        try {
            $validated = $request->validate($rules);
        } catch (ValidationException $e) {
            Log::error('Validation failed for stream update', [
                'stream_id' => $id,
                'errors' => $e->errors(),
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ], 422);
        }

        // Verify new classroom belongs to same school (only if class_id is being changed and not null)
        if (isset($validated['class_id']) && !empty($validated['class_id'])) {
            $classroom = Classroom::find($validated['class_id']);
            
            if (!$classroom) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'The specified classroom does not exist.',
                    'errors' => [
                        'class_id' => ['The selected classroom is invalid.']
                    ]
                ], 422);
            }
            
            if ($classroom->school_id !== $stream->school_id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'The assigned classroom must belong to the same school.',
                    'errors' => [
                        'class_id' => ['The classroom must belong to your school.']
                    ]
                ], 422);
            }
        } else {
            // If class_id is null or not provided, keep the existing value
            unset($validated['class_id']);
        }

        // Verify class teacher belongs to same school if provided
        if (isset($validated['class_teacher_id']) && !empty($validated['class_teacher_id'])) {
            $teacher = Teacher::find($validated['class_teacher_id']);
            
            if (!$teacher) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'The specified teacher does not exist.',
                    'errors' => [
                        'class_teacher_id' => ['The selected teacher is invalid.']
                    ]
                ], 422);
            }
            
            if ($teacher->school_id !== $stream->school_id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'The class teacher must belong to the same school.',
                    'errors' => [
                        'class_teacher_id' => ['The teacher must belong to your school.']
                    ]
                ], 422);
            }
        }

        $stream->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Stream updated successfully.',
            'data' => $stream->load(['school', 'classroom', 'classTeacher'])
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

        return response()->json([
            'status' => 'success',
            'message' => 'Stream deleted successfully.'
        ]);
    }

    /**
     * GET - Streams by Classroom
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
            return response()->json(['message' => 'Unauthorized access to this classroom.'], 403);
        }

        $streams = Stream::where('class_id', $classroomId)
            ->with(['school', 'classTeacher', 'teachers'])
            ->get();

        return response()->json([
            'status' => 'success',
            'message' => 'Streams for classroom fetched successfully.',
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
        
        if (!$teacher) {
            return response()->json([
                'status' => 'error',
                'message' => 'No teacher found with the specified ID.'
            ], 404);
        }
        
        if ($teacher->school_id !== $stream->school_id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Teacher and stream must belong to the same school.'
            ], 422);
        }

        $stream->class_teacher_id = $teacher->id;
        $stream->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Class teacher assigned successfully.',
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
            'teacher_ids' => 'required|array',
            'teacher_ids.*' => 'integer',
        ]);

        // Verify all teachers belong to the same school
        $teachers = Teacher::whereIn('id', $validated['teacher_ids'])
            ->where('school_id', $stream->school_id)
            ->get();

        if ($teachers->count() !== count($validated['teacher_ids'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'One or more teachers do not belong to the same school or do not exist.'
            ], 422);
        }

        // Sync teachers to stream (assuming many-to-many relationship)
        $stream->teachers()->sync($validated['teacher_ids']);

        return response()->json([
            'status' => 'success',
            'message' => 'Teachers assigned successfully.',
            'data' => $stream->load(['teachers'])
        ]);
    }

    /**
     * GET teachers assigned to a stream
     */
    public function getStreamTeachers(Request $request, $streamId)
    {
        $user = $this->getUser($request);
        
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        try {
            $stream = Stream::with('teachers.user')->findOrFail($streamId);
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
            'teachers' => $stream->teachers
        ]);
    }

    /**
     * GET all streams with their class teachers
     */
    public function getClassTeachers(Request $request)
    {
        $user = $this->getUser($request);
        
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        if (!$user->school_id) {
            return response()->json(['message' => 'User is not associated with any school.'], 400);
        }

        $streams = Stream::with(['classroom', 'classTeacher.user'])
            ->where('school_id', $user->school_id)
            ->whereNotNull('class_teacher_id')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $streams
        ]);
    }
}