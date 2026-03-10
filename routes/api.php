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

// ── Enrollment system controllers ─────────────────────────────────────────────
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\Admin\EnrollmentController as AdminEnrollmentController;
use App\Http\Controllers\Admin\AdmissionConfigController;
use App\Http\Controllers\Admin\EnrollmentSettingController;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// =============================================================================
// PUBLIC ROUTES (No Authentication Required)
// =============================================================================

Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/schools', [SchoolController::class, 'store']);
Route::get('/schools/check-code-availability', [SchoolController::class, 'checkCodeAvailability']);

Route::get('/test', function () {
    return response()->json(['status' => 'API is working', 'timestamp' => now()]);
});

// ── Enrollment: check if school is accepting applications ─────────────────────
// Called by the frontend BEFORE showing the enrollment form — no login needed.
// GET /api/enrollment/status?school_id=1&academic_year_id=2
Route::get('/enrollment/status', [EnrollmentController::class, 'checkStatus']);

// ── Enrollment: unauthenticated application status tracking ───────────────────
// Parent clicks "Track My Application" from their confirmation email.
// No login required — verifies by enrollment ID + parent email together.
// Rate limited: 10 requests/minute per IP to prevent enumeration attacks.
// GET /api/enrollment/track?ref=1&email=parent@gmail.com
Route::middleware('throttle:10,1')->get('/enrollment/track', [EnrollmentController::class, 'track']);

// ── Enrollment: create application (public — parent has no account yet) ────────
// Parent fills the form on the school homepage without logging in.
// POST /api/enrollment
Route::post('/enrollment', [EnrollmentController::class, 'store']);

// ─────────────────────────────────────────────────────────────────────────────
// BULK ENROLLMENT — TEST / SEEDING ONLY
// ─────────────────────────────────────────────────────────────────────────────
// Inserts up to 500 students in one request. Bypasses the enrollment date-window
// check, skips email/SMS notifications, and sets status = submitted directly
// so all records appear immediately in the admin EnrollmentManager queue.
//
// ⚠️  Comment out this route once testing is complete.
//
// ⚠️  MUST be declared here — BEFORE the /{enrollment} wildcard routes inside
//     the auth.redis group below. If it were placed after those routes, Laravel
//     would try to resolve the literal string "bulk" as an Enrollment model ID
//     and return a 404 before this handler ever runs.
//
// POST /api/enrollment/bulk
// No auth required — same as the regular POST /api/enrollment.
Route::post('/enrollment/bulk', [EnrollmentController::class, 'bulkStore']);

