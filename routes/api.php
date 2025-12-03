<?php

use App\Http\Controllers\AcademicYearController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClassroomController;
use App\Http\Controllers\ParentController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SchoolController;
use App\Http\Controllers\StreamController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\SubjectAssignmentController;
use App\Http\Controllers\TeacherController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// =====================
// PUBLIC ROUTES (No Authentication Required)
// =====================

// Login
Route::post('/auth/login', [AuthController::class, 'login']);

// Public school registration
Route::post('/schools', [SchoolController::class, 'store']);

// For debugging - can be removed in production
Route::get('/test', function() {
    return response()->json([
        'status' => 'API is working',
        'timestamp' => now()
    ]);
});

// =====================
// PROTECTED ROUTES (Require Authentication)
// =====================
Route::middleware('auth:sanctum')->group(function () {
    
    // ---------------------
    // AUTH & USER MANAGEMENT
    // ---------------------
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/user', [AuthController::class, 'user']);
    Route::post('/user/change-password', [AuthController::class, 'changePassword']);

    Route::apiResource('users', UserController::class);
    Route::apiResource('roles', RoleController::class);

    // ---------------------
    // SCHOOL MANAGEMENT
    // ---------------------
    Route::get('/schools/all', [SchoolController::class, 'index']);
    Route::get('/schools', [SchoolController::class, 'mySchool']);
    Route::put('/schools/{school}', [SchoolController::class, 'update']);
    Route::get('/schools/{school}', [SchoolController::class, 'show']);

    // Academic Years
    Route::apiResource('academic-years', AcademicYearController::class);
    // Add this route after the existing academic-years resource route
    Route::get('/academic-years/by-curriculum/{curriculum}', [AcademicYearController::class, 'getByCurriculum']);

    // ---------------------
    // CLASSROOM ROUTES
    // ---------------------
    Route::apiResource('classrooms', ClassroomController::class);
    
    // Stream-related classroom routes (for schools with streams)
    Route::get('/classrooms/{classroomId}/streams', [ClassroomController::class, 'getStreams']);
    Route::post('/classrooms/{classroomId}/streams', [ClassroomController::class, 'addStream']);
    
    // Teacher-related classroom routes (for schools without streams)
    Route::get('/classrooms/{classroomId}/teachers', [ClassroomController::class, 'getTeachers']);
    Route::post('/classrooms/{classroomId}/teachers', [ClassroomController::class, 'assignTeachers']);
    Route::post('/classrooms/{classroomId}/class-teacher', [ClassroomController::class, 'assignClassTeacher']);
    Route::delete('/classrooms/{classroomId}/class-teacher', [ClassroomController::class, 'removeClassTeacher']);
    
    // NEW: Assign a teacher to multiple classrooms
    Route::post('/teachers/assign-to-multiple-classrooms', [ClassroomController::class, 'assignToMultipleClassrooms']);
    
    // NEW: Get available classrooms for a teacher based on max_classes limit
    Route::get('/teachers/{teacherId}/available-classrooms', [ClassroomController::class, 'getAvailableClassroomsForTeacher']);

    // ---------------------
    // STREAM ROUTES
    // ---------------------
    // IMPORTANT: Specific routes must come BEFORE apiResource
    Route::get('/streams/class-teachers', [StreamController::class, 'getAllClassTeachers']);
    Route::get('/streams/classroom/{classroomId}', [StreamController::class, 'getStreamsByClassroom']);
    Route::get('/streams/{streamId}/teachers', [StreamController::class, 'getStreamTeachers']);
    Route::post('/streams/{streamId}/assign-class-teacher', [StreamController::class, 'assignClassTeacher']);
    Route::delete('/streams/{streamId}/remove-class-teacher', [StreamController::class, 'removeClassTeacher']);
    Route::post('/streams/{streamId}/assign-teachers', [StreamController::class, 'assignTeachers']);
    
    // NEW: Assign a teacher to multiple streams
    Route::post('/teachers/assign-to-multiple-streams', [StreamController::class, 'assignToMultipleStreams']);
    
    // apiResource MUST come AFTER specific routes
    Route::apiResource('streams', StreamController::class);

    // ---------------------
    // SUBJECT ASSIGNMENT ROUTES
    // ---------------------
    // Regular subject assignment routes
    Route::apiResource('subject-assignments', SubjectAssignmentController::class);
    
    // NEW: Batch assignment route for creating multiple assignments at once
    Route::post('/subject-assignments/batch', [SubjectAssignmentController::class, 'storeBatch']);

    // ---------------------
    // SUBJECT ROUTES
    // ---------------------
    Route::apiResource('subjects', SubjectController::class);

    // ---------------------
    // TEACHER ROUTES
    // ---------------------
    // Custom routes for teacher-specific operations
    Route::get('teachers/{teacherId}/assignments', [TeacherController::class, 'getAssignments']);
    Route::get('/teachers/class-teachers', [TeacherController::class, 'getAllClassTeachers']);
    Route::get('/teachers/school/{schoolId}', [TeacherController::class, 'getTeachersBySchool']);
    Route::get('/teachers/stream/{streamId}', [TeacherController::class, 'getTeachersByStream']);
    
    // Routes for schools without streams
    Route::get('/teachers/{teacherId}/classrooms', [TeacherController::class, 'getClassrooms']);
    Route::post('/teachers/{teacherId}/classrooms', [TeacherController::class, 'assignToClassroom']);
    Route::delete('/teachers/{teacherId}/classrooms/{classroomId}', [TeacherController::class, 'removeFromClassroom']);
    
    // Routes for schools with streams
    Route::get('/teachers/{teacherId}/streams-as-class-teacher', [TeacherController::class, 'getStreamsAsClassTeacher']);
    Route::get('/teachers/{teacherId}/streams-as-teacher', [TeacherController::class, 'getStreamsAsTeacher']);

    // apiResource LAST
    Route::apiResource('teachers', TeacherController::class);

    // ---------------------
    // PARENT ROUTES (NEW)
    // ---------------------
    // Custom routes for parent-specific operations
    Route::get('parents/{parentId}/students', [ParentController::class, 'getStudents']);
    Route::get('/parents/school/{schoolId}', [ParentController::class, 'getParentsBySchool']);

    // apiResource LAST
    Route::apiResource('parents', ParentController::class);

    // ---------------------
    // STUDENT ROUTES
    // ---------------------
    Route::apiResource('students', StudentController::class);
    Route::get('get-students', [StudentController::class, 'getStudents']);
    Route::get('class-students/{classId}', [StudentController::class, 'getClassStudents']);
    Route::get('get-parents', [StudentController::class, 'getParentsForMySchool']);
});