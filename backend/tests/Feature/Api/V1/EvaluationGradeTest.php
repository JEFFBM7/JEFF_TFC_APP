<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\ClassRoom;
use App\Models\Evaluation;
use App\Models\GradeAudit;
use App\Models\Level;
use App\Models\Period;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EvaluationGradeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    /** @return array{0:ClassRoom,1:Subject,2:Term,3:Period} */
    private function context(): array
    {
        $level = Level::factory()->create();
        $classroom = ClassRoom::factory()->create(['level_id' => $level->id, 'section' => 'A']);
        $subject = Subject::factory()->create(['name' => 'Maths']);
        $year = SchoolYear::factory()->create();
        $term = Term::factory()->create(['school_year_id' => $year->id, 'name' => 'T1', 'position' => 1]);
        $period = Period::factory()->create(['term_id' => $term->id, 'name' => 'P1', 'position' => 1]);

        return [$classroom, $subject, $term, $period];
    }

    /** @return array{0:ClassRoom,1:Subject,2:Term,3:Period,4:User} */
    private function teacherContext(): array
    {
        [$classroom, $subject, $term, $period] = $this->context();
        $teacherUser = User::factory()->create(['role' => UserRole::Enseignant]);
        $teacher = Teacher::factory()->create(['user_id' => $teacherUser->id]);

        TeacherAssignment::query()->create([
            'teacher_id' => $teacher->id,
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
            'school_year_id' => $term->school_year_id,
        ]);

        return [$classroom, $subject, $term, $period, $teacherUser];
    }

    // ─── Évaluations ──────────────────────────────────────────────────────────

    public function test_parent_cannot_create_evaluation(): void
    {
        $u = User::factory()->create(['role' => UserRole::Parent]);

        $this->actingAs($u, 'sanctum')
            ->postJson('/api/v1/evaluations', [])
            ->assertForbidden();
    }

    public function test_admin_can_create_exam_evaluation(): void
    {
        [$c, $s, $t, $p] = $this->context();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/evaluations', [
                'classroom_id' => $c->id,
                'subject_id' => $s->id,
                'term_id' => $t->id,
                'period_id' => $p->id,
                'name' => 'Examen de période 1',
                'type' => Evaluation::TYPE_EXAMEN,
                'held_on' => '2026-09-15',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Examen de période 1')
            ->assertJsonPath('data.type_label', 'Examen de période')
            ->assertJsonPath('data.component', Evaluation::COMPONENT_EXAM)
            ->assertJsonPath('data.term_id', $t->id)
            ->assertJsonPath('data.period_id', $p->id);
    }

    public function test_admin_cannot_create_non_exam_evaluation(): void
    {
        [$c, $s, $t, $p] = $this->context();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/evaluations', [
                'classroom_id' => $c->id,
                'subject_id' => $s->id,
                'term_id' => $t->id,
                'period_id' => $p->id,
                'name' => 'Interrogation 1',
                'type' => Evaluation::TYPE_INTERROGATION,
                'held_on' => '2026-09-15',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);
    }

    public function test_primary_titular_teacher_can_create_evaluation_for_classroom_subject(): void
    {
        $level = Level::factory()->create(['cycle' => Level::CYCLE_PRIMAIRE]);
        $classroom = ClassRoom::factory()->create(['level_id' => $level->id, 'section' => 'A']);
        $subject = Subject::factory()->create(['name' => 'Français']);
        $classroom->subjects()->attach($subject->id, ['coefficient' => 4]);
        $year = SchoolYear::factory()->current()->create();
        $term = Term::factory()->create([
            'school_year_id' => $year->id,
            'name' => '1er trimestre',
            'position' => 1,
            'applicable_cycle' => 'primaire',
        ]);
        $period = Period::factory()->create(['term_id' => $term->id, 'name' => 'P1', 'position' => 1]);

        $teacherUser = User::factory()->create(['role' => UserRole::Enseignant]);
        $teacher = Teacher::factory()->create([
            'user_id' => $teacherUser->id,
            'teacher_type' => Teacher::TYPE_PRIMAIRE,
            'speciality' => null,
        ]);

        TeacherAssignment::query()->create([
            'teacher_id' => $teacher->id,
            'classroom_id' => $classroom->id,
            'school_year_id' => $year->id,
            'subject_id' => null,
            'is_main' => true,
        ]);

        $this->actingAs($teacherUser, 'sanctum')
            ->postJson('/api/v1/evaluations', [
                'classroom_id' => $classroom->id,
                'subject_id' => $subject->id,
                'term_id' => $term->id,
                'period_id' => $period->id,
                'name' => 'Interrogation français',
                'type' => 'interrogation',
                'held_on' => '2026-09-15',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Interrogation français');

        $this->actingAs($teacherUser, 'sanctum')
            ->getJson('/api/v1/classrooms')
            ->assertOk()
            ->assertJsonPath('data.0.id', $classroom->id);
    }

    public function test_teacher_can_create_continuous_evaluation(): void
    {
        [$c, $s, $t, $p, $teacherUser] = $this->teacherContext();

        $this->actingAs($teacherUser, 'sanctum')
            ->postJson('/api/v1/evaluations', [
                'classroom_id' => $c->id,
                'subject_id' => $s->id,
                'term_id' => $t->id,
                'period_id' => $p->id,
                'name' => 'Devoir maison 1',
                'type' => Evaluation::TYPE_DEVOIR,
                'held_on' => '2026-09-15',
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', Evaluation::TYPE_DEVOIR)
            ->assertJsonPath('data.component', Evaluation::COMPONENT_CONTINUOUS);
    }

    public function test_teacher_cannot_create_exam_evaluation(): void
    {
        [$c, $s, $t, $p, $teacherUser] = $this->teacherContext();

        $this->actingAs($teacherUser, 'sanctum')
            ->postJson('/api/v1/evaluations', [
                'classroom_id' => $c->id,
                'subject_id' => $s->id,
                'term_id' => $t->id,
                'period_id' => $p->id,
                'name' => 'Examen interdit',
                'type' => Evaluation::TYPE_EXAMEN,
                'held_on' => '2026-09-15',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);
    }

    public function test_admin_cannot_modify_teacher_evaluation(): void
    {
        [$c, $s, $t, $p] = $this->context();
        $eval = Evaluation::factory()->create([
            'classroom_id' => $c->id,
            'subject_id' => $s->id,
            'term_id' => $t->id,
            'period_id' => $p->id,
            'type' => Evaluation::TYPE_DEVOIR,
            'name' => 'Devoir enseignant',
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/v1/evaluations/{$eval->id}", [
                'classroom_id' => $c->id,
                'subject_id' => $s->id,
                'term_id' => $t->id,
                'period_id' => $p->id,
                'name' => 'Devoir renommé',
                'type' => Evaluation::TYPE_DEVOIR,
                'held_on' => '2026-09-16',
            ])
            ->assertForbidden();
    }

    public function test_teacher_cannot_modify_exam_evaluation(): void
    {
        [$c, $s, $t, $p, $teacherUser] = $this->teacherContext();
        $eval = Evaluation::factory()->create([
            'classroom_id' => $c->id,
            'subject_id' => $s->id,
            'term_id' => $t->id,
            'period_id' => $p->id,
            'type' => Evaluation::TYPE_EXAMEN,
            'name' => 'Examen admin',
        ]);

        $this->actingAs($teacherUser, 'sanctum')
            ->putJson("/api/v1/evaluations/{$eval->id}", [
                'classroom_id' => $c->id,
                'subject_id' => $s->id,
                'term_id' => $t->id,
                'period_id' => $p->id,
                'name' => 'Examen renommé',
                'type' => Evaluation::TYPE_EXAMEN,
                'held_on' => '2026-09-16',
            ])
            ->assertForbidden();
    }

    public function test_invalid_type_rejected(): void
    {
        [$c, $s, $t, $p] = $this->context();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/evaluations', [
                'classroom_id' => $c->id,
                'subject_id' => $s->id,
                'term_id' => $t->id,
                'period_id' => $p->id,
                'name' => 'X',
                'type' => 'inconnu',
                'held_on' => '2026-09-15',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);
    }

    public function test_evaluation_rejects_period_from_another_term(): void
    {
        [$c, $s, $t] = $this->context();
        $otherTerm = Term::factory()->create(['school_year_id' => $t->school_year_id, 'name' => 'T2', 'position' => 2]);
        $otherPeriod = Period::factory()->create(['term_id' => $otherTerm->id, 'name' => 'P3', 'position' => 3]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/evaluations', [
                'classroom_id' => $c->id,
                'subject_id' => $s->id,
                'term_id' => $t->id,
                'period_id' => $otherPeriod->id,
                'name' => 'Devoir incohérent',
                'type' => Evaluation::TYPE_EXAMEN,
                'held_on' => '2026-09-15',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['period_id']);
    }

    public function test_evaluations_default_to_current_school_year_and_allow_historical_filter(): void
    {
        $level = Level::factory()->create();
        $classroom = ClassRoom::factory()->create(['level_id' => $level->id]);
        $subject = Subject::factory()->create(['name' => 'Sciences']);
        $oldYear = SchoolYear::factory()->create(['name' => '2024-2025']);
        $currentYear = SchoolYear::factory()->current()->create(['name' => '2025-2026']);
        $oldTerm = Term::factory()->create(['school_year_id' => $oldYear->id, 'name' => 'Ancien T1']);
        $currentTerm = Term::factory()->create(['school_year_id' => $currentYear->id, 'name' => 'Courant T1']);

        Evaluation::factory()->create([
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
            'term_id' => $oldTerm->id,
            'name' => 'Ancienne évaluation',
        ]);
        Evaluation::factory()->create([
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
            'term_id' => $currentTerm->id,
            'name' => 'Évaluation courante',
        ]);

        $default = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/evaluations')
            ->assertOk();

        $this->assertCount(1, $default->json('data'));
        $this->assertSame('Évaluation courante', $default->json('data.0.name'));

        $historical = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/evaluations?school_year_id='.$oldYear->id)
            ->assertOk();

        $this->assertCount(1, $historical->json('data'));
        $this->assertSame('Ancienne évaluation', $historical->json('data.0.name'));
    }

    // ─── Saisie de notes ─────────────────────────────────────────────────────

    public function test_grades_endpoint_lists_classroom_students(): void
    {
        [$c, $s, $t, $p] = $this->context();
        $eval = Evaluation::factory()->create([
            'classroom_id' => $c->id,
            'subject_id' => $s->id,
            'term_id' => $t->id,
            'period_id' => $p->id,
        ]);
        Student::factory()->create([
            'classroom_id' => $c->id,
            'enrollment_school_year_id' => $t->school_year_id,
            'last_name' => 'AAA',
        ]);
        Student::factory()->create([
            'classroom_id' => $c->id,
            'enrollment_school_year_id' => $t->school_year_id,
            'last_name' => 'BBB',
        ]);

        $res = $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/v1/evaluations/{$eval->id}/grades")
            ->assertOk();

        $this->assertCount(2, $res->json('data'));
        $this->assertNull($res->json('data.0.value'));
    }

    public function test_admin_can_save_grades_in_batch(): void
    {
        [$c, $s, $t, $p] = $this->context();
        $eval = Evaluation::factory()->create([
            'classroom_id' => $c->id,
            'subject_id' => $s->id,
            'term_id' => $t->id,
            'period_id' => $p->id,
        ]);
        $s1 = Student::factory()->create([
            'classroom_id' => $c->id,
            'enrollment_school_year_id' => $t->school_year_id,
        ]);
        $s2 = Student::factory()->create([
            'classroom_id' => $c->id,
            'enrollment_school_year_id' => $t->school_year_id,
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/evaluations/{$eval->id}/grades", [
                'grades' => [
                    ['student_id' => $s1->id, 'value' => 14.5],
                    ['student_id' => $s2->id, 'absent' => true],
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas('grades', ['student_id' => $s1->id, 'value' => 14.5, 'absent' => false]);
        $this->assertDatabaseHas('grades', ['student_id' => $s2->id, 'absent' => true]);
    }

    public function test_grade_value_must_follow_evaluation_max_value(): void
    {
        [$c, $s, $t, $p] = $this->context();
        $eval = Evaluation::factory()->create([
            'classroom_id' => $c->id,
            'subject_id' => $s->id,
            'term_id' => $t->id,
            'period_id' => $p->id,
            'max_value' => 100,
        ]);
        $stu = Student::factory()->create([
            'classroom_id' => $c->id,
            'enrollment_school_year_id' => $t->school_year_id,
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/evaluations/{$eval->id}/grades", [
                'grades' => [
                    ['student_id' => $stu->id, 'value' => 101],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['grades.0.value']);
    }

    public function test_grade_creation_and_modification_create_audit_entries(): void
    {
        [$c, $s, $t, $p] = $this->context();
        $eval = Evaluation::factory()->create([
            'classroom_id' => $c->id,
            'subject_id' => $s->id,
            'term_id' => $t->id,
            'period_id' => $p->id,
        ]);
        $stu = Student::factory()->create([
            'classroom_id' => $c->id,
            'enrollment_school_year_id' => $t->school_year_id,
        ]);

        // 1ère saisie
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/evaluations/{$eval->id}/grades", [
                'grades' => [['student_id' => $stu->id, 'value' => 12]],
            ])->assertOk();

        $this->assertSame(1, GradeAudit::query()->count());
        $this->assertDatabaseHas('grade_audits', [
            'old_value' => null,
            'new_value' => 12,
        ]);

        // 2e saisie : modification → audit
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/evaluations/{$eval->id}/grades", [
                'grades' => [['student_id' => $stu->id, 'value' => 16]],
            ])->assertOk();

        $this->assertSame(2, GradeAudit::query()->count());
        $this->assertDatabaseHas('grade_audits', [
            'old_value' => 12,
            'new_value' => 16,
        ]);
    }

    public function test_evaluation_rejects_term_from_wrong_cycle_group(): void
    {
        [$c, $s, $t, $p] = $this->context();
        $secondaryLevel = Level::factory()->create(['cycle' => Level::CYCLE_SECONDAIRE]);
        $secondaryClassroom = ClassRoom::factory()->create(['level_id' => $secondaryLevel->id, 'section' => 'A']);
        $secondaryTerm = Term::factory()->create([
            'school_year_id' => $t->school_year_id,
            'name' => '1er Semestre',
            'position' => 4,
            'term_type' => Term::TYPE_SEMESTRE,
            'applicable_cycle' => Term::CYCLE_SECONDAIRE,
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-02-11',
        ]);
        $secondaryPeriod = Period::factory()->create([
            'term_id' => $secondaryTerm->id,
            'school_year_id' => $t->school_year_id,
            'name' => 'Période 1',
            'position' => 7,
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-11-15',
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/evaluations', [
                'classroom_id' => $c->id,
                'subject_id' => $s->id,
                'term_id' => $secondaryTerm->id,
                'period_id' => $secondaryPeriod->id,
                'name' => 'Interrogation incohérente',
                'type' => Evaluation::TYPE_EXAMEN,
                'held_on' => '2026-09-15',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['period_id']);
    }
}
