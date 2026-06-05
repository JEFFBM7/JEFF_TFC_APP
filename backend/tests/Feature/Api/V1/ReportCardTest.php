<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\ClassRoom;
use App\Models\Evaluation;
use App\Models\Grade;
use App\Models\Level;
use App\Models\Period;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportCardTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_card_computes_weighted_average(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $level = Level::factory()->create();
        $classroom = ClassRoom::factory()->create(['level_id' => $level->id, 'section' => 'A']);
        $year = SchoolYear::factory()->create();
        $term = Term::factory()->create(['school_year_id' => $year->id, 'name' => 'T1', 'position' => 1]);
        $periodOne = Period::factory()->create(['term_id' => $term->id, 'name' => 'P1', 'position' => 1]);
        $periodTwo = Period::factory()->create(['term_id' => $term->id, 'name' => 'P2', 'position' => 2]);

        $maths = Subject::factory()->create(['name' => 'Maths']);
        $francais = Subject::factory()->create(['name' => 'Français']);

        // Coefficients
        $classroom->subjects()->attach($maths->id, ['coefficient' => 4]);
        $classroom->subjects()->attach($francais->id, ['coefficient' => 2]);

        $student = Student::factory()->create(['classroom_id' => $classroom->id]);

        $eM1 = Evaluation::factory()->create([
            'classroom_id' => $classroom->id, 'subject_id' => $maths->id, 'term_id' => $term->id, 'period_id' => $periodOne->id,
        ]);
        $eF1 = Evaluation::factory()->create([
            'classroom_id' => $classroom->id, 'subject_id' => $francais->id, 'term_id' => $term->id, 'period_id' => $periodOne->id,
        ]);
        $eM2 = Evaluation::factory()->create([
            'classroom_id' => $classroom->id, 'subject_id' => $maths->id, 'term_id' => $term->id, 'period_id' => $periodTwo->id,
        ]);
        $eF2 = Evaluation::factory()->create([
            'classroom_id' => $classroom->id, 'subject_id' => $francais->id, 'term_id' => $term->id, 'period_id' => $periodTwo->id,
        ]);

        Grade::query()->create(['evaluation_id' => $eM1->id, 'student_id' => $student->id, 'value' => 16, 'absent' => false]);
        Grade::query()->create(['evaluation_id' => $eF1->id, 'student_id' => $student->id, 'value' => 10, 'absent' => false]);
        Grade::query()->create(['evaluation_id' => $eM2->id, 'student_id' => $student->id, 'value' => 12, 'absent' => false]);
        Grade::query()->create(['evaluation_id' => $eF2->id, 'student_id' => $student->id, 'value' => 18, 'absent' => false]);

        // P1 = (16*4 + 10*2) / 6 = 14 ; P2 = (12*4 + 18*2) / 6 = 14 ; T1 = (P1+P2)/2 = 14.
        $res = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/students/{$student->id}/report-cards/{$term->id}")
            ->assertOk();

        $this->assertEquals(14.0, $res->json('data.overall_average'));
        $this->assertEquals(14.0, $res->json('data.period_averages.0.average'));
        $this->assertEquals(14.0, $res->json('data.period_averages.1.average'));
        $this->assertCount(2, $res->json('data.subjects'));
    }

    public function test_report_card_applies_rdc_period_components_on_twenty(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $level = Level::factory()->create();
        $classroom = ClassRoom::factory()->create(['level_id' => $level->id, 'section' => 'A']);
        $year = SchoolYear::factory()->create();
        $term = Term::factory()->create(['school_year_id' => $year->id, 'name' => 'T1', 'position' => 1]);
        $period = Period::factory()->create(['term_id' => $term->id, 'name' => 'P1', 'position' => 1]);
        $subject = Subject::factory()->create(['name' => 'Maths']);
        $classroom->subjects()->attach($subject->id, ['coefficient' => 1]);
        $student = Student::factory()->create([
            'classroom_id' => $classroom->id,
            'enrollment_school_year_id' => $year->id,
        ]);

        $interro = Evaluation::factory()->create([
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
            'term_id' => $term->id,
            'period_id' => $period->id,
            'type' => Evaluation::TYPE_INTERROGATION,
            'max_value' => 10,
        ]);
        $devoir = Evaluation::factory()->create([
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
            'term_id' => $term->id,
            'period_id' => $period->id,
            'type' => Evaluation::TYPE_DEVOIR,
            'max_value' => 20,
        ]);
        $exam = Evaluation::factory()->create([
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
            'term_id' => $term->id,
            'period_id' => $period->id,
            'type' => Evaluation::TYPE_EXAMEN,
            'max_value' => 20,
        ]);

        Grade::query()->create(['evaluation_id' => $interro->id, 'student_id' => $student->id, 'value' => 8, 'absent' => false]);
        Grade::query()->create(['evaluation_id' => $devoir->id, 'student_id' => $student->id, 'value' => 12, 'absent' => false]);
        Grade::query()->create(['evaluation_id' => $exam->id, 'student_id' => $student->id, 'value' => 15, 'absent' => false]);

        // Continu = moyenne(8/10 => 16/20, 12/20) = 14 ; examen = 15 ; période = 14*40% + 15*60%.
        $res = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/students/{$student->id}/report-cards/{$term->id}")
            ->assertOk();

        $this->assertEquals(14.6, $res->json('data.subjects.0.average'));
        $this->assertEquals(14.6, $res->json('data.period_averages.0.average'));
        $this->assertEquals(14.6, $res->json('data.overall_average'));
    }

    public function test_report_card_only_lists_subjects_with_evaluations_in_term(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $level = Level::factory()->create();
        $classroom = ClassRoom::factory()->create(['level_id' => $level->id, 'section' => 'A']);
        $year = SchoolYear::factory()->create();
        $term = Term::factory()->create(['school_year_id' => $year->id, 'name' => 'T1', 'position' => 1]);
        $period = Period::factory()->create(['term_id' => $term->id, 'name' => 'P1', 'position' => 1]);

        $withEval = Subject::factory()->create(['name' => 'Maths']);
        $withoutEval = Subject::factory()->create(['name' => 'Éducation à la vie']);
        $classroom->subjects()->attach($withEval->id, ['coefficient' => 2]);
        $classroom->subjects()->attach($withoutEval->id, ['coefficient' => 1]);

        $student = Student::factory()->create(['classroom_id' => $classroom->id]);

        $evaluation = Evaluation::factory()->create([
            'classroom_id' => $classroom->id,
            'subject_id' => $withEval->id,
            'term_id' => $term->id,
            'period_id' => $period->id,
        ]);
        Grade::query()->create([
            'evaluation_id' => $evaluation->id,
            'student_id' => $student->id,
            'value' => 11,
            'absent' => false,
        ]);

        $res = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/students/{$student->id}/report-cards/{$term->id}")
            ->assertOk();

        $this->assertCount(1, $res->json('data.subjects'));
        $this->assertSame('Maths', $res->json('data.subjects.0.subject_name'));
    }

    public function test_pdf_endpoint_returns_pdf(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $level = Level::factory()->create();
        $classroom = ClassRoom::factory()->create(['level_id' => $level->id, 'section' => 'A']);
        $year = SchoolYear::factory()->create();
        $term = Term::factory()->create(['school_year_id' => $year->id, 'name' => 'T1', 'position' => 1]);
        $student = Student::factory()->create(['classroom_id' => $classroom->id]);

        $res = $this->actingAs($admin, 'sanctum')
            ->get("/api/v1/students/{$student->id}/report-cards/{$term->id}/pdf");

        $res->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('content-type'));
    }
}
