<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\ClassRoom;
use App\Models\Evaluation;
use App\Models\Grade;
use App\Models\Level;
use App\Models\ParentProfile;
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

class StudentTimelineTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{year: SchoolYear, term: Term, period: Period, classroom: ClassRoom, student: Student, subject: Subject} */
    private function setupTimelineContext(): array
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
            'ends_on' => '2025-12-31',
        ]);
        $period = Period::factory()->create([
            'term_id' => $term->id,
            'name' => 'Période 1',
            'position' => 1,
            'starts_on' => '2025-09-01',
            'ends_on' => '2025-10-31',
        ]);
        $level = Level::factory()->create();
        $classroom = ClassRoom::factory()->create(['level_id' => $level->id]);
        $subject = Subject::factory()->create(['name' => 'Maths']);
        $classroom->subjects()->attach($subject->id, ['coefficient' => 1]);

        $student = Student::factory()->create([
            'classroom_id' => $classroom->id,
            'enrollment_school_year_id' => $year->id,
        ]);
        $evaluation = Evaluation::factory()->create([
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
            'term_id' => $term->id,
            'period_id' => $period->id,
        ]);
        Grade::query()->create([
            'evaluation_id' => $evaluation->id,
            'student_id' => $student->id,
            'value' => 14,
            'absent' => false,
        ]);
        Attendance::factory()->create([
            'student_id' => $student->id,
            'classroom_id' => $classroom->id,
            'subject_id' => null,
            'date' => '2025-10-10',
            'status' => Attendance::STATUS_ABSENT,
            'justified' => false,
        ]);
        Attendance::factory()->create([
            'student_id' => $student->id,
            'classroom_id' => $classroom->id,
            'subject_id' => null,
            'date' => '2025-10-11',
            'status' => Attendance::STATUS_LATE,
            'justified' => false,
        ]);
        return compact('year', 'term', 'period', 'classroom', 'student', 'subject');
    }

    public function test_admin_can_read_student_timeline(): void
    {
        $ctx = $this->setupTimelineContext();
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $res = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/students/{$ctx['student']->id}/timeline")
            ->assertOk();

        $this->assertEquals(14.0, $res->json('data.term_averages.0.average'));
        $this->assertEquals(14.0, $res->json('data.period_averages.0.average'));

        $october = collect($res->json('data.monthly_attendance'))->firstWhere('value', '2025-10');
        $this->assertSame(1, $october['absences']);
        $this->assertSame(1, $october['lates']);
    }

    public function test_teacher_needs_assignment_to_read_student_timeline(): void
    {
        $ctx = $this->setupTimelineContext();
        $teacher = Teacher::factory()->create();

        $this->actingAs($teacher->user, 'sanctum')
            ->getJson("/api/v1/students/{$ctx['student']->id}/timeline")
            ->assertForbidden();

        TeacherAssignment::query()->create([
            'teacher_id' => $teacher->id,
            'classroom_id' => $ctx['classroom']->id,
            'subject_id' => $ctx['subject']->id,
            'school_year_id' => $ctx['year']->id,
            'term_id' => $ctx['term']->id,
            'weekly_hours' => 2,
            'is_main' => false,
        ]);

        $this->actingAs($teacher->user, 'sanctum')
            ->getJson("/api/v1/students/{$ctx['student']->id}/timeline")
            ->assertOk();
    }

    public function test_student_portal_reads_own_timeline(): void
    {
        $ctx = $this->setupTimelineContext();
        $studentUser = User::factory()->create(['role' => UserRole::Eleve]);
        $ctx['student']->update(['user_id' => $studentUser->id]);

        $this->actingAs($studentUser, 'sanctum')
            ->getJson('/api/v1/student/timeline')
            ->assertOk()
            ->assertJsonPath('data.term_averages.0.average', 14)
            ->assertJsonPath('data.period_averages.0.average', 14);
    }

    public function test_parent_can_only_read_child_timeline(): void
    {
        $ctx = $this->setupTimelineContext();
        $parentUser = User::factory()->create(['role' => UserRole::Parent]);
        $profile = ParentProfile::factory()->create(['user_id' => $parentUser->id]);
        $ctx['student']->parents()->attach($profile->id, ['relation' => 'pere']);

        $other = Student::factory()->create([
            'classroom_id' => $ctx['classroom']->id,
            'enrollment_school_year_id' => $ctx['year']->id,
        ]);

        $this->actingAs($parentUser, 'sanctum')
            ->getJson("/api/v1/parent/children/{$ctx['student']->id}/timeline")
            ->assertOk()
            ->assertJsonPath('data.term_averages.0.average', 14)
            ->assertJsonPath('data.period_averages.0.average', 14);

        $this->actingAs($parentUser, 'sanctum')
            ->getJson("/api/v1/parent/children/{$other->id}/timeline")
            ->assertForbidden();
    }
}
