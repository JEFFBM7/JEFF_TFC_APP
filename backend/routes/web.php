<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 * Documentation API — Swagger UI officielle
 * La spec OpenAPI est générée automatiquement par Scramble (à partir des
 * FormRequest, Resources et signatures des controllers) et exposée en JSON.
 *
 *   - UI Swagger    : GET /api/documentation
 *   - Spec OpenAPI  : GET /docs/api.json   (servi par Scramble)
 *   - UI Stoplight  : GET /docs/api        (UI alternative servie par Scramble)
 */
Route::get('/api/documentation', function () {
    return view('swagger', [
        'title' => config('app.name').' — API Swagger UI',
        'specUrl' => url('/docs/api.json'),
    ]);
})->name('api.documentation');
