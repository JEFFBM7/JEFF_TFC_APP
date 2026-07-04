<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Term;
use App\Models\Student;
use App\Services\ReportCardService;
use App\Support\AdminScopeContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ReportCardController extends Controller
{
    public function __construct(
        private readonly ReportCardService $service,
    ) {}

    public function show(Request $request, Student $student, Term $term): JsonResponse
    {
        AdminScopeContext::assertStudentAllowed($request->user(), $student);

        $report = $this->service->compute($student, $term);

        return response()->json([
            'data' => [
                'student' => [
                    'id' => $student->id,
                    'full_name' => $student->full_name,
                    'registration_number' => $student->registration_number,
                    'classroom' => $student->classroom?->full_name,
                ],
                'term' => [
                    'id' => $term->id,
                    'name' => $term->name,
                ],
                'subjects' => $report['subjects']->map(fn ($r) => [
                    'subject_id' => $r['subject']->id,
                    'subject_name' => $r['subject']->name,
                    'coefficient' => (float) $r['coefficient'],
                    'count' => (int) $r['count'],
                    'average' => $r['average'],
                    'evaluations' => $r['evaluations'] ?? [],
                ])->values(),
                'period_averages' => $report['period_averages']->map(fn ($r) => [
                    'period_id' => $r['period']->id,
                    'name' => $r['period']->name,
                    'position' => $r['period']->position,
                    'average' => $r['average'],
                ])->values(),
                'overall_average' => $report['overall_average'],
                'total_coefficient' => $report['total_coefficient'],
                'appreciation' => $report['appreciation'] ?? null,
            ],
        ]);
    }

    public function pdf(Student $student, Term $term): Response
    {
        AdminScopeContext::assertStudentAllowed(request()->user(), $student);

        $student->loadMissing('classroom.level');
        $term->loadMissing('schoolYear');

        $report = $this->service->compute($student, $term);

        $pdf = Pdf::loadView('report_cards.pdf', [
            'student' => $student,
            'term' => $term,
            'subjects' => $report['subjects'],
            'period_averages' => $report['period_averages'],
            'overall_average' => $report['overall_average'],
            'appreciation' => $report['appreciation'] ?? null,
        ])->setPaper('a4');

        $filename = sprintf(
            'bulletin-%s-%s.pdf',
            Str::slug($student->full_name),
            Str::slug($term->name),
        );

        return $pdf->download($filename);
    }

    public function classRanking(int $classroomId, Term $term): JsonResponse
    {
        AdminScopeContext::assertClassroomAllowed(request()->user(), $classroomId);

        $rows = $this->service->classRanking($classroomId, $term);

        return response()->json([
            'data' => $rows->map(fn ($r, $idx) => [
                'rank' => $idx + 1,
                'student_id' => $r['student']->id,
                'full_name' => $r['student']->full_name,
                'overall_average' => $r['overall_average'],
            ])->values(),
        ]);
    }
}
