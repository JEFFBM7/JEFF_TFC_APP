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
use App\Http\Controllers\Api\V1\PeriodController;
use App\Http\Controllers\Api\V1\PromotionController;
use App\Http\Controllers\Api\V1\ReportCardController;
use App\Http\Controllers\Api\V1\ReportsController;
use App\Http\Controllers\Api\V1\SchoolOptionController;
use App\Http\Controllers\Api\V1\SchoolClassController;
use App\Http\Controllers\Api\V1\SchoolCalendarController;
use App\Http\Controllers\Api\V1\PushController;
use App\Http\Controllers\Api\V1\SchoolYearController;
use App\Http\Controllers\Api\V1\TermController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\StudentController;
use App\Http\Controllers\Api\V1\StudentPortalController;
use App\Http\Controllers\Api\V1\StudentsAtRiskController;
use App\Http\Controllers\Api\V1\SubjectController;
use App\Http\Controllers\Api\V1\TeacherController;
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

        // Année scolaire courante : nécessaire à tous les rôles pour le contexte global UI
        // (filtrage automatique dans le client API + indicateur dans la topbar).
        Route::get('/school-years/current', [SchoolYearController::class, 'current']);

        // Notifications Web Push (tous rôles).
        Route::get('/push/public-key', [PushController::class, 'publicKey']);
        Route::post('/push/subscribe', [PushController::class, 'subscribe']);
        Route::post('/push/unsubscribe', [PushController::class, 'unsubscribe']);
    });

    Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->group(function (): void {
        Route::get('/ping', fn () => [
            'ok' => true,
            'message' => 'Bienvenue dans la zone admin.',
        ]);
        Route::middleware('global_admin')->group(function (): void {
            Route::get('/users', [UserController::class, 'index']);
            Route::post('/users', [UserController::class, 'store']);
            Route::patch('/users/{user}', [UserController::class, 'update']);
            Route::delete('/users/{user}', [UserController::class, 'destroy']);
            Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword']);
            Route::get('/login-logs', [AuthController::class, 'loginLogs']);
            Route::get('/settings', [SettingsController::class, 'index']);
            Route::put('/settings', [SettingsController::class, 'update']);
        });
        Route::get('/dashboard', [DashboardController::class, 'admin']);
    });

    // Élèves en difficulté (admin + enseignant)
    Route::middleware(['auth:sanctum', 'role:admin,enseignant'])->group(function (): void {
        Route::get('students-at-risk', [StudentsAtRiskController::class, 'index']);
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

    // Lecture classes / cours : présences & bulletins côté enseignant / secrétariat
    Route::middleware(['auth:sanctum', 'role:admin,enseignant,secretariat'])->group(function (): void {
        Route::get('school-calendar/context', [SchoolCalendarController::class, 'context']);
        Route::get('school-calendar/dev-options', [SchoolCalendarController::class, 'devOptions']);
        Route::get('classrooms', [ClassRoomController::class, 'index']);
        Route::get('periods', [PeriodController::class, 'index']);
        Route::get('subjects', [SubjectController::class, 'index']);
        Route::get('classrooms/{classroom}/subjects', [SubjectController::class, 'classroomSubjects']);
        Route::get('timetable-slots', [TimetableSlotController::class, 'index']);
    });

    Route::middleware(['auth:sanctum', 'role:admin'])->group(function (): void {
        Route::get(
            'school-years/{school_year}/classrooms/{classroom}/details',
            [SchoolYearController::class, 'classroomDetails'],
        );
        Route::middleware('global_admin')->group(function (): void {
            Route::post(
                'school-years/{school_year}/archive',
                [SchoolYearController::class, 'archive'],
            );
            Route::post(
                'school-years/{school_year}/generate-classes',
                [SchoolClassController::class, 'generate'],
            );
            Route::post(
                'school-years/{school_year}/unarchive',
                [SchoolYearController::class, 'unarchive'],
            );

            // Passage de classe (promotion / redoublement) à la clôture/création d'année.
            Route::get('school-years/{school_year}/promotion/preview', [PromotionController::class, 'preview']);
            Route::post('school-years/{school_year}/promotion/commit', [PromotionController::class, 'commit']);
            Route::post('promotion-batches/{promotion_batch}/rollback', [PromotionController::class, 'rollback']);
            Route::apiResource('school-years', SchoolYearController::class)
                ->parameter('school-years', 'school_year')
                ->except(['index', 'show']);
            Route::apiResource('terms', TermController::class);
            Route::apiResource('periods', PeriodController::class)->except(['index']);
            Route::post('periods/{period}/close', [PeriodController::class, 'close']);
            Route::apiResource('levels', LevelController::class)->except(['index', 'show']);
            Route::apiResource('school-options', SchoolOptionController::class)->only(['store']);
        });
        Route::apiResource('school-years', SchoolYearController::class)
            ->parameter('school-years', 'school_year')
            ->only(['index', 'show']);
        Route::get('school-years/{school_year}/school-classes', [SchoolClassController::class, 'index']);
        Route::post('school-years/{school_year}/school-classes', [SchoolClassController::class, 'store']);
        Route::post('school-classes/{school_class}/divisions', [SchoolClassController::class, 'addDivisions']);
        Route::post('school-classes/{school_class}/divisions/next', [SchoolClassController::class, 'addNextDivision']);
        Route::apiResource('levels', LevelController::class)->only(['index', 'show']);
        Route::apiResource('classrooms', ClassRoomController::class)->except(['index']);
        Route::apiResource('school-options', SchoolOptionController::class)->only(['index']);

        // Cours
        Route::apiResource('subjects', SubjectController::class)->except(['index']);
        Route::post('subjects/{subject}/assign-teacher', [SubjectController::class, 'assignTeacher']);
        Route::delete('subjects/{subject}/assign-teacher', [SubjectController::class, 'unassignTeacher']);
        Route::prefix('classrooms/{classroom}/subjects')->group(function (): void {
            Route::post('/', [SubjectController::class, 'syncClassroomSubject']);
            Route::delete('/{subject}', [SubjectController::class, 'detachClassroomSubject']);
        });

        // Enseignants + affectations
        Route::apiResource('teachers', TeacherController::class);
        Route::post('teachers/{teacher}/assign-classroom', [TeacherController::class, 'assignClassroom']);
        Route::delete('teachers/{teacher}/assign-classroom', [TeacherController::class, 'unassignClassroom']);
        Route::get('assignments', [TeacherController::class, 'assignments']);
        Route::post('assignments', [TeacherController::class, 'storeAssignment']);
        Route::patch('assignments/{assignment}', [TeacherController::class, 'updateAssignment']);
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
        Route::post('evaluations/{evaluation}/publish', [EvaluationController::class, 'publish']);
        Route::post('evaluations/{evaluation}/unpublish', [EvaluationController::class, 'unpublish']);
    });

    // Bulletins
    Route::middleware(['auth:sanctum', 'role:admin,enseignant'])->group(function (): void {
        Route::get('students/{student}/timeline', [StudentController::class, 'timeline']);
        Route::get('students/{student}/report-cards/{term}', [ReportCardController::class, 'show']);
        Route::get('students/{student}/report-cards/{term}/pdf', [ReportCardController::class, 'pdf']);
        Route::get('classrooms/{classroom}/ranking/{term}', fn ($classroom, $term) => app(ReportCardController::class)->classRanking((int) $classroom, Term::findOrFail($term)));

        // Appréciations enseignant principal (CDC §4.6)
        Route::get('students/{student}/appreciations/{term}', [AppreciationController::class, 'show']);
        Route::put('students/{student}/appreciations/{term}', [AppreciationController::class, 'upsert']);
    });

    // Clôture d'un trimestre (admin seul) — CDC §4.9 / UC-04
    Route::middleware(['auth:sanctum', 'role:admin'])->group(function (): void {
        Route::post('terms/{term}/close', [TermController::class, 'close'])->middleware('global_admin');
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

    // Portail élève (CDC §4.1 — consultation notes, bulletins, emploi du temps)
    Route::middleware(['auth:sanctum', 'role:eleve'])->prefix('student')->group(function (): void {
        Route::get('me', [StudentPortalController::class, 'me']);
        Route::get('dashboard', [StudentPortalController::class, 'dashboard']);
        Route::get('timeline', [StudentPortalController::class, 'timeline']);
        Route::get('terms', [StudentPortalController::class, 'terms']);
        Route::get('terms/{term}/periods', [StudentPortalController::class, 'periods']);
        Route::get('school-years', [StudentPortalController::class, 'schoolYears']);
        Route::get('report-card/{term}', [StudentPortalController::class, 'reportCard']);
        Route::get('report-card/{term}/pdf', [StudentPortalController::class, 'reportCardPdf']);
        Route::get('attendances', [StudentPortalController::class, 'attendances']);
        Route::patch('attendances/{attendance}/justify', [StudentPortalController::class, 'justifyAttendance']);
        Route::get('timetable', [StudentPortalController::class, 'timetable']);
        // Choix d'option d'entrée au secondaire (8e CTEB) — fenêtre ouverte
        // une semaine avant la clôture de l'année, consommé par le passage.
        Route::get('option-choice', [StudentPortalController::class, 'optionChoice']);
        Route::put('option-choice', [StudentPortalController::class, 'submitOptionChoice']);
    });

    // Portail parent (CDC UC-05)
    Route::middleware(['auth:sanctum', 'role:parent'])->prefix('parent')->group(function (): void {
        Route::get('dashboard', [ParentPortalController::class, 'dashboard']);
        Route::get('children', [ParentPortalController::class, 'children']);
        Route::get('terms', [ParentPortalController::class, 'terms']);
        Route::get('children/{student}/terms', [ParentPortalController::class, 'childTerms']);
        Route::get('children/{student}/terms/{term}/periods', [ParentPortalController::class, 'childPeriods']);
        Route::get('children/{student}/timeline', [ParentPortalController::class, 'childTimeline']);
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

    // Diffusion d'annonces (admin + secrétariat)
    Route::middleware(['auth:sanctum', 'role:admin,secretariat'])->group(function (): void {
        Route::post('messages/broadcast', [MessageController::class, 'broadcast']);
        Route::patch('messages/broadcast/{broadcastId}', [MessageController::class, 'updateBroadcast']);
        Route::get('messages/broadcast/audience-count', [MessageController::class, 'broadcastAudienceCount']);
    });
});