// =============================================================================
// PROTECTED ROUTES (Redis Authentication)
// =============================================================================
Route::middleware('auth.redis')->group(function () {

    // -------------------------------------------------------------------------
    // AUTH & USER MANAGEMENT
    // -------------------------------------------------------------------------
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

    // -------------------------------------------------------------------------
    // SCHOOL MANAGEMENT
    // -------------------------------------------------------------------------
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

    // -------------------------------------------------------------------------
    // ACADEMIC YEAR ROUTES
    // -------------------------------------------------------------------------
    Route::post('/academic-years/bulk', [AcademicYearController::class, 'storeBulk']);
    Route::get('/academic-years/by-curriculum/{curriculum}', [AcademicYearController::class, 'getByCurriculum']);
    Route::apiResource('academic-years', AcademicYearController::class);

    // -------------------------------------------------------------------------
    // CLASSROOM ROUTES
    // -------------------------------------------------------------------------
    Route::apiResource('classrooms', ClassroomController::class);
    Route::get('/classrooms/{classroomId}/streams', [ClassroomController::class, 'getStreams']);
    Route::post('/classrooms/{classroomId}/streams', [ClassroomController::class, 'addStream']);
    Route::get('/classrooms/{classroomId}/teachers', [ClassroomController::class, 'getTeachers']);
    Route::post('/classrooms/{classroomId}/teachers', [ClassroomController::class, 'assignTeachers']);
    Route::post('/classrooms/{classroomId}/class-teacher', [ClassroomController::class, 'assignClassTeacher']);
    Route::delete('/classrooms/{classroomId}/class-teacher', [ClassroomController::class, 'removeClassTeacher']);
    Route::post('/teachers/assign-to-multiple-classrooms', [ClassroomController::class, 'assignToMultipleClassrooms']);
    Route::get('/teachers/{teacherId}/available-classrooms', [ClassroomController::class, 'getAvailableClassroomsForTeacher']);

    // -------------------------------------------------------------------------
    // STREAM ROUTES
    // -------------------------------------------------------------------------
    Route::get('/streams/class-teachers', [StreamController::class, 'getAllClassTeachers']);
    Route::get('/streams/classroom/{classroomId}', [StreamController::class, 'getStreamsByClassroom']);
    Route::get('/streams/{streamId}/teachers', [StreamController::class, 'getStreamTeachers']);
    Route::post('/streams/{streamId}/assign-class-teacher', [StreamController::class, 'assignClassTeacher']);
    Route::delete('/streams/{streamId}/remove-class-teacher', [StreamController::class, 'removeClassTeacher']);
    Route::post('/streams/{streamId}/assign-teachers', [StreamController::class, 'assignTeachers']);
    Route::post('/teachers/assign-to-multiple-streams', [StreamController::class, 'assignToMultipleStreams']);
    Route::apiResource('streams', StreamController::class);

    // -------------------------------------------------------------------------
    // SUBJECT ASSIGNMENT ROUTES
    // -------------------------------------------------------------------------
    Route::post('/subject-assignments/batch', [SubjectAssignmentController::class, 'storeBatch']);
    Route::apiResource('subject-assignments', SubjectAssignmentController::class);

    // -------------------------------------------------------------------------
    // TEACHER ROUTES
    // -------------------------------------------------------------------------
    Route::get('/teachers/class-teachers', [TeacherController::class, 'getAllClassTeachers']);
    Route::get('/teachers/workload-report', [TeacherController::class, 'getWorkloadReport']);
    Route::get('/teachers/with-assignments', [TeacherController::class, 'getTeachersWithAssignments']);
    Route::get('/teachers/school/{schoolId}', [TeacherController::class, 'getTeachersBySchool']);
    Route::get('/teachers/stream/{streamId}', [TeacherController::class, 'getTeachersByStream']);

    Route::get('/teacher-combinations', [TeacherController::class, 'getCombinations']);
    Route::get('/teacher-combinations/{id}/preview', [TeacherController::class, 'previewCombination']);

    Route::get('/teachers/{teacherId}/workload', [TeacherController::class, 'getWorkload']);
    Route::get('/teachers/{teacherId}/timetable-capacity', [TeacherController::class, 'getTimetableCapacity']);
    Route::post('/teachers/{teacherId}/validate-assignment', [TeacherController::class, 'validateAssignment']);
    Route::get('/teachers/{teacherId}/assignments', [TeacherController::class, 'getAssignments']);

    Route::get('/teachers/{teacherId}/classrooms', [TeacherController::class, 'getClassrooms']);
    Route::post('/teachers/{teacherId}/classrooms', [TeacherController::class, 'assignToClassroom']);
    Route::delete('/teachers/{teacherId}/classrooms/{classroomId}', [TeacherController::class, 'removeFromClassroom']);

    Route::get('/teachers/{teacherId}/streams-as-class-teacher', [TeacherController::class, 'getStreamsAsClassTeacher']);
    Route::get('/teachers/{teacherId}/streams-as-teacher', [TeacherController::class, 'getStreamsAsTeacher']);

    Route::get('/teachers/{teacherId}/subjects', [TeacherController::class, 'getSubjects']);
    Route::post('/teachers/{teacherId}/subjects', [TeacherController::class, 'addSubject']);
    Route::delete('/teachers/{teacherId}/subjects/{subjectId}', [TeacherController::class, 'removeSubject']);

    Route::apiResource('teachers', TeacherController::class);

    // -------------------------------------------------------------------------
    // SUBJECT ROUTES
    // -------------------------------------------------------------------------
    Route::get('/subjects/filter', [TeacherController::class, 'filterSubjectsForTeacher']);
    Route::get('/subjects/constants', [SubjectController::class, 'getConstants']);
    Route::get('/subjects/search', [SubjectController::class, 'searchSubjectByName']);
    Route::get('/subjects/by-learning-area', [SubjectController::class, 'getByLearningArea']);
    Route::get('/subjects/kicd-required/{level}', [SubjectController::class, 'getKICDRequired']);
    Route::get('/subjects/curriculum/{curriculum}/level/{level}', [SubjectController::class, 'getByCurriculumAndLevel']);
    Route::get('/subjects/by-school-level', [SubjectController::class, 'getBySchoolLevel']);

    Route::get('/subjects/{subjectId}/streams', [SubjectController::class, 'getStreams']);
    Route::post('/subjects/{subjectId}/streams', [SubjectController::class, 'assignToStreams']);
    Route::get('/subjects/{subjectId}/teachers', [SubjectController::class, 'getTeachers']);
    Route::post('/subjects/{subjectId}/teachers', [SubjectController::class, 'assignTeachers']);

    Route::apiResource('subjects', SubjectController::class);

    // -------------------------------------------------------------------------
    // PARENT ROUTES
    // -------------------------------------------------------------------------
    Route::get('parents/{parentId}/students', [ParentController::class, 'getStudents']);
    Route::get('/parents/school/{schoolId}', [ParentController::class, 'getParentsBySchool']);
    Route::apiResource('parents', ParentController::class);

    // -------------------------------------------------------------------------
    // STUDENT ROUTES
    // -------------------------------------------------------------------------
    Route::apiResource('students', StudentController::class);
    Route::get('get-students', [StudentController::class, 'getStudents']);
    Route::get('class-students/{classId}', [StudentController::class, 'getClassStudents']);
    Route::get('get-parents', [StudentController::class, 'getParentsForMySchool']);

    // =========================================================================
    // ENROLLMENT ROUTES (parent-facing, auth.redis protected)
    // =========================================================================
    // Parents can view and manage their own applications after logging in.
    // store() is PUBLIC (above) — parent has no account at submission time.
    // All other routes are protected — parent logs in to track after submission.
    //
    // Ownership enforced by authorizeParent() in EnrollmentController:
    //   parent_phone or parent_email must match the logged-in user.
    Route::get('/enrollment', [EnrollmentController::class, 'index']);
    Route::get('/enrollment/{enrollment}', [EnrollmentController::class, 'show']);
    Route::put('/enrollment/{enrollment}', [EnrollmentController::class, 'update']);
    Route::post('/enrollment/{enrollment}/submit', [EnrollmentController::class, 'submit']);

    // =========================================================================
    // ADMIN ENROLLMENT ROUTES
    // =========================================================================
    Route::prefix('admin')->group(function () {

        // ── Enrollment management ─────────────────────────────────────────────
        //
        // ⚠️  ORDER MATTERS — Static/named routes MUST come BEFORE {enrollment}
        //     parameter routes. Laravel matches routes top-to-bottom, so if the
        //     /{enrollment} wildcard appears first, strings like "waitlist" and
        //     "placements" will be resolved as enrollment IDs and return 404s.

        // Static routes first:
        Route::get('/enrollments/waitlist', [AdminEnrollmentController::class, 'waitlist']);
        Route::post('/enrollments/waitlist/promote', [AdminEnrollmentController::class, 'promoteFromWaitlist']);

        // Government placement verification (senior school CBC — MoE placements)
        Route::get('/enrollments/placements', [AdminEnrollmentController::class, 'placements']);
        Route::post('/enrollments/placements/bulk-verify', [AdminEnrollmentController::class, 'bulkVerifyPlacements']);

        // Standard enrollment CRUD + workflow (parameterized routes last):
        Route::get('/enrollments', [AdminEnrollmentController::class, 'index']);
        Route::get('/enrollments/{enrollment}', [AdminEnrollmentController::class, 'show']);
        Route::put('/enrollments/{enrollment}', [AdminEnrollmentController::class, 'update']);
        Route::post('/enrollments/{enrollment}/review', [AdminEnrollmentController::class, 'startReview']);
        Route::post('/enrollments/{enrollment}/approve', [AdminEnrollmentController::class, 'approve']);
        Route::post('/enrollments/{enrollment}/reject', [AdminEnrollmentController::class, 'reject']);
        Route::post('/enrollments/{enrollment}/verify-placement', [AdminEnrollmentController::class, 'verifyPlacement']);

        // ── Admission number configuration ────────────────────────────────────
        //
        // ⚠️  Static sub-routes (/preview, /reset-sequence) declared before
        //     the base /admission-config route.
        Route::get('/admission-config/preview', [AdmissionConfigController::class, 'preview']);
        Route::post('/admission-config/reset-sequence', [AdmissionConfigController::class, 'resetSequence']);
        Route::get('/admission-config', [AdmissionConfigController::class, 'show']);
        Route::post('/admission-config', [AdmissionConfigController::class, 'store']);

        // ── Enrollment settings ───────────────────────────────────────────────
        //
        // ⚠️  Static sub-routes (/toggle, /summary) declared before the base
        //     /enrollment-settings route.
        Route::post('/enrollment-settings/toggle', [EnrollmentSettingController::class, 'toggle']);
        Route::get('/enrollment-settings/summary', [EnrollmentSettingController::class, 'summary']);
        Route::get('/enrollment-settings', [EnrollmentSettingController::class, 'show']);
        Route::post('/enrollment-settings', [EnrollmentSettingController::class, 'store']);

        // Update existing enrollment settings (PUT and PATCH both supported)
        Route::put('/enrollment-settings/{id}',   [EnrollmentSettingController::class, 'update']);
        Route::patch('/enrollment-settings/{id}', [EnrollmentSettingController::class, 'update']);
    });
});