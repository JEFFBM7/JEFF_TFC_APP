<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ClassRoomController;
use App\Http\Controllers\Api\V1\LevelController;
use App\Http\Controllers\Api\V1\SchoolYearController;
use App\Http\Controllers\Api\V1\SubjectController;
use App\Http\Controllers\Api\V1\TeacherController;
use App\Http\Controllers\Api\V1\TermController;
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

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);
    });

    Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->group(function (): void {
        Route::get('/ping', fn () => [
            'ok' => true,
            'message' => 'Bienvenue dans la zone admin.',
        ]);
    });

    Route::middleware(['auth:sanctum', 'role:admin,secretariat'])->prefix('staff')->group(function (): void {
        Route::get('/ping', fn () => [
            'ok' => true,
            'message' => 'Zone staff (admin + secrétariat).',
        ]);
    });

    Route::middleware(['auth:sanctum', 'role:admin'])->group(function (): void {
        Route::apiResource('school-years', SchoolYearController::class)
            ->parameter('school-years', 'school_year');
        Route::apiResource('terms', TermController::class);
        Route::apiResource('levels', LevelController::class);
        Route::apiResource('classrooms', ClassRoomController::class);

        // Matières
        Route::apiResource('subjects', SubjectController::class);
        Route::prefix('classrooms/{classroom}/subjects')->group(function (): void {
            Route::get('/', [SubjectController::class, 'classroomSubjects']);
            Route::post('/', [SubjectController::class, 'syncClassroomSubject']);
            Route::delete('/{subject}', [SubjectController::class, 'detachClassroomSubject']);
        });

        // Enseignants + affectations
        Route::apiResource('teachers', TeacherController::class);
        Route::get('assignments', [TeacherController::class, 'assignments']);
        Route::post('assignments', [TeacherController::class, 'storeAssignment']);
        Route::delete('assignments/{assignment}', [TeacherController::class, 'destroyAssignment']);
    });
});
