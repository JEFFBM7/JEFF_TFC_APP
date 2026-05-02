<?php

/*
|--------------------------------------------------------------------------
| API v1 — EduConnect
|--------------------------------------------------------------------------
|
| Documentation interactive (Swagger UI) :  GET /api/documentation
| Spec OpenAPI 3.1 (JSON)                :  GET /docs/api.json
| UI alternative (Stoplight Elements)    :  GET /docs/api
|
| La doc est générée automatiquement par dedoc/scramble à partir des
| FormRequest, Resources et signatures des controllers — aucune annotation
| manuelle n'est requise. Pour rafraîchir l'export :
|   php artisan scramble:export
|
*/

use App\Http\Controllers\Api\V1\AppreciationController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ClassRoomController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\EvaluationController;
use App\Http\Controllers\Api\V1\LevelController;
use App\Http\Controllers\Api\V1\MessageController;
use App\Http\Controllers\Api\V1\ParentController;
use App\Http\Controllers\Api\V1\ParentPortalController;
use App\Http\Controllers\Api\V1\ReportCardController;
use App\Http\Controllers\Api\V1\ReportsController;
use App\Http\Controllers\Api\V1\SchoolYearController;
use App\Http\Controllers\Api\V1\StudentController;
use App\Http\Controllers\Api\V1\SubjectController;
use App\Http\Controllers\Api\V1\TeacherController;
use App\Http\Controllers\Api\V1\TermController;
use App\Http\Controllers\Api\V1\TimetableSlotController;
use App\Http\Controllers\Api\V1\UserController;
use App\Models\Term;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', fn () => [
        'ok' => true,
        'service' => 'educonnect-api',
        'version' => 'v1',
    ]);

    Route::middleware('throttle:login')->group(function (): void {
        Route::post('/auth/login', [AuthController::class, 'login']);
    });

    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);
    });

    Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->group(function (): void {
        Route::get('/ping', fn () => [
            'ok' => true,
            'message' => 'Bienvenue dans la zone admin.',
        ]);
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::get('/dashboard', [DashboardController::class, 'admin']);
        Route::get('/login-logs', [AuthController::class, 'loginLogs']);
    });

    Route::middleware(['auth:sanctum', 'role:enseignant'])->group(function (): void {
        Route::get('teacher/dashboard', [DashboardController::class, 'teacher']);
    });

    Route::middleware(['auth:sanctum', 'role:admin,secretariat'])->prefix('staff')->group(function (): void {
        Route::get('/ping', fn () => [
            'ok' => true,
            'message' => 'Zone staff (admin + secrétariat).',
        ]);
    });

    // Lecture classes / matières : présences & bulletins côté enseignant / secrétariat
    Route::middleware(['auth:sanctum', 'role:admin,enseignant,secretariat'])->group(function (): void {
        Route::get('classrooms', [ClassRoomController::class, 'index']);
        Route::get('subjects', [SubjectController::class, 'index']);
        Route::get('classrooms/{classroom}/subjects', [SubjectController::class, 'classroomSubjects']);
        Route::get('timetable-slots', [TimetableSlotController::class, 'index']);
    });

    Route::middleware(['auth:sanctum', 'role:admin'])->group(function (): void {
        Route::apiResource('school-years', SchoolYearController::class)
            ->parameter('school-years', 'school_year');
        Route::apiResource('terms', TermController::class);
        Route::apiResource('levels', LevelController::class);
        Route::apiResource('classrooms', ClassRoomController::class)->except(['index']);

        // Matières
        Route::apiResource('subjects', SubjectController::class)->except(['index']);
        Route::prefix('classrooms/{classroom}/subjects')->group(function (): void {
            Route::post('/', [SubjectController::class, 'syncClassroomSubject']);
            Route::delete('/{subject}', [SubjectController::class, 'detachClassroomSubject']);
        });

        // Enseignants + affectations
        Route::apiResource('teachers', TeacherController::class);
        Route::get('assignments', [TeacherController::class, 'assignments']);
        Route::post('assignments', [TeacherController::class, 'storeAssignment']);
        Route::delete('assignments/{assignment}', [TeacherController::class, 'destroyAssignment']);

        // Emploi du temps (CDC §4.4)
        Route::apiResource('timetable-slots', TimetableSlotController::class)->except(['index']);

        // Parents et élèves
        Route::apiResource('parents', ParentController::class);
        Route::get('students/import/template', [StudentController::class, 'importTemplate']);
        Route::post('students/import', [StudentController::class, 'import']);
        Route::apiResource('students', StudentController::class);
        Route::post('students/{student}/parents', [StudentController::class, 'attachParent']);
        Route::delete('students/{student}/parents/{parent}', [StudentController::class, 'detachParent']);
    });

    // Évaluations & notes : admin + enseignant
    Route::middleware(['auth:sanctum', 'role:admin,enseignant'])->group(function (): void {
        Route::apiResource('evaluations', EvaluationController::class);
        Route::get('evaluations/{evaluation}/grades', [EvaluationController::class, 'grades']);
        Route::post('evaluations/{evaluation}/grades', [EvaluationController::class, 'saveGrades']);
    });

    // Bulletins
    Route::middleware(['auth:sanctum', 'role:admin,enseignant'])->group(function (): void {
        Route::get('students/{student}/report-cards/{term}', [ReportCardController::class, 'show']);
        Route::get('students/{student}/report-cards/{term}/pdf', [ReportCardController::class, 'pdf']);
        Route::get('classrooms/{classroom}/ranking/{term}', fn ($classroom, $term) => app(ReportCardController::class)->classRanking((int) $classroom, Term::findOrFail($term)));

        // Appréciations enseignant principal (CDC §4.6)
        Route::get('students/{student}/appreciations/{term}', [AppreciationController::class, 'show']);
        Route::put('students/{student}/appreciations/{term}', [AppreciationController::class, 'upsert']);
    });

    // Clôture d'un trimestre (admin seul) — CDC §4.9 / UC-04
    Route::middleware(['auth:sanctum', 'role:admin'])->group(function (): void {
        Route::post('terms/{term}/close', [TermController::class, 'close']);
    });

    // Rapports & exports CSV (CDC §4.7) — admin + enseignant
    Route::middleware(['auth:sanctum', 'role:admin,enseignant'])->prefix('reports')->group(function (): void {
        Route::get('classrooms/{classroom}/ranking/{term}/csv', [ReportsController::class, 'classRankingCsv']);
        Route::get('attendance/csv', [ReportsController::class, 'attendanceCsv']);
        Route::get('students/{student}/evolution/csv', [ReportsController::class, 'studentEvolutionCsv']);
    });

    // Absences (admin + enseignant + secrétariat)
    Route::middleware(['auth:sanctum', 'role:admin,enseignant,secretariat'])->group(function (): void {
        Route::get('attendances', [AttendanceController::class, 'index']);
        Route::get('attendances/roll-call', [AttendanceController::class, 'rollCall']);
        Route::post('attendances/batch', [AttendanceController::class, 'saveBatch']);
        Route::patch('attendances/{attendance}/justify', [AttendanceController::class, 'justify']);
        Route::delete('attendances/{attendance}', [AttendanceController::class, 'destroy']);
        Route::get('students/{student}/attendance-summary', [AttendanceController::class, 'studentSummary']);
        Route::get('classrooms/{classroom}/attendance-summary', [AttendanceController::class, 'classSummary']);
    });

    // Portail parent (CDC UC-05)
    Route::middleware(['auth:sanctum', 'role:parent'])->prefix('parent')->group(function (): void {
        Route::get('dashboard', [ParentPortalController::class, 'dashboard']);
        Route::get('children', [ParentPortalController::class, 'children']);
        Route::get('terms', [ParentPortalController::class, 'terms']);
        Route::get('children/{student}/report-card/{term}', [ParentPortalController::class, 'childReportCard']);
        Route::get('children/{student}/report-card/{term}/pdf', [ParentPortalController::class, 'childReportCardPdf']);
        Route::get('children/{student}/attendances', [ParentPortalController::class, 'childAttendances']);
        Route::get('children/{student}/attendance-summary', [ParentPortalController::class, 'childAttendanceSummary']);
        Route::patch(
            'children/{student}/attendances/{attendance}/justify',
            [ParentPortalController::class, 'justifyChildAttendance'],
        );
    });

    // Messagerie interne (tous les rôles authentifiés)
    Route::middleware(['auth:sanctum'])->group(function (): void {
        Route::get('messages/inbox', [MessageController::class, 'inbox']);
        Route::get('messages/sent', [MessageController::class, 'sent']);
        Route::get('messages/unread-count', [MessageController::class, 'unreadCount']);
        Route::get('messages/contacts', [MessageController::class, 'contacts']);
        Route::post('messages', [MessageController::class, 'store']);
        Route::get('messages/{message}', [MessageController::class, 'show']);
        Route::delete('messages/{message}', [MessageController::class, 'destroy']);
    });
});
