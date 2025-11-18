<?php

use App\Http\Controllers\AcademicYearController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClassroomController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SchoolController;
use App\Http\Controllers\StreamController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\SubjectController;
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

    // ---------------------
    // CLASSROOM ROUTES
    // ---------------------
    Route::apiResource('classrooms', ClassroomController::class);
    Route::get('/classrooms/{classroomId}/streams', [ClassroomController::class, 'getStreams']);
    Route::post('/classrooms/{classroomId}/streams', [ClassroomController::class, 'addStream']);

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
    
    // apiResource MUST come AFTER specific routes
    Route::apiResource('streams', StreamController::class);

    // ---------------------
    // SUBJECT ROUTES
    // ---------------------
    Route::apiResource('subjects', SubjectController::class);
    Route::post('/subjects/{subjectId}/assign-teacher', [SubjectController::class, 'assignToTeacher']);
    Route::delete('/subjects/{subjectId}/remove-teacher/{teacherId}', [SubjectController::class, 'removeFromTeacher']);
    Route::get('/subjects/{subjectId}/teachers', [SubjectController::class, 'getTeachersBySubject']);
    Route::get('/subjects/school/{schoolId}', [SubjectController::class, 'getSubjectsBySchool']);
    Route::post('/subjects/{subjectId}/assign-teachers', [SubjectController::class, 'assignMultipleTeachers']);
    Route::get('/subjects/teacher/{teacherId}', [SubjectController::class, 'getSubjectsByTeacher']);

    // ---------------------
    // TEACHER ROUTES
    // ---------------------
    // Specific routes FIRST (before apiResource)
    Route::get('/teachers/class-teachers', [TeacherController::class, 'getAllClassTeachers']);
    Route::get('/teachers/school/{schoolId}', [TeacherController::class, 'getTeachersBySchool']);
    Route::get('/teachers/stream/{streamId}', [TeacherController::class, 'getTeachersByStream']);
    Route::get('/teachers/{teacherId}/classrooms', [TeacherController::class, 'getClassrooms']);
    Route::get('/teachers/{teacherId}/streams-as-class-teacher', [TeacherController::class, 'getStreamsAsClassTeacher']);
    Route::get('/teachers/{teacherId}/streams-as-teacher', [TeacherController::class, 'getStreamsAsTeacher']);

    // POST routes
    Route::post('/teachers/{teacherId}/assign-subjects', [TeacherController::class, 'assignSubjects']);
    Route::post('/teachers/{teacherId}/assign-stream', [TeacherController::class, 'assignToStream']);

    // apiResource LAST
    Route::apiResource('teachers', TeacherController::class);

    // ---------------------
    // STUDENT ROUTES
    // ---------------------
    Route::apiResource('students', StudentController::class);
    Route::get('get-students', [StudentController::class, 'getStudents']);
    Route::get('class-students/{classId}', [StudentController::class, 'getClassStudents']);
    Route::get('get-parents', [StudentController::class, 'getParentsForMySchool']);
});