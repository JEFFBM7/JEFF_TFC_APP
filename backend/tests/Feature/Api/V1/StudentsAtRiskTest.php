<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\AppSetting;
use App\Models\Attendance;
use App\Models\ClassRoom;
use App\Models\Evaluation;
use App\Models\Grade;
use App\Models\Level;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\Term;
use App\Models\User;
use App\Support\AdminScopeContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentsAtRiskTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    /** @return array{classroom: ClassRoom, year: SchoolYear, term: Term} */
    private function setupContext(): array
    {
        $year = SchoolYear::factory()->current()->create([
            'name' => '2025-2026',
            'starts_on' => '2025-09-01',
            'ends_on' => '2026-06-30',
        ]);
        $term = Term::factory()->create([
            'school_year_id' => $year->id,
            'name' => 'Trimestre 1',
            'position' => 1,
            'starts_on' => '2025-09-01',
            'ends_on' => '2026-06-30',
        ]);
        $level = Level::factory()->create();
        $classroom = ClassRoom::factory()->create(['level_id' => $level->id, 'section' => 'A']);
        $term = $term;

        return compact('classroom', 'year', 'term');
    }

    public function test_non_admin_non_teacher_cannot_list(): void
    {
        $parent = User::factory()->create(['role' => UserRole::Parent]);

        $this->actingAs($parent, 'sanctum')
            ->getJson('/api/v1/students-at-risk')
            ->assertForbidden();
    }

    public function test_lists_students_with_absences_alert(): void
    {
        $ctx = $this->setupContext();
        $student = Student::factory()->create([
            'classroom_id' => $ctx['classroom']->id,
            'enrollment_school_year_id' => $ctx['year']->id,
        ]);

        foreach (['2026-04-01', '2026-04-02', '2026-04-03'] as $day) {
            Attendance::factory()->create([
                'student_id' => $student->id,
                'classroom_id' => $ctx['classroom']->id,
                'subject_id' => null,
                'date' => $day,
                'status' => Attendance::STATUS_ABSENT,
                'justified' => false,
            ]);
        }

        $this->travelTo('2026-04-04');

        $res = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/students-at-risk?type=absences')
            ->assertOk();

        $rows = $res->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame($student->id, $rows[0]['id']);
        $this->assertTrue($rows[0]['triggers']['has_absence_alert']);
        $this->assertGreaterThanOrEqual(3, $rows[0]['triggers']['absences_consecutive']);
    }

    public function test_lists_students_with_late_alert(): void
    {
        $ctx = $this->setupContext();
        $student = Student::factory()->create([
            'classroom_id' => $ctx['classroom']->id,
            'enrollment_school_year_id' => $ctx['year']->id,
        ]);

        AppSetting::set('attendance.late_threshold', 3);

        foreach (['2026-04-01', '2026-04-05', '2026-04-10'] as $day) {
            Attendance::factory()->create([
                'student_id' => $student->id,
                'classroom_id' => $ctx['classroom']->id,
                'subject_id' => null,
                'date' => $day,
                'status' => Attendance::STATUS_LATE,
                'justified' => false,
            ]);
        }

        $this->travelTo('2026-04-11');

        $res = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/students-at-risk?type=lates')
            ->assertOk();

        $rows = $res->json('data');
        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['triggers']['has_late_alert']);
    }

    public function test_threshold_changes_take_effect(): void
    {
        $ctx = $this->setupContext();
        $student = Student::factory()->create([
            'classroom_id' => $ctx['classroom']->id,
            'enrollment_school_year_id' => $ctx['year']->id,
        ]);

        foreach (['2026-04-01', '2026-04-02'] as $day) {
            Attendance::factory()->create([
                'student_id' => $student->id,
                'classroom_id' => $ctx['classroom']->id,
                'subject_id' => null,
                'date' => $day,
                'status' => Attendance::STATUS_ABSENT,
                'justified' => false,
            ]);
        }

        $this->travelTo('2026-04-03');

        // Avec le seuil par défaut (3), aucune alerte
        $res = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/students-at-risk?type=absences')
            ->assertOk();
        $this->assertCount(0, $res->json('data'));

        // On baisse le seuil à 2
        AppSetting::set('attendance.consecutive_threshold', 2);
        AppSetting::flushCache();

        $res = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/students-at-risk?type=absences')
            ->assertOk();
        $this->assertCount(1, $res->json('data'));
    }

    public function test_lists_students_with_low_grade_alert(): void
    {
        $ctx = $this->setupContext();
        $subject = Subject::factory()->create(['name' => 'Maths']);
        $ctx['classroom']->subjects()->attach($subject->id, ['coefficient' => 1]);

        $atRisk = Student::factory()->create([
            'classroom_id' => $ctx['classroom']->id,
            'enrollment_school_year_id' => $ctx['year']->id,
        ]);
        $ok = Student::factory()->create([
            'classroom_id' => $ctx['classroom']->id,
            'enrollment_school_year_id' => $ctx['year']->id,
        ]);
        $evaluation = Evaluation::factory()->create([
            'classroom_id' => $ctx['classroom']->id,
            'subject_id' => $subject->id,
            'term_id' => $ctx['term']->id,
        ]);
        Grade::query()->create(['evaluation_id' => $evaluation->id, 'student_id' => $atRisk->id, 'value' => 7, 'absent' => false]);
        Grade::query()->create(['evaluation_id' => $evaluation->id, 'student_id' => $ok->id, 'value' => 12, 'absent' => false]);

        $res = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/students-at-risk?type=low_grade&term_id='.$ctx['term']->id)
            ->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame($atRisk->id, $res->json('data.0.id'));
        $this->assertTrue($res->json('data.0.triggers.has_low_grade_alert'));
        $this->assertEquals(7.0, $res->json('data.0.average'));
    }

    public function test_global_admin_sees_low_grade_alerts_from_both_cycles(): void
    {
        $year = SchoolYear::factory()->current()->create([
            'name' => '2025-2026',
            'starts_on' => '2025-09-01',
            'ends_on' => '2026-06-30',
        ]);
        $primaryTerm = Term::factory()->create([
            'school_year_id' => $year->id,
            'name' => 'Trimestre 1',
            'position' => 1,
            'term_type' => Term::TYPE_TRIMESTRE,
            'applicable_cycle' => Term::CYCLE_PRIMAIRE,
            'starts_on' => '2025-09-01',
            'ends_on' => '2025-12-20',
        ]);
        $secondaryTerm = Term::factory()->create([
            'school_year_id' => $year->id,
            'name' => '1er Semestre',
            'position' => 4,
            'term_type' => Term::TYPE_SEMESTRE,
            'applicable_cycle' => Term::CYCLE_SECONDAIRE,
            'starts_on' => '2025-09-01',
            'ends_on' => '2026-01-31',
        ]);

        $primaryLevel = Level::factory()->create(['cycle' => Level::CYCLE_PRIMAIRE, 'name' => 'Global primaire']);
        $secondaryLevel = Level::factory()->create(['cycle' => Level::CYCLE_SECONDAIRE, 'name' => 'Global secondaire']);
        $primaryClass = ClassRoom::factory()->create(['level_id' => $primaryLevel->id, 'section' => 'A']);
        $secondaryClass = ClassRoom::factory()->create(['level_id' => $secondaryLevel->id, 'section' => 'A']);

        $primarySubject = Subject::factory()->create(['name' => 'Français']);
        $secondarySubject = Subject::factory()->create(['name' => 'Maths']);
        $primaryClass->subjects()->attach($primarySubject->id, ['coefficient' => 1]);
        $secondaryClass->subjects()->attach($secondarySubject->id, ['coefficient' => 1]);

        $primaryStudent = Student::factory()->create([
            'classroom_id' => $primaryClass->id,
            'enrollment_school_year_id' => $year->id,
        ]);
        $secondaryStudent = Student::factory()->create([
            'classroom_id' => $secondaryClass->id,
            'enrollment_school_year_id' => $year->id,
        ]);

        $primaryEval = Evaluation::factory()->create([
            'classroom_id' => $primaryClass->id,
            'subject_id' => $primarySubject->id,
            'term_id' => $primaryTerm->id,
        ]);
        $secondaryEval = Evaluation::factory()->create([
            'classroom_id' => $secondaryClass->id,
            'subject_id' => $secondarySubject->id,
            'term_id' => $secondaryTerm->id,
        ]);

        Grade::query()->create(['evaluation_id' => $primaryEval->id, 'student_id' => $primaryStudent->id, 'value' => 6, 'absent' => false]);
        Grade::query()->create(['evaluation_id' => $secondaryEval->id, 'student_id' => $secondaryStudent->id, 'value' => 5, 'absent' => false]);

        $this->travelTo('2025-10-15');

        $res = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/students-at-risk?type=low_grade')
            ->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($primaryStudent->id, $ids);
        $this->assertContains($secondaryStudent->id, $ids);
        $this->assertArrayHasKey('primaire', $res->json('meta.terms'));
        $this->assertArrayHasKey('secondaire', $res->json('meta.terms'));
    }

    public function test_primary_admin_does_not_see_secondary_students_at_risk(): void
    {
        $year = SchoolYear::factory()->current()->create([
            'name' => '2025-2026',
            'starts_on' => '2025-09-01',
            'ends_on' => '2026-06-30',
        ]);
        $primaryLevel = Level::factory()->create(['cycle' => Level::CYCLE_PRIMAIRE, 'name' => 'Primaire scope test']);
        $secondaryLevel = Level::factory()->create(['cycle' => Level::CYCLE_SECONDAIRE, 'name' => 'Secondaire scope test']);
        $primaryClass = ClassRoom::factory()->create(['level_id' => $primaryLevel->id, 'section' => 'A']);
        $secondaryClass = ClassRoom::factory()->create(['level_id' => $secondaryLevel->id, 'section' => 'A']);

        $primaryStudent = Student::factory()->create([
            'classroom_id' => $primaryClass->id,
            'enrollment_school_year_id' => $year->id,
        ]);
        $secondaryStudent = Student::factory()->create([
            'classroom_id' => $secondaryClass->id,
            'enrollment_school_year_id' => $year->id,
        ]);

        foreach ([$primaryStudent, $secondaryStudent] as $student) {
            foreach (['2026-04-01', '2026-04-02', '2026-04-03'] as $day) {
                Attendance::factory()->create([
                    'student_id' => $student->id,
                    'classroom_id' => $student->classroom_id,
                    'subject_id' => null,
                    'date' => $day,
                    'status' => Attendance::STATUS_ABSENT,
                    'justified' => false,
                ]);
            }
        }

        $this->travelTo('2026-04-04');

        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'admin_scope' => AdminScopeContext::PRIMARY_MATERNAL,
        ]);

        $res = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/students-at-risk?type=absences')
            ->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->all();

        $this->assertContains($primaryStudent->id, $ids);
        $this->assertNotContains($secondaryStudent->id, $ids);
        $this->assertSame(AdminScopeContext::PRIMARY_MATERNAL, $res->json('meta.admin_scope'));
    }

    public function test_secondary_admin_only_sees_secondary_students_at_risk(): void
    {
        $year = SchoolYear::factory()->current()->create([
            'name' => '2025-2026',
            'starts_on' => '2025-09-01',
            'ends_on' => '2026-06-30',
        ]);
        $primaryLevel = Level::factory()->create(['cycle' => Level::CYCLE_PRIMAIRE, 'name' => 'Primaire scope test B']);
        $secondaryLevel = Level::factory()->create(['cycle' => Level::CYCLE_SECONDAIRE, 'name' => 'Secondaire scope test B']);
        $primaryClass = ClassRoom::factory()->create(['level_id' => $primaryLevel->id, 'section' => 'A']);
        $secondaryClass = ClassRoom::factory()->create(['level_id' => $secondaryLevel->id, 'section' => 'A']);

        $primaryStudent = Student::factory()->create([
            'classroom_id' => $primaryClass->id,
            'enrollment_school_year_id' => $year->id,
        ]);
        $secondaryStudent = Student::factory()->create([
            'classroom_id' => $secondaryClass->id,
            'enrollment_school_year_id' => $year->id,
        ]);

        foreach ([$primaryStudent, $secondaryStudent] as $student) {
            foreach (['2026-04-01', '2026-04-02', '2026-04-03'] as $day) {
                Attendance::factory()->create([
                    'student_id' => $student->id,
                    'classroom_id' => $student->classroom_id,
                    'subject_id' => null,
                    'date' => $day,
                    'status' => Attendance::STATUS_ABSENT,
                    'justified' => false,
                ]);
            }
        }

        $this->travelTo('2026-04-04');

        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'admin_scope' => AdminScopeContext::SECONDARY_TECHNICAL,
        ]);

        $ids = collect($this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/students-at-risk?type=absences')
            ->assertOk()
            ->json('data'))
            ->pluck('id')
            ->all();

        $this->assertContains($secondaryStudent->id, $ids);
        $this->assertNotContains($primaryStudent->id, $ids);
    }

    public function test_cycle_admin_cannot_request_out_of_scope_cycle_filter(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'admin_scope' => AdminScopeContext::SECONDARY_TECHNICAL,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/students-at-risk?cycle=primaire')
            ->assertForbidden();
    }

    public function test_teacher_only_sees_assigned_students_at_risk(): void
    {
        $ctx = $this->setupContext();
        $otherClassroom = ClassRoom::factory()->create(['level_id' => $ctx['classroom']->level_id, 'section' => 'B']);
        $visible = Student::factory()->create([
            'classroom_id' => $ctx['classroom']->id,
            'enrollment_school_year_id' => $ctx['year']->id,
        ]);
        $hidden = Student::factory()->create([
            'classroom_id' => $otherClassroom->id,
            'enrollment_school_year_id' => $ctx['year']->id,
        ]);

        foreach ([$visible, $hidden] as $student) {
            foreach (['2026-04-01', '2026-04-02', '2026-04-03'] as $day) {
                Attendance::factory()->create([
                    'student_id' => $student->id,
                    'classroom_id' => $student->classroom_id,
                    'subject_id' => null,
                    'date' => $day,
                    'status' => Attendance::STATUS_ABSENT,
                    'justified' => false,
                ]);
            }
        }

        $teacher = Teacher::factory()->create();
        TeacherAssignment::query()->create([
            'teacher_id' => $teacher->id,
            'classroom_id' => $ctx['classroom']->id,
            'subject_id' => null,
            'school_year_id' => $ctx['year']->id,
            'term_id' => null,
            'weekly_hours' => 1,
            'is_main' => true,
        ]);

        $this->travelTo('2026-04-04');

        $res = $this->actingAs($teacher->user, 'sanctum')
            ->getJson('/api/v1/students-at-risk?type=absences')
            ->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame($visible->id, $res->json('data.0.id'));
    }
}
