<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\ClassRoom;
use App\Models\Evaluation;
use App\Models\Grade;
use App\Models\Level;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    // ─── Admin dashboard ─────────────────────────────────────────────────

    public function test_parent_cannot_access_admin_dashboard(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Parent]), 'sanctum')
            ->getJson('/api/v1/admin/dashboard')
            ->assertForbidden();
    }

    public function test_admin_dashboard_returns_counts(): void
    {
        $level = Level::factory()->create();
        $classroom = ClassRoom::factory()->create(['level_id' => $level->id, 'section' => 'A']);
        Student::factory()->count(3)->create(['classroom_id' => $classroom->id]);
        Subject::factory()->create(['name' => 'Maths']);

        $res = $this->actingAs(User::factory()->create(['role' => UserRole::Admin]), 'sanctum')
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk();

        $this->assertSame(3, $res->json('data.counts.students'));
        $this->assertSame(1, $res->json('data.counts.classrooms'));
        $this->assertSame(1, $res->json('data.counts.subjects'));
        $res->assertJsonStructure([
            'data' => [
                'insights' => [
                    'institution_average',
                    'institution_average_delta',
                    'students_at_risk_count',
                    'classes_with_unjustified_absences',
                    'low_grade_threshold',
                    'attendance_breakdown' => [
                        'present_pct',
                        'justified_absences_pct',
                        'unjustified_absences_pct',
                    ],
                    'top_students',
                    'watchlist',
                ],
                'monthly_averages',
            ],
        ]);
    }

    public function test_admin_dashboard_includes_attendance_stats(): void
    {
        $level = Level::factory()->create();
        $classroom = ClassRoom::factory()->create(['level_id' => $level->id, 'section' => 'B']);
        $student = Student::factory()->create(['classroom_id' => $classroom->id]);

        Attendance::factory()->count(2)->create([
            'student_id' => $student->id,
            'classroom_id' => $classroom->id,
            'status' => Attendance::STATUS_ABSENT,
            'justified' => false,
        ]);
        Attendance::factory()->create([
            'student_id' => $student->id,
            'classroom_id' => $classroom->id,
            'status' => Attendance::STATUS_LATE,
        ]);

        $res = $this->actingAs(User::factory()->create(['role' => UserRole::Admin]), 'sanctum')
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk();

        $this->assertSame(2, $res->json('data.attendance.total_absences'));
        $this->assertSame(2, $res->json('data.attendance.unjustified'));
        $this->assertSame(1, $res->json('data.attendance.total_lates'));
    }

    public function test_admin_dashboard_period_filters_attendance_stats(): void
    {
        $level = Level::factory()->create();
        $classroom = ClassRoom::factory()->create(['level_id' => $level->id, 'section' => 'B']);
        $student = Student::factory()->create(['classroom_id' => $classroom->id]);

        Attendance::factory()->create([
            'student_id' => $student->id,
            'classroom_id' => $classroom->id,
            'date' => now()->toDateString(),
            'status' => Attendance::STATUS_ABSENT,
            'justified' => false,
        ]);
        Attendance::factory()->create([
            'student_id' => $student->id,
            'classroom_id' => $classroom->id,
            'date' => now()->subMonths(2)->toDateString(),
            'status' => Attendance::STATUS_ABSENT,
            'justified' => false,
        ]);

        $res = $this->actingAs(User::factory()->create(['role' => UserRole::Admin]), 'sanctum')
            ->getJson('/api/v1/admin/dashboard?period=week')
            ->assertOk();

        $this->assertSame('week', $res->json('data.period.key'));
        $this->assertSame(1, $res->json('data.attendance.total_absences'));
    }

    public function test_admin_dashboard_month_period_filters_attendance_stats(): void
    {
        SchoolYear::factory()->current()->create([
            'name' => '2025-2026',
            'starts_on' => '2025-09-01',
            'ends_on' => '2026-07-31',
        ]);

        $level = Level::factory()->create();
        $classroom = ClassRoom::factory()->create(['level_id' => $level->id, 'section' => 'B']);
        $student = Student::factory()->create(['classroom_id' => $classroom->id]);

        Attendance::factory()->create([
            'student_id' => $student->id,
            'classroom_id' => $classroom->id,
            'date' => '2026-03-12',
            'status' => Attendance::STATUS_ABSENT,
            'justified' => false,
        ]);
        Attendance::factory()->create([
            'student_id' => $student->id,
            'classroom_id' => $classroom->id,
            'date' => '2026-04-12',
            'status' => Attendance::STATUS_ABSENT,
            'justified' => false,
        ]);

        $res = $this->actingAs(User::factory()->create(['role' => UserRole::Admin]), 'sanctum')
            ->getJson('/api/v1/admin/dashboard?period=month&month=2026-03')
            ->assertOk();

        $this->assertSame('month', $res->json('data.period.key'));
        $this->assertSame('Mars 2026', $res->json('data.period.label'));
        $this->assertSame('2026-03-01', $res->json('data.period.starts_on'));
        $this->assertSame('2026-03-31', $res->json('data.period.ends_on'));
        $this->assertSame(1, $res->json('data.attendance.total_absences'));
        $this->assertContains('2026-03', collect($res->json('data.available_months'))->pluck('value')->all());
    }

    public function test_admin_dashboard_includes_monthly_attendance_timeline(): void
    {
        SchoolYear::factory()->current()->create([
            'name' => '2025-2026',
            'starts_on' => '2025-09-01',
            'ends_on' => '2026-06-30',
        ]);

        $level = Level::factory()->create();
        $classroom = ClassRoom::factory()->create(['level_id' => $level->id]);
        $student = Student::factory()->create(['classroom_id' => $classroom->id]);
        Attendance::factory()->create([
            'student_id' => $student->id,
            'classroom_id' => $classroom->id,
            'date' => '2025-10-10',
            'status' => Attendance::STATUS_ABSENT,
        ]);
        Attendance::factory()->create([
            'student_id' => $student->id,
            'classroom_id' => $classroom->id,
            'date' => '2025-10-11',
            'status' => Attendance::STATUS_LATE,
        ]);

        $res = $this->actingAs(User::factory()->create(['role' => UserRole::Admin]), 'sanctum')
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk();

        $october = collect($res->json('data.monthly_attendance'))->firstWhere('value', '2025-10');
        $this->assertSame(1, $october['absences']);
        $this->assertSame(1, $october['lates']);
    }

    public function test_admin_dashboard_term_period_filters_attendance_stats(): void
    {
        $year = SchoolYear::factory()->current()->create([
            'name' => '2025-2026',
            'starts_on' => '2025-09-01',
            'ends_on' => '2026-07-31',
        ]);
        $termOne = Term::factory()->create([
            'school_year_id' => $year->id,
            'name' => 'Trimestre 1',
            'position' => 1,
            'starts_on' => '2025-09-01',
            'ends_on' => '2025-12-15',
        ]);
        Term::factory()->create([
            'school_year_id' => $year->id,
            'name' => 'Trimestre 2',
            'position' => 2,
            'starts_on' => '2026-01-05',
            'ends_on' => '2026-03-31',
        ]);

        $level = Level::factory()->create();
        $classroom = ClassRoom::factory()->create(['level_id' => $level->id, 'section' => 'B']);
        $student = Student::factory()->create(['classroom_id' => $classroom->id]);

        Attendance::factory()->create([
            'student_id' => $student->id,
            'classroom_id' => $classroom->id,
            'date' => '2025-10-12',
            'status' => Attendance::STATUS_ABSENT,
            'justified' => false,
        ]);
        Attendance::factory()->create([
            'student_id' => $student->id,
            'classroom_id' => $classroom->id,
            'date' => '2026-02-12',
            'status' => Attendance::STATUS_ABSENT,
            'justified' => false,
        ]);

        $res = $this->actingAs(User::factory()->create(['role' => UserRole::Admin]), 'sanctum')
            ->getJson("/api/v1/admin/dashboard?period=term&term_id={$termOne->id}")
            ->assertOk();

        $this->assertSame('term', $res->json('data.period.key'));
        $this->assertSame('Trimestre 1', $res->json('data.period.label'));
        $this->assertSame('2025-09-01', $res->json('data.period.starts_on'));
        $this->assertSame('2025-12-15', $res->json('data.period.ends_on'));
        $this->assertSame(1, $res->json('data.attendance.total_absences'));
        $this->assertContains((string) $termOne->id, collect($res->json('data.available_terms'))->pluck('value')->all());
    }

    // ─── Teacher dashboard ───────────────────────────────────────────────

    public function test_parent_cannot_access_teacher_dashboard(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Parent]), 'sanctum')
            ->getJson('/api/v1/teacher/dashboard')
            ->assertForbidden();
    }

    public function test_teacher_dashboard_returns_assignments(): void
    {
        $teacherUser = User::factory()->create(['role' => UserRole::Enseignant]);
        $teacher = Teacher::factory()->create(['user_id' => $teacherUser->id]);

        $level = Level::factory()->create();
        $classroom = ClassRoom::factory()->create(['level_id' => $level->id, 'section' => 'C']);
        $subject = Subject::factory()->create(['name' => 'Physique']);
        $year = SchoolYear::factory()->create(['name' => '2025-2026']);

        TeacherAssignment::query()->create([
            'teacher_id' => $teacher->id,
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
            'school_year_id' => $year->id,
        ]);

        Student::factory()->count(2)->create(['classroom_id' => $classroom->id]);

        $res = $this->actingAs($teacherUser, 'sanctum')
            ->getJson('/api/v1/teacher/dashboard')
            ->assertOk();

        $this->assertSame($teacherUser->name, $res->json('data.teacher_name'));
        $this->assertCount(1, $res->json('data.assignments'));
        $this->assertSame('Physique', $res->json('data.assignments.0.subject'));
        $this->assertSame(2, $res->json('data.assignments.0.student_count'));
    }

    public function test_teacher_dashboard_period_filters_evaluations_and_grades(): void
    {
        $teacherUser = User::factory()->create(['role' => UserRole::Enseignant]);
        $teacher = Teacher::factory()->create(['user_id' => $teacherUser->id]);

        $level = Level::factory()->create();
        $classroom = ClassRoom::factory()->create(['level_id' => $level->id, 'section' => 'C']);
        $student = Student::factory()->create(['classroom_id' => $classroom->id]);
        $subject = Subject::factory()->create(['name' => 'Physique']);
        $year = SchoolYear::factory()->create(['name' => '2025-2026']);
        $term = Term::factory()->create(['school_year_id' => $year->id]);

        TeacherAssignment::query()->create([
            'teacher_id' => $teacher->id,
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
            'school_year_id' => $year->id,
        ]);

        $currentEvaluation = Evaluation::factory()->create([
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
            'term_id' => $term->id,
            'held_on' => now()->toDateString(),
        ]);
        $oldEvaluation = Evaluation::factory()->create([
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
            'term_id' => $term->id,
            'held_on' => now()->subMonths(2)->toDateString(),
        ]);

        Grade::query()->create([
            'evaluation_id' => $currentEvaluation->id,
            'student_id' => $student->id,
            'value' => 14,
            'absent' => false,
        ]);
        Grade::query()->create([
            'evaluation_id' => $oldEvaluation->id,
            'student_id' => $student->id,
            'value' => 8,
            'absent' => false,
        ]);

        $res = $this->actingAs($teacherUser, 'sanctum')
            ->getJson('/api/v1/teacher/dashboard?period=week')
            ->assertOk();

        $this->assertSame('week', $res->json('data.period.key'));
        $this->assertSame(1, $res->json('data.assignments.0.evaluations'));
        $this->assertSame(1, $res->json('data.assignments.0.grades_entered'));
        $this->assertSame(14, $res->json('data.assignments.0.class_average'));
    }

    public function test_teacher_dashboard_month_period_filters_evaluations_and_grades(): void
    {
        $teacherUser = User::factory()->create(['role' => UserRole::Enseignant]);
        $teacher = Teacher::factory()->create(['user_id' => $teacherUser->id]);

        $level = Level::factory()->create();
        $classroom = ClassRoom::factory()->create(['level_id' => $level->id, 'section' => 'C']);
        $student = Student::factory()->create(['classroom_id' => $classroom->id]);
        $subject = Subject::factory()->create(['name' => 'Physique']);
        $year = SchoolYear::factory()->current()->create([
            'name' => '2025-2026',
            'starts_on' => '2025-09-01',
            'ends_on' => '2026-07-31',
        ]);
        $term = Term::factory()->create([
            'school_year_id' => $year->id,
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-03-31',
        ]);

        TeacherAssignment::query()->create([
            'teacher_id' => $teacher->id,
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
            'school_year_id' => $year->id,
        ]);

        $marchEvaluation = Evaluation::factory()->create([
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
            'term_id' => $term->id,
            'held_on' => '2026-03-10',
        ]);
        $aprilEvaluation = Evaluation::factory()->create([
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
            'term_id' => $term->id,
            'held_on' => '2026-04-10',
        ]);

        Grade::query()->create([
            'evaluation_id' => $marchEvaluation->id,
            'student_id' => $student->id,
            'value' => 14,
            'absent' => false,
        ]);
        Grade::query()->create([
            'evaluation_id' => $aprilEvaluation->id,
            'student_id' => $student->id,
            'value' => 8,
            'absent' => false,
        ]);

        $res = $this->actingAs($teacherUser, 'sanctum')
            ->getJson('/api/v1/teacher/dashboard?period=month&month=2026-03')
            ->assertOk();

        $this->assertSame('month', $res->json('data.period.key'));
        $this->assertSame('Mars 2026', $res->json('data.period.label'));
        $this->assertSame(1, $res->json('data.assignments.0.evaluations'));
        $this->assertSame(1, $res->json('data.assignments.0.grades_entered'));
        $this->assertSame(14, $res->json('data.assignments.0.class_average'));
        $this->assertContains('2026-03', collect($res->json('data.available_months'))->pluck('value')->all());
    }

    public function test_teacher_dashboard_term_period_filters_evaluations_and_grades(): void
    {
        $teacherUser = User::factory()->create(['role' => UserRole::Enseignant]);
        $teacher = Teacher::factory()->create(['user_id' => $teacherUser->id]);

        $level = Level::factory()->create();
        $classroom = ClassRoom::factory()->create(['level_id' => $level->id, 'section' => 'C']);
        $student = Student::factory()->create(['classroom_id' => $classroom->id]);
        $subject = Subject::factory()->create(['name' => 'Physique']);
        $year = SchoolYear::factory()->current()->create([
            'name' => '2025-2026',
            'starts_on' => '2025-09-01',
            'ends_on' => '2026-07-31',
        ]);
        $termOne = Term::factory()->create([
            'school_year_id' => $year->id,
            'name' => 'Trimestre 1',
            'position' => 1,
            'starts_on' => '2025-09-01',
            'ends_on' => '2025-12-15',
        ]);
        $termTwo = Term::factory()->create([
            'school_year_id' => $year->id,
            'name' => 'Trimestre 2',
            'position' => 2,
            'starts_on' => '2026-01-05',
            'ends_on' => '2026-03-31',
        ]);

        TeacherAssignment::query()->create([
            'teacher_id' => $teacher->id,
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
            'school_year_id' => $year->id,
        ]);

        $termOneEvaluation = Evaluation::factory()->create([
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
            'term_id' => $termOne->id,
            'held_on' => '2025-10-10',
        ]);
        $termTwoEvaluation = Evaluation::factory()->create([
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
            'term_id' => $termTwo->id,
            'held_on' => '2026-02-10',
        ]);

        Grade::query()->create([
            'evaluation_id' => $termOneEvaluation->id,
            'student_id' => $student->id,
            'value' => 15,
            'absent' => false,
        ]);
        Grade::query()->create([
            'evaluation_id' => $termTwoEvaluation->id,
            'student_id' => $student->id,
            'value' => 7,
            'absent' => false,
        ]);

        $res = $this->actingAs($teacherUser, 'sanctum')
            ->getJson("/api/v1/teacher/dashboard?period=term&term_id={$termOne->id}")
            ->assertOk();

        $this->assertSame('term', $res->json('data.period.key'));
        $this->assertSame('Trimestre 1', $res->json('data.period.label'));
        $this->assertSame(1, $res->json('data.assignments.0.evaluations'));
        $this->assertSame(1, $res->json('data.assignments.0.grades_entered'));
        $this->assertSame(15, $res->json('data.assignments.0.class_average'));
        $this->assertContains((string) $termOne->id, collect($res->json('data.available_terms'))->pluck('value')->all());
    }

    public function test_teacher_without_profile_gets_empty_dashboard(): void
    {
        $teacherUser = User::factory()->create(['role' => UserRole::Enseignant]);

        $this->actingAs($teacherUser, 'sanctum')
            ->getJson('/api/v1/teacher/dashboard')
            ->assertOk()
            ->assertJsonPath('data.assignments', []);
    }
}
