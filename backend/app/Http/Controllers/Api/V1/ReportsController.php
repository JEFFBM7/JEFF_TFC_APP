<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\ClassRoom;
use App\Models\Term;
use App\Models\Student;
use App\Services\ReportCardService;
use App\Support\AdminScopeContext;
use App\Support\SchoolYearContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exports CSV pour le module Rapports & Analyses (CDC §4.7).
 */
class ReportsController extends Controller
{
    public function __construct(private readonly ReportCardService $reportCards) {}

    /** Classement par moyenne d'une classe pour un trimestre. */
    public function classRankingCsv(ClassRoom $classroom, Term $term): StreamedResponse
    {
        AdminScopeContext::assertClassroomAllowed(request()->user(), $classroom);

        $rows = $this->reportCards->classRanking($classroom->id, $term);

        $filename = "classement-{$classroom->full_name}-{$term->name}.csv";

        return $this->streamCsv($filename, function ($out) use ($rows): void {
            fputcsv($out, ['rang', 'eleve', 'moyenne_generale']);
            $rank = 1;
            foreach ($rows as $r) {
                fputcsv($out, [
                    $rank,
                    $r['student']->full_name,
                    $r['overall_average'] !== null ? number_format((float) $r['overall_average'], 2, '.', '') : '',
                ]);
                $rank++;
            }
        });
    }

    /** Taux d'absentéisme par classe sur une période. */
    public function attendanceCsv(Request $request): StreamedResponse
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'school_year_id' => ['nullable', 'integer', 'exists:school_years,id'],
        ]);

        $from = $request->string('from')->value() ?: null;
        $to = $request->string('to')->value() ?: null;
        $schoolYearId = SchoolYearContext::requestedOrCurrentId($request);

        $classroomQuery = ClassRoom::query()->with('level');
        AdminScopeContext::applyClassroomScope($classroomQuery, $request);
        $classrooms = $classroomQuery->get();

        $period = ($from ?? 'debut').'_au_'.($to ?? 'fin');
        $filename = "absenteisme-{$period}.csv";

        return $this->streamCsv($filename, function ($out) use ($classrooms, $from, $to, $request, $schoolYearId): void {
            fputcsv($out, ['classe', 'effectif', 'present', 'absent', 'absent_non_justifie', 'retard']);

            foreach ($classrooms as $classroom) {
                $studentQuery = Student::query()->where('classroom_id', $classroom->id);
                SchoolYearContext::applyStudentEnrollmentYearId($studentQuery, $schoolYearId);
                $studentCount = $studentQuery->count();

                $q = Attendance::query()->where('classroom_id', $classroom->id);
                SchoolYearContext::applyDateRange($q, $request);
                if ($from) {
                    $q->whereDate('date', '>=', $from);
                }
                if ($to) {
                    $q->whereDate('date', '<=', $to);
                }
                $rows = $q->get();

                fputcsv($out, [
                    $classroom->full_name,
                    $studentCount,
                    $rows->where('status', Attendance::STATUS_PRESENT)->count(),
                    $rows->where('status', Attendance::STATUS_ABSENT)->count(),
                    $rows->where('status', Attendance::STATUS_ABSENT)->where('justified', false)->count(),
                    $rows->where('status', Attendance::STATUS_LATE)->count(),
                ]);
            }
        });
    }

    /** Évolution des moyennes d'un élève sur plusieurs trimestres d'une année scolaire. */
    public function studentEvolutionCsv(Student $student, Request $request): StreamedResponse
    {
        AdminScopeContext::assertStudentAllowed($request->user(), $student);

        $request->validate([
            'school_year_id' => ['nullable', 'integer', 'exists:school_years,id'],
        ]);

        $termsQuery = Term::query();
        SchoolYearContext::applySchoolYearColumn($termsQuery, $request);

        $terms = $termsQuery
            ->orderBy('school_year_id')
            ->orderBy('position')
            ->get();

        $filename = "evolution-{$student->full_name}.csv";

        return $this->streamCsv($filename, function ($out) use ($student, $terms): void {
            fputcsv($out, ['trimestre', 'annee_scolaire', 'moyenne_generale']);
            foreach ($terms as $term) {
                $term->loadMissing('schoolYear');
                $report = $this->reportCards->compute($student, $term);
                fputcsv($out, [
                    $term->name,
                    $term->schoolYear?->name ?? '',
                    $report['overall_average'] !== null
                        ? number_format((float) $report['overall_average'], 2, '.', '')
                        : '',
                ]);
            }
        });
    }

    /** Helper : envoie un CSV streamé avec UTF-8 BOM pour Excel. */
    private function streamCsv(string $filename, callable $writer): StreamedResponse
    {
        return response()->streamDownload(function () use ($writer): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 (Excel)
            $writer($out);
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
