<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\ClassRoom;
use App\Models\Evaluation;
use App\Models\Grade;
use App\Models\Level;
use App\Models\Period;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\Term;
use App\Models\TimetableSlot;
use App\Models\Teacher;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StudentPortalTest extends TestCase
{
    use RefreshDatabase;

    /** Crée un compte élève + profil Student lié. */
    private function makeStudentUser(): array
    {
        $level = Level::factory()->create();
        $classroom = ClassRoom::factory()->create(['level_id' => $level->id, 'section' => 'A']);
        $user = User::factory()->create(['role' => UserRole::Eleve, 'name' => 'Jean Tshimanga']);
        $student = Student::factory()->create([
            'user_id' => $user->id,
            'classroom_id' => $classroom->id,
            'first_name' => 'Jean',
            'last_name' => 'Tshimanga',
            'middle_name' => 'Ilunga',
        ]);

        return compact('user', 'student', 'classroom');
    }

    // ─── RBAC ────────────────────────────────────────────────────────────

    public function test_parent_cannot_access_student_portal(): void
    {
        $parent = User::factory()->create(['role' => UserRole::Parent]);

        $this->actingAs($parent, 'sanctum')
            ->getJson('/api/v1/student/dashboard')
            ->assertForbidden();
    }

    public function test_admin_cannot_access_student_portal(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/student/dashboard')
            ->assertForbidden();
    }

    public function test_eleve_without_profile_gets_404(): void
    {
        $user = User::factory()->create(['role' => UserRole::Eleve]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/student/dashboard')
            ->assertNotFound();
    }

    // ─── Me ──────────────────────────────────────────────────────────────

    public function test_student_can_see_own_profile(): void
    {
        $ctx = $this->makeStudentUser();

        $this->actingAs($ctx['user'], 'sanctum')
            ->getJson('/api/v1/student/me')
            ->assertOk()
            ->assertJsonPath('data.full_name', 'Tshimanga Ilunga Jean');
    }

    // ─── Dashboard ───────────────────────────────────────────────────────

    public function test_student_dashboard_uses_upcoming_term_when_none_is_current(): void
    {
        $year = SchoolYear::factory()->current()->create([
            'name' => '2026-2027',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-06-30',
        ]);
        $level = Level::factory()->create(['cycle' => Level::CYCLE_PRIMAIRE]);
        $classroom = ClassRoom::factory()->create(['level_id' => $level->id]);
        $user = User::factory()->create(['role' => UserRole::Eleve]);
        Student::factory()->create([
            'user_id' => $user->id,
            'classroom_id' => $classroom->id,
            'enrollment_school_year_id' => $year->id,
            'first_name' => 'Marie',
            'last_name' => 'Kabila',
        ]);
        Term::factory()->create([
            'school_year_id' => $year->id,
            'name' => '1er Trimestre',
            'position' => 1,
            'term_type' => Term::TYPE_TRIMESTRE,
            'applicable_cycle' => Term::CYCLE_PRIMAIRE,
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-12-20',
        ]);

        $this->travelTo('2026-06-04');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/student/dashboard')
            ->assertOk()
            ->assertJsonPath('data.first_name', 'Marie')
            ->assertJsonPath('data.current_term', '1er Trimestre');
    }

    public function test_student_dashboard_shows_absences(): void
    {
        $ctx = $this->makeStudentUser();

        Attendance::factory()->count(2)->create([
            'student_id' => $ctx['student']->id,
            'classroom_id' => $ctx['classroom']->id,
            'status' => Attendance::STATUS_ABSENT,
        ]);

        $this->actingAs($ctx['user'], 'sanctum')
            ->getJson('/api/v1/student/dashboard')
            ->assertOk()
            ->assertJsonPath('data.total_absences', 2)
            ->assertJsonPath('data.full_name', 'Tshimanga Ilunga Jean');
    }

    public function test_student_dashboard_hides_absence_alert_when_justification_deadline_passed(): void
    {
        Carbon::setTestNow('2026-05-25 10:00:00');

        try {
            $ctx = $this->makeStudentUser();

            foreach (['2026-05-22', '2026-05-23', '2026-05-24'] as $day) {
                Attendance::factory()->create([
                    'student_id' => $ctx['student']->id,
                    'classroom_id' => $ctx['classroom']->id,
                    'status' => Attendance::STATUS_ABSENT,
                    'date' => $day,
                    'justified' => false,
                ]);
            }

            $this->actingAs($ctx['user'], 'sanctum')
                ->getJson('/api/v1/student/dashboard')
                ->assertOk()
                ->assertJsonPath('data.alert.triggered', false);
        } finally {
            Carbon::setTestNow();
        }
    }

    // ─── Bulletin ────────────────────────────────────────────────────────

    public function test_student_can_view_report_card_json(): void
    {
        $ctx = $this->makeStudentUser();
        $year = SchoolYear::factory()->create(['name' => '2025-2026']);
        $term = Term::factory()->create(['school_year_id' => $year->id, 'name' => 'T1', 'position' => 1]);

        $this->actingAs($ctx['user'], 'sanctum')
            ->getJson("/api/v1/student/report-card/{$term->id}")
            ->assertOk()
            ->assertJsonStructure(['data' => ['student', 'term', 'subjects', 'overall_average']]);
    }

    public function test_student_report_card_hides_subjects_without_published_evaluation(): void
    {
        $ctx = $this->makeStudentUser();
        $year = SchoolYear::factory()->create(['name' => '2025-2026']);
        $term = Term::factory()->create(['school_year_id' => $year->id, 'name' => 'T1', 'position' => 1]);
        $period = Period::factory()->create(['term_id' => $term->id, 'name' => 'P1', 'position' => 1]);

        $graded = Subject::factory()->create(['name' => 'Maths']);
        $listedOnly = Subject::factory()->create(['name' => 'Éducation à la vie']);
        $ctx['classroom']->subjects()->attach($graded->id, ['coefficient' => 2]);
        $ctx['classroom']->subjects()->attach($listedOnly->id, ['coefficient' => 1]);

        $evaluation = Evaluation::factory()->create([
            'classroom_id' => $ctx['classroom']->id,
            'subject_id' => $graded->id,
            'term_id' => $term->id,
            'period_id' => $period->id,
            'published_at' => now(),
        ]);
        Grade::query()->create([
            'evaluation_id' => $evaluation->id,
            'student_id' => $ctx['student']->id,
            'value' => 9,
            'absent' => false,
        ]);

        $this->actingAs($ctx['user'], 'sanctum')
            ->getJson("/api/v1/student/report-card/{$term->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.subjects')
            ->assertJsonPath('data.subjects.0.subject_name', 'Maths');
    }

    public function test_student_report_card_includes_published_grades_even_without_classroom_subject_link(): void
    {
        $ctx = $this->makeStudentUser();
        $year = SchoolYear::factory()->create(['name' => '2025-2026']);
        $term = Term::factory()->create(['school_year_id' => $year->id, 'name' => 'T1', 'position' => 1]);
        $period = Period::factory()->create(['term_id' => $term->id, 'name' => 'P1', 'position' => 1]);
        $subject = Subject::factory()->create(['name' => 'Histoire-Géo', 'default_coefficient' => 1]);
        $evaluation = Evaluation::factory()->create([
            'classroom_id' => $ctx['classroom']->id,
            'subject_id' => $subject->id,
            'term_id' => $term->id,
            'period_id' => $period->id,
            'type' => Evaluation::TYPE_INTERROGATION,
            'name' => 'interro',
            'max_value' => 20,
            'published_at' => now(),
        ]);

        Grade::query()->create([
            'evaluation_id' => $evaluation->id,
            'student_id' => $ctx['student']->id,
            'value' => 3,
            'absent' => false,
        ]);

        $this->actingAs($ctx['user'], 'sanctum')
            ->getJson("/api/v1/student/report-card/{$term->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.subjects')
            ->assertJsonPath('data.subjects.0.subject_name', 'Histoire-Géo')
            ->assertJsonPath('data.subjects.0.count', 1)
            ->assertJsonPath('data.subjects.0.evaluations.0.value', 3);
    }

    public function test_student_can_download_report_card_pdf(): void
    {
        $ctx = $this->makeStudentUser();
        $year = SchoolYear::factory()->create(['name' => '2024-2025']);
        $term = Term::factory()->create(['school_year_id' => $year->id, 'name' => 'T2', 'position' => 2]);

        $this->actingAs($ctx['user'], 'sanctum')
            ->getJson("/api/v1/student/report-card/{$term->id}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    // ─── Absences ────────────────────────────────────────────────────────

    public function test_student_can_list_own_attendances(): void
    {
        $ctx = $this->makeStudentUser();

        Attendance::factory()->create([
            'student_id' => $ctx['student']->id,
            'classroom_id' => $ctx['classroom']->id,
            'status' => Attendance::STATUS_ABSENT,
        ]);
        Attendance::factory()->create([
            'student_id' => $ctx['student']->id,
            'classroom_id' => $ctx['classroom']->id,
            'status' => Attendance::STATUS_LATE,
        ]);

        $res = $this->actingAs($ctx['user'], 'sanctum')
            ->getJson('/api/v1/student/attendances')
            ->assertOk();

        $this->assertCount(2, $res->json('data'));
    }

    public function test_student_portal_uses_current_year_duplicate_profile_when_account_points_to_another_year(): void
    {
        $currentYear = SchoolYear::factory()->current()->create([
            'name' => '2025-2026',
            'starts_on' => now()->subMonth()->toDateString(),
            'ends_on' => now()->addMonth()->toDateString(),
        ]);
        $otherYear = SchoolYear::factory()->create(['name' => '2030-2031']);
        $level = Level::factory()->create();
        $linkedClassroom = ClassRoom::factory()->create(['level_id' => $level->id, 'section' => 'A']);
        $currentClassroom = ClassRoom::factory()->create(['level_id' => $level->id, 'section' => 'B']);
        $user = User::factory()->create(['role' => UserRole::Eleve, 'name' => 'BOPE MIKOBI Jeff']);

        Student::factory()->create([
            'user_id' => $user->id,
            'classroom_id' => $linkedClassroom->id,
            'enrollment_school_year_id' => $otherYear->id,
            'first_name' => 'Jeff',
            'last_name' => 'BOPE',
            'middle_name' => 'MIKOBI',
        ]);

        $actualStudent = Student::factory()->create([
            'user_id' => null,
            'classroom_id' => $currentClassroom->id,
            'enrollment_school_year_id' => $currentYear->id,
            'first_name' => 'JEFF',
            'last_name' => 'MIKOBI',
            'middle_name' => 'BOPE',
        ]);

        $attendance = Attendance::factory()->create([
            'student_id' => $actualStudent->id,
            'classroom_id' => $currentClassroom->id,
            'status' => Attendance::STATUS_ABSENT,
            'date' => now()->toDateString(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/student/me')
            ->assertOk()
            ->assertJsonPath('data.id', $actualStudent->id);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/student/attendances')
            ->assertOk()
            ->assertJsonPath('data.0.id', $attendance->id)
            ->assertJsonPath('data.0.student_id', $actualStudent->id);
    }

    public function test_student_can_filter_own_absences(): void
    {
        $ctx = $this->makeStudentUser();

        Attendance::factory()->create([
            'student_id' => $ctx['student']->id,
            'classroom_id' => $ctx['classroom']->id,
            'status' => Attendance::STATUS_ABSENT,
        ]);
        Attendance::factory()->create([
            'student_id' => $ctx['student']->id,
            'classroom_id' => $ctx['classroom']->id,
            'status' => Attendance::STATUS_LATE,
        ]);

        $res = $this->actingAs($ctx['user'], 'sanctum')
            ->getJson('/api/v1/student/attendances?status=absent')
            ->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame(Attendance::STATUS_ABSENT, $res->json('data.0.status'));
    }

    public function test_student_can_submit_same_day_justification_for_absence_or_late(): void
    {
        $ctx = $this->makeStudentUser();

        $attendance = Attendance::factory()->create([
            'student_id' => $ctx['student']->id,
            'classroom_id' => $ctx['classroom']->id,
            'status' => Attendance::STATUS_LATE,
            'date' => now()->toDateString(),
            'justified' => false,
        ]);

        $this->actingAs($ctx['user'], 'sanctum')
            ->patchJson("/api/v1/student/attendances/{$attendance->id}/justify", [
                'justification' => 'Transport bloqué',
            ])
            ->assertOk()
            ->assertJsonPath('data.justified', false)
            ->assertJsonPath('data.student_justification', 'Transport bloqué')
            ->assertJsonPath('data.justification_status', 'pending_parent');

        $this->assertDatabaseHas('attendances', [
            'id' => $attendance->id,
            'student_justification' => 'Transport bloqué',
            'justified' => false,
        ]);
    }

    public function test_student_cannot_submit_justification_after_same_day_deadline(): void
    {
        $ctx = $this->makeStudentUser();

        $attendance = Attendance::factory()->create([
            'student_id' => $ctx['student']->id,
            'classroom_id' => $ctx['classroom']->id,
            'status' => Attendance::STATUS_ABSENT,
            'date' => now()->subDay()->toDateString(),
            'justified' => false,
        ]);

        $this->actingAs($ctx['user'], 'sanctum')
            ->patchJson("/api/v1/student/attendances/{$attendance->id}/justify", [
                'justification' => 'Malade',
            ])
            ->assertStatus(422);
    }

    // ─── Emploi du temps ─────────────────────────────────────────────────

    public function test_student_can_view_class_timetable(): void
    {
        $ctx = $this->makeStudentUser();
        $subject = Subject::factory()->create(['name' => 'Physique']);
        $teacher = Teacher::factory()->create();
        $year = SchoolYear::factory()->create(['name' => '2025-2026']);

        TimetableSlot::query()->create([
            'classroom_id' => $ctx['classroom']->id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
            'school_year_id' => $year->id,
            'day_of_week' => 1,
            'starts_at' => '08:00',
            'ends_at' => '09:00',
        ]);

        $res = $this->actingAs($ctx['user'], 'sanctum')
            ->getJson('/api/v1/student/timetable')
            ->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame('Physique', $res->json('data.0.subject.name'));
    }

    // ─── Trimestres ──────────────────────────────────────────────────────

    public function test_student_can_list_periods_for_term(): void
    {
        $ctx = $this->makeStudentUser();
        $year = SchoolYear::factory()->create(['name' => '2025-2026']);
        $term = Term::factory()->create([
            'school_year_id' => $year->id,
            'name' => '1er Trimestre',
            'position' => 1,
            'term_type' => Term::TYPE_TRIMESTRE,
            'applicable_cycle' => Term::CYCLE_PRIMAIRE,
        ]);
        $period = Period::factory()->create(['term_id' => $term->id, 'name' => 'Période 1', 'position' => 1]);

        $this->actingAs($ctx['user'], 'sanctum')
            ->getJson("/api/v1/student/terms/{$term->id}/periods")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $period->id)
            ->assertJsonPath('meta.term_type', Term::TYPE_TRIMESTRE);
    }

    public function test_student_report_card_can_be_scoped_to_period(): void
    {
        $ctx = $this->makeStudentUser();
        $year = SchoolYear::factory()->create(['name' => '2025-2026']);
        $term = Term::factory()->create(['school_year_id' => $year->id, 'name' => 'T1', 'position' => 1]);
        $period = Period::factory()->create(['term_id' => $term->id, 'name' => 'P1', 'position' => 1]);
        $subject = Subject::factory()->create(['name' => 'Maths']);
        $ctx['classroom']->subjects()->attach($subject->id, ['coefficient' => 1]);
        $evaluation = Evaluation::factory()->create([
            'classroom_id' => $ctx['classroom']->id,
            'subject_id' => $subject->id,
            'term_id' => $term->id,
            'period_id' => $period->id,
            'published_at' => now(),
        ]);
        Grade::query()->create([
            'evaluation_id' => $evaluation->id,
            'student_id' => $ctx['student']->id,
            'value' => 14,
            'absent' => false,
        ]);

        $res = $this->actingAs($ctx['user'], 'sanctum')
            ->getJson("/api/v1/student/report-card/{$term->id}?period_id={$period->id}")
            ->assertOk();

        $this->assertSame($period->id, $res->json('data.scoped_period_id'));
        $this->assertEquals(14.0, $res->json('data.overall_average'));
    }

    public function test_student_can_list_terms(): void
    {
        $year = SchoolYear::factory()->create(['name' => '2023-2024']);
        Term::factory()->create(['school_year_id' => $year->id, 'name' => 'T1', 'position' => 1]);

        $ctx = $this->makeStudentUser();

        $this->actingAs($ctx['user'], 'sanctum')
            ->getJson('/api/v1/student/terms')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_student_terms_are_limited_to_their_cycle(): void
    {
        $year = SchoolYear::factory()->current()->create([
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-06-30',
        ]);
        $primaryLevel = Level::factory()->create(['cycle' => Level::CYCLE_PRIMAIRE]);
        $classroom = ClassRoom::factory()->create(['level_id' => $primaryLevel->id]);
        $user = User::factory()->create(['role' => UserRole::Eleve]);
        Student::factory()->create([
            'user_id' => $user->id,
            'classroom_id' => $classroom->id,
            'enrollment_school_year_id' => $year->id,
        ]);

        $primaryTerm = Term::factory()->create([
            'school_year_id' => $year->id,
            'name' => '1er Trimestre',
            'position' => 1,
            'applicable_cycle' => Term::CYCLE_PRIMAIRE,
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-12-20',
        ]);
        Term::factory()->create([
            'school_year_id' => $year->id,
            'name' => '1er Semestre',
            'position' => 4,
            'applicable_cycle' => Term::CYCLE_SECONDAIRE,
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-01-31',
        ]);

        $this->travelTo('2026-06-04');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/student/terms')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $primaryTerm->id)
            ->assertJsonPath('meta.recommended_term_id', $primaryTerm->id);
    }
}
