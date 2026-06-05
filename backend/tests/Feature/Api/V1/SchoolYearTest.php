<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\ClassRoom;
use App\Models\Evaluation;
use App\Models\Grade;
use App\Models\Level;
use App\Models\ParentProfile;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\Term;
use App\Models\TimetableSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchoolYearTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    public function test_parent_cannot_list_school_years(): void
    {
        $user = User::factory()->create(['role' => UserRole::Parent]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/school-years')
            ->assertForbidden();
    }

    public function test_admin_can_create_school_year(): void
    {
        $payload = [
            'name' => '2026-2027',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-06-30',
            'is_current' => true,
        ];

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/school-years', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.name', '2026-2027')
            ->assertJsonPath('data.is_current', true);

        $this->assertDatabaseHas('school_years', [
            'name' => '2026-2027',
            'is_current' => true,
        ]);
    }

    public function test_creating_a_current_year_uncurrents_others(): void
    {
        SchoolYear::factory()->current()->create(['name' => '2025-2026']);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/school-years', [
                'name' => '2026-2027',
                'starts_on' => '2026-09-01',
                'ends_on' => '2027-06-30',
                'is_current' => true,
            ])
            ->assertCreated();

        $this->assertSame(1, SchoolYear::query()->where('is_current', true)->count());
    }

    public function test_saving_current_year_directly_uncurrents_others(): void
    {
        $previous = SchoolYear::factory()->current()->create(['name' => '2025-2026']);
        $current = SchoolYear::factory()->create(['name' => '2026-2027']);

        $current->forceFill(['is_current' => true])->save();

        $this->assertFalse($previous->fresh()->is_current);
        $this->assertTrue($current->fresh()->is_current);
        $this->assertSame(1, SchoolYear::query()->where('is_current', true)->count());
    }

    public function test_unique_name(): void
    {
        SchoolYear::factory()->create(['name' => '2026-2027']);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/school-years', [
                'name' => '2026-2027',
                'starts_on' => '2026-09-01',
                'ends_on' => '2027-06-30',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_ends_on_after_starts_on(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/school-years', [
                'name' => '2026-2027',
                'starts_on' => '2027-06-30',
                'ends_on' => '2026-09-01',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ends_on']);
    }

    public function test_admin_can_update_and_delete(): void
    {
        $year = SchoolYear::factory()->create(['name' => '2026-2027']);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/v1/school-years/{$year->id}", [
                'name' => '2026-2027 (modifié)',
                'starts_on' => '2026-09-01',
                'ends_on' => '2027-06-30',
                'is_current' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', '2026-2027 (modifié)');

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/v1/school-years/{$year->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('school_years', ['id' => $year->id]);
    }

    public function test_show_school_year_includes_annual_stats(): void
    {
        $year = SchoolYear::factory()->create([
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
        $classroom = ClassRoom::factory()->create(['level_id' => $level->id]);
        $student = Student::factory()->create([
            'classroom_id' => $classroom->id,
            'enrollment_school_year_id' => $year->id,
        ]);
        $parent = ParentProfile::factory()->create();
        $parent->students()->attach($student->id, ['relation' => 'mere']);
        $subject = Subject::factory()->create();
        $teacher = Teacher::factory()->create();

        TeacherAssignment::query()->create([
            'teacher_id' => $teacher->id,
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
            'school_year_id' => $year->id,
            'is_main' => true,
        ]);

        $firstEvaluation = Evaluation::factory()->create([
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
            'term_id' => $termOne->id,
            'teacher_id' => $teacher->id,
            'held_on' => '2025-10-10',
        ]);
        $secondEvaluation = Evaluation::factory()->create([
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
            'term_id' => $termOne->id,
            'teacher_id' => $teacher->id,
            'held_on' => '2025-11-10',
        ]);

        Grade::query()->create([
            'evaluation_id' => $firstEvaluation->id,
            'student_id' => $student->id,
            'value' => 12,
            'absent' => false,
        ]);
        Grade::query()->create([
            'evaluation_id' => $secondEvaluation->id,
            'student_id' => $student->id,
            'value' => 16,
            'absent' => false,
        ]);

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
            'date' => '2025-10-13',
            'status' => Attendance::STATUS_LATE,
        ]);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/v1/school-years/{$year->id}")
            ->assertOk();

        $response->assertJsonPath('data.stats.summary.terms', 2)
            ->assertJsonPath('data.stats.summary.classes', 1)
            ->assertJsonPath('data.stats.summary.students', 1)
            ->assertJsonPath('data.stats.summary.parents', 1)
            ->assertJsonPath('data.stats.summary.teacher_assignments', 1)
            ->assertJsonPath('data.stats.summary.evaluations', 2)
            ->assertJsonPath('data.stats.summary.grades_entered', 2)
            ->assertJsonPath('data.stats.summary.grade_average', 14)
            ->assertJsonPath('data.stats.summary.absences', 1)
            ->assertJsonPath('data.stats.summary.lates', 1)
            ->assertJsonPath('data.stats.terms.0.evaluations', 2)
            ->assertJsonPath('data.stats.terms.0.grade_average', 14)
            ->assertJsonPath('data.stats.class_averages.0.student_count', 1)
            ->assertJsonPath('data.stats.class_averages.0.parent_count', 1)
            ->assertJsonPath('data.stats.class_averages.0.teacher_count', 1)
            ->assertJsonPath('data.stats.class_averages.0.subject_count', 1)
            ->assertJsonPath('data.stats.class_averages.0.attendance_records', 2)
            ->assertJsonPath('data.stats.class_averages.0.grade_average', 14);

        $this->assertContains('2025-10', collect($response->json('data.stats.monthly_attendance'))->pluck('value')->all());
    }

    public function test_school_year_stats_include_divisions_without_activity(): void
    {
        $year = SchoolYear::factory()->create([
            'name' => '2026-2027',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-06-30',
        ]);

        $level = Level::factory()->create(['cycle' => Level::CYCLE_CTEB, 'name' => '7e CTEB']);
        $activeClassroom = ClassRoom::factory()->create(['level_id' => $level->id, 'section' => 'A']);
        $inactiveClassroom = ClassRoom::factory()->create(['level_id' => $level->id, 'section' => 'B']);

        $activeSchoolClass = \App\Models\SchoolClass::factory()->create([
            'school_year_id' => $year->id,
            'level_id' => $level->id,
            'name' => '7EB',
        ]);
        $inactiveSchoolClass = \App\Models\SchoolClass::factory()->create([
            'school_year_id' => $year->id,
            'level_id' => $level->id,
            'name' => '7EB-2',
        ]);

        $activeClassroom->update(['school_class_id' => $activeSchoolClass->id]);
        $inactiveClassroom->update(['school_class_id' => $inactiveSchoolClass->id]);

        $subject = Subject::factory()->create();
        $teacher = Teacher::factory()->create();

        TeacherAssignment::query()->create([
            'teacher_id' => $teacher->id,
            'classroom_id' => $activeClassroom->id,
            'subject_id' => $subject->id,
            'school_year_id' => $year->id,
            'is_main' => true,
        ]);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/v1/school-years/{$year->id}")
            ->assertOk();

        $classroomIds = collect($response->json('data.stats.class_averages'))->pluck('classroom_id');

        $this->assertTrue($classroomIds->contains($activeClassroom->id));
        $this->assertTrue($classroomIds->contains($inactiveClassroom->id));
        $this->assertSame(2, $response->json('data.stats.summary.classes'));
    }

    public function test_admin_can_archive_and_unarchive_school_year(): void
    {
        $admin = $this->admin();
        $year = SchoolYear::factory()->current()->create(['name' => '2024-2025']);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/school-years/{$year->id}/archive")
            ->assertOk()
            ->assertJsonPath('data.is_archived', true)
            ->assertJsonPath('data.is_current', false)
            ->assertJsonPath('data.archived_by.id', $admin->id);

        $this->assertNotNull($year->fresh()->archived_at);
        $this->assertNotNull($year->fresh()->closed_at);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/school-years/{$year->id}/unarchive")
            ->assertOk()
            ->assertJsonPath('data.is_archived', false);

        $this->assertNull($year->fresh()->archived_at);
        $this->assertNull($year->fresh()->archived_by_id);
    }

    public function test_archived_school_year_blocks_updates_and_deletes(): void
    {
        $year = SchoolYear::factory()->archived()->create(['name' => '2020-2021']);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/v1/school-years/{$year->id}", [
                'name' => '2020-2021 (modifié)',
                'starts_on' => '2020-09-01',
                'ends_on' => '2021-06-30',
            ])
            ->assertStatus(423);

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/v1/school-years/{$year->id}")
            ->assertStatus(423);

        $this->assertDatabaseHas('school_years', ['id' => $year->id, 'name' => '2020-2021']);
    }

    public function test_show_school_year_includes_students_and_history(): void
    {
        $year = SchoolYear::factory()->create([
            'name' => '2025-2026',
            'starts_on' => '2025-09-01',
            'ends_on' => '2026-07-31',
        ]);
        $term = Term::factory()->create([
            'school_year_id' => $year->id,
            'starts_on' => '2025-09-01',
            'ends_on' => '2025-12-15',
        ]);
        $level = Level::factory()->create();
        $classroom = ClassRoom::factory()->create(['level_id' => $level->id]);
        $student = Student::factory()->create([
            'classroom_id' => $classroom->id,
            'enrollment_school_year_id' => $year->id,
            'first_name' => 'Eve',
            'last_name' => 'Mukendi',
            'middle_name' => 'Ilunga',
        ]);
        $subject = Subject::factory()->create();
        $teacher = Teacher::factory()->create();
        TeacherAssignment::query()->create([
            'teacher_id' => $teacher->id,
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
            'school_year_id' => $year->id,
        ]);
        $evaluation = Evaluation::factory()->create([
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
            'term_id' => $term->id,
            'teacher_id' => $teacher->id,
            'name' => 'Interrogation 1',
            'type' => 'controle',
            'held_on' => '2025-10-10',
        ]);
        Grade::query()->create([
            'evaluation_id' => $evaluation->id,
            'student_id' => $student->id,
            'value' => 14,
            'absent' => false,
        ]);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/v1/school-years/{$year->id}")
            ->assertOk();

        $this->assertSame(100.0, (float) $response->json('data.stats.summary.success_rate'));
        $response->assertJsonPath('data.stats.summary.students_evaluated', 1)
            ->assertJsonPath('data.stats.students.0.full_name', 'Mukendi Ilunga Eve')
            ->assertJsonPath('data.stats.students.0.classroom', $classroom->full_name)
            ->assertJsonPath('data.stats.students.0.final_status', 'admis')
            ->assertJsonPath('data.stats.history.terms.0.id', $term->id);
    }

    public function test_admin_can_view_school_year_classroom_details(): void
    {
        $year = SchoolYear::factory()->create([
            'name' => '2025-2026',
            'starts_on' => '2025-09-01',
            'ends_on' => '2026-07-31',
        ]);
        $term = Term::factory()->create([
            'school_year_id' => $year->id,
            'starts_on' => '2025-09-01',
            'ends_on' => '2025-12-15',
        ]);
        $level = Level::factory()->create(['name' => '6ème']);
        $classroom = ClassRoom::factory()->create(['level_id' => $level->id, 'section' => 'A']);
        $student = Student::factory()->create([
            'classroom_id' => $classroom->id,
            'enrollment_school_year_id' => $year->id,
            'first_name' => 'Marie',
            'last_name' => 'Kabongo',
            'middle_name' => 'Ilunga',
            'registration_number' => 'EDU-001',
        ]);
        $parentUser = User::factory()->create([
            'role' => UserRole::Parent,
            'name' => 'Parent Kabongo',
            'email' => 'parent@example.test',
        ]);
        $parent = ParentProfile::factory()->create([
            'user_id' => $parentUser->id,
            'phone' => '+243000000',
        ]);
        $parent->students()->attach($student->id, ['relation' => 'mere']);

        $subject = Subject::factory()->create(['name' => 'Mathématiques']);
        $teacherUser = User::factory()->create([
            'role' => UserRole::Enseignant,
            'name' => 'Mme Kabasele',
            'email' => 'teacher@example.test',
        ]);
        $teacher = Teacher::factory()->create([
            'user_id' => $teacherUser->id,
            'speciality' => 'Mathématiques',
        ]);

        TeacherAssignment::query()->create([
            'teacher_id' => $teacher->id,
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
            'school_year_id' => $year->id,
            'is_main' => true,
        ]);
        TimetableSlot::query()->create([
            'teacher_id' => $teacher->id,
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
            'school_year_id' => $year->id,
            'day_of_week' => 1,
            'starts_at' => '08:00',
            'ends_at' => '09:00',
            'room' => 'A12',
        ]);

        $evaluation = Evaluation::factory()->create([
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
            'term_id' => $term->id,
            'teacher_id' => $teacher->id,
            'name' => 'Interrogation 1',
            'type' => 'controle',
            'held_on' => '2025-10-10',
        ]);
        Grade::query()->create([
            'evaluation_id' => $evaluation->id,
            'student_id' => $student->id,
            'value' => 15,
            'absent' => false,
        ]);
        Attendance::factory()->create([
            'student_id' => $student->id,
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
            'date' => '2025-10-01',
            'status' => Attendance::STATUS_PRESENT,
        ]);
        Attendance::factory()->create([
            'student_id' => $student->id,
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
            'date' => '2025-10-02',
            'status' => Attendance::STATUS_ABSENT,
            'justified' => false,
        ]);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/v1/school-years/{$year->id}/classrooms/{$classroom->id}/details")
            ->assertOk();

        $response->assertJsonPath('data.classroom.full_name', '6ème A')
            ->assertJsonPath('data.main_teacher.name', 'Mme Kabasele')
            ->assertJsonPath('data.main_teacher.subject', 'Mathématiques')
            ->assertJsonPath('data.summary.students', 1)
            ->assertJsonPath('data.summary.parents', 1)
            ->assertJsonPath('data.summary.teachers', 1)
            ->assertJsonPath('data.summary.subjects', 1)
            ->assertJsonPath('data.summary.grade_average', 15)
            ->assertJsonPath('data.summary.present', 1)
            ->assertJsonPath('data.summary.absences', 1)
            ->assertJsonPath('data.students.0.full_name', 'Kabongo Ilunga Marie')
            ->assertJsonPath('data.students.0.parents.0.name', 'Parent Kabongo')
            ->assertJsonPath('data.parents.0.children.0.relation', 'mere')
            ->assertJsonPath('data.courses.0.teacher.name', 'Mme Kabasele')
            ->assertJsonPath('data.timetable.0.room', 'A12')
            ->assertJsonPath('data.evaluations.0.name', 'Interrogation 1')
            ->assertJsonPath('data.evaluations.0.subject.name', 'Mathématiques')
            ->assertJsonPath('data.evaluations.0.grades_count', 1);

        $this->assertCount(2, $response->json('data.recent_attendances'));
    }
}
