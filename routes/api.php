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

Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/schools', [SchoolController::class, 'store']);
Route::get('/schools/check-code-availability', [SchoolController::class, 'checkCodeAvailability']);

Route::get('/test', function() {
    return response()->json(['status' => 'API is working', 'timestamp' => now()]);
});

// =====================
// PROTECTED ROUTES (Redis Authentication)
// =====================
Route::middleware('auth.redis')->group(function () {

    // ---------------------
    // AUTH & USER MANAGEMENT
    // ---------------------
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/logout-all', [AuthController::class, 'logoutAll']);
    Route::get('/auth/user', [AuthController::class, 'user']);
    Route::get('/auth/active-sessions', [AuthController::class, 'activeSessions']);
    Route::post('/user/change-password', [AuthController::class, 'changePassword']);

    Route::put('/user/profile', [UserController::class, 'updateProfile']);
    Route::patch('/user/profile', [UserController::class, 'updateProfile']);

    Route::get('/users/super-admins', [UserController::class, 'getSuperAdmins']);
    Route::apiResource('users', UserController::class);
    Route::apiResource('roles', RoleController::class);

    // ---------------------
    // SCHOOL MANAGEMENT
    // ---------------------
    // ⚠️ Specific routes MUST come BEFORE {school} parameter routes

    Route::get('/schools/statistics', [SchoolController::class, 'statistics']);
    Route::get('/schools/cities', [SchoolController::class, 'getCities']);
    Route::get('/schools/all', [SchoolController::class, 'index']);
    Route::get('/schools/select-options', [SchoolController::class, 'getSchoolsForSelect']);
    Route::get('/schools/check-code-availability-auth', [SchoolController::class, 'checkCodeAvailability']);
    Route::get('/schools/my-school', [SchoolController::class, 'mySchool']);
    Route::get('/schools/{school}', [SchoolController::class, 'show']);
    Route::get('/schools/{school}/user-breakdown', [SchoolController::class, 'getUserBreakdown']);
    Route::put('/schools/{school}', [SchoolController::class, 'update']);
    Route::post('/schools/{school}', [SchoolController::class, 'update']);
    Route::put('/schools/{school}/super-admin-update', [SchoolController::class, 'updateBySuperAdmin']);
    Route::post('/schools/{school}/super-admin-update', [SchoolController::class, 'updateBySuperAdmin']);

    // ---------------------
    // ACADEMIC YEAR ROUTES
    // ---------------------
    // ⚠️ Specific routes MUST come BEFORE apiResource to avoid route swallowing

    Route::post('/academic-years/bulk', [AcademicYearController::class, 'storeBulk']);
    Route::get('/academic-years/by-curriculum/{curriculum}', [AcademicYearController::class, 'getByCurriculum']);
    Route::apiResource('academic-years', AcademicYearController::class);

    // ---------------------
    // CLASSROOM ROUTES
    // ---------------------
    Route::apiResource('classrooms', ClassroomController::class);
    Route::get('/classrooms/{classroomId}/streams', [ClassroomController::class, 'getStreams']);
    Route::post('/classrooms/{classroomId}/streams', [ClassroomController::class, 'addStream']);
    Route::get('/classrooms/{classroomId}/teachers', [ClassroomController::class, 'getTeachers']);
    Route::post('/classrooms/{classroomId}/teachers', [ClassroomController::class, 'assignTeachers']);
    Route::post('/classrooms/{classroomId}/class-teacher', [ClassroomController::class, 'assignClassTeacher']);
    Route::delete('/classrooms/{classroomId}/class-teacher', [ClassroomController::class, 'removeClassTeacher']);
    Route::post('/teachers/assign-to-multiple-classrooms', [ClassroomController::class, 'assignToMultipleClassrooms']);
    Route::get('/teachers/{teacherId}/available-classrooms', [ClassroomController::class, 'getAvailableClassroomsForTeacher']);

    // ---------------------
    // STREAM ROUTES
    // ---------------------
    Route::get('/streams/class-teachers', [StreamController::class, 'getAllClassTeachers']);
    Route::get('/streams/classroom/{classroomId}', [StreamController::class, 'getStreamsByClassroom']);
    Route::get('/streams/{streamId}/teachers', [StreamController::class, 'getStreamTeachers']);
    Route::post('/streams/{streamId}/assign-class-teacher', [StreamController::class, 'assignClassTeacher']);
    Route::delete('/streams/{streamId}/remove-class-teacher', [StreamController::class, 'removeClassTeacher']);
    Route::post('/streams/{streamId}/assign-teachers', [StreamController::class, 'assignTeachers']);
    Route::post('/teachers/assign-to-multiple-streams', [StreamController::class, 'assignToMultipleStreams']);
    Route::apiResource('streams', StreamController::class);

    // ---------------------
    // SUBJECT ASSIGNMENT ROUTES
    // ---------------------
    Route::post('/subject-assignments/batch', [SubjectAssignmentController::class, 'storeBatch']);
    Route::apiResource('subject-assignments', SubjectAssignmentController::class);

    // ---------------------
    // TEACHER ROUTES
    // ---------------------
    // ⚠️ IMPORTANT: Specific routes MUST come BEFORE {teacherId} parameter routes
    // ⚠️ IMPORTANT: Static segment routes MUST come BEFORE apiResource

    // 1. Static listing routes (no {teacherId} param)
    Route::get('/teachers/class-teachers', [TeacherController::class, 'getAllClassTeachers']);
    Route::get('/teachers/workload-report', [TeacherController::class, 'getWorkloadReport']);
    Route::get('/teachers/with-assignments', [TeacherController::class, 'getTeachersWithAssignments']);
    Route::get('/teachers/school/{schoolId}', [TeacherController::class, 'getTeachersBySchool']);
    Route::get('/teachers/stream/{streamId}', [TeacherController::class, 'getTeachersByStream']);

    // ✅ Teacher combinations endpoint for form dropdown
    Route::get('/teacher-combinations', [TeacherController::class, 'getCombinations']);

    // ✅ Add missing preview route
    Route::get('/teacher-combinations/{id}/preview', [TeacherController::class, 'previewCombination']);

    // 2. Routes with {teacherId} parameter
    Route::get('/teachers/{teacherId}/workload', [TeacherController::class, 'getWorkload']);
    Route::get('/teachers/{teacherId}/timetable-capacity', [TeacherController::class, 'getTimetableCapacity']);
    Route::post('/teachers/{teacherId}/validate-assignment', [TeacherController::class, 'validateAssignment']);
    Route::get('/teachers/{teacherId}/assignments', [TeacherController::class, 'getAssignments']);

    // Classroom management (non-stream schools)
    Route::get('/teachers/{teacherId}/classrooms', [TeacherController::class, 'getClassrooms']);
    Route::post('/teachers/{teacherId}/classrooms', [TeacherController::class, 'assignToClassroom']);
    Route::delete('/teachers/{teacherId}/classrooms/{classroomId}', [TeacherController::class, 'removeFromClassroom']);

    // Stream management (stream schools)
    Route::get('/teachers/{teacherId}/streams-as-class-teacher', [TeacherController::class, 'getStreamsAsClassTeacher']);
    Route::get('/teachers/{teacherId}/streams-as-teacher', [TeacherController::class, 'getStreamsAsTeacher']);

    // Subject combination management routes
    Route::get('/teachers/{teacherId}/subjects', [TeacherController::class, 'getSubjects']);
    Route::post('/teachers/{teacherId}/subjects', [TeacherController::class, 'addSubject']);
    Route::delete('/teachers/{teacherId}/subjects/{subjectId}', [TeacherController::class, 'removeSubject']);

    // 3. Standard CRUD (MUST be last to avoid swallowing named routes)
    Route::apiResource('teachers', TeacherController::class);

    // ---------------------
    // SUBJECT ROUTES
    // ---------------------
    // ⚠️ IMPORTANT: Specific routes MUST come BEFORE {subject} parameter routes

    // Subject filter for teacher form (must be BEFORE apiResource and BEFORE {subjectId} routes)
    Route::get('/subjects/filter', [TeacherController::class, 'filterSubjectsForTeacher']);

    // Existing subject-specific routes
    Route::get('/subjects/constants', [SubjectController::class, 'getConstants']);
    Route::get('/subjects/search', [SubjectController::class, 'searchSubjectByName']);
    Route::get('/subjects/by-learning-area', [SubjectController::class, 'getByLearningArea']);
    Route::get('/subjects/kicd-required/{level}', [SubjectController::class, 'getKICDRequired']);
    Route::get('/subjects/curriculum/{curriculum}/level/{level}', [SubjectController::class, 'getByCurriculumAndLevel']);
    Route::get('/subjects/by-school-level', [SubjectController::class, 'getBySchoolLevel']);

    // Individual subject endpoints (with {subjectId})
    Route::get('/subjects/{subjectId}/streams', [SubjectController::class, 'getStreams']);
    Route::post('/subjects/{subjectId}/streams', [SubjectController::class, 'assignToStreams']);
    Route::get('/subjects/{subjectId}/teachers', [SubjectController::class, 'getTeachers']);
    Route::post('/subjects/{subjectId}/teachers', [SubjectController::class, 'assignTeachers']);

    // Standard CRUD (MUST be last)
    Route::apiResource('subjects', SubjectController::class);

    // ---------------------
    // PARENT ROUTES
    // ---------------------
    Route::get('parents/{parentId}/students', [ParentController::class, 'getStudents']);
    Route::get('/parents/school/{schoolId}', [ParentController::class, 'getParentsBySchool']);
    Route::apiResource('parents', ParentController::class);

    // ---------------------
    // STUDENT ROUTES
    // ---------------------
    Route::apiResource('students', StudentController::class);
    Route::get('get-students', [StudentController::class, 'getStudents']);
    Route::get('class-students/{classId}', [StudentController::class, 'getClassStudents']);
    Route::get('get-parents', [StudentController::class, 'getParentsForMySchool']);
});