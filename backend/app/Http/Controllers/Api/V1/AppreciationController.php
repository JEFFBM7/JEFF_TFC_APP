<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\ReportCardAppreciation;
use App\Models\Term;
use App\Models\Student;
use App\Models\Teacher;
use App\Support\SchoolYearContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppreciationController extends Controller
{
    public function show(Student $student, Term $term): JsonResponse
    {
        $appreciation = ReportCardAppreciation::query()
            ->where('student_id', $student->id)
            ->where('term_id', $term->id)
            ->first();

        return response()->json([
            'data' => [
                'student_id' => $student->id,
                'term_id' => $term->id,
                'content' => $appreciation?->content,
                'updated_at' => $appreciation?->updated_at,
            ],
        ]);
    }

    public function upsert(Request $request, Student $student, Term $term): JsonResponse
    {
        SchoolYearContext::assertTermNotArchived($term);

        $data = $request->validate([
            'content' => ['required', 'string', 'max:2000'],
        ]);

        $user = $request->user();
        $teacherId = null;
        if ($user->role === UserRole::Enseignant) {
            $teacherId = Teacher::query()->where('user_id', $user->id)->value('id');
        }

        $appreciation = ReportCardAppreciation::query()->updateOrCreate(
            ['student_id' => $student->id, 'term_id' => $term->id],
            ['content' => $data['content'], 'teacher_id' => $teacherId],
        );

        return response()->json([
            'data' => [
                'student_id' => $appreciation->student_id,
                'term_id' => $appreciation->term_id,
                'content' => $appreciation->content,
                'updated_at' => $appreciation->updated_at,
            ],
        ]);
    }
}
