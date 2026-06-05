<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\ClassRoom;
use App\Models\Level;
use App\Models\Period;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Valide le contexte global "année scolaire courante" :
 * - /school-years/current accessible à tous les rôles authentifiés.
 * - Création d'élèves auto-remplit enrollment_school_year_id.
 * - Mutations sur année archivée renvoient 423 (Locked).
 */
class SchoolYearContextTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    private function classroom(): ClassRoom
    {
        return ClassRoom::factory()->create([
            'level_id' => Level::factory()->create()->id,
            'section' => 'A',
        ]);
    }

    public function test_current_school_year_endpoint_is_accessible_to_authenticated_users(): void
    {
        SchoolYear::factory()->current()->create(['name' => '2025-2026']);

        foreach (UserRole::cases() as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user, 'sanctum')
                ->getJson('/api/v1/school-years/current')
                ->assertOk()
                ->assertJsonPath('data.name', '2025-2026')
                ->assertJsonPath('data.is_current', true);
        }
    }

    public function test_current_school_year_endpoint_returns_null_when_no_current_year(): void
    {
        $user = User::factory()->create(['role' => UserRole::Enseignant]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/school-years/current')
            ->assertOk()
            ->assertJson(['data' => null]);
    }

    public function test_current_school_year_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/v1/school-years/current')->assertUnauthorized();
    }

    public function test_creating_student_without_school_year_uses_current(): void
    {
        $classroom = $this->classroom();
        $current = SchoolYear::factory()->current()->create([
            'name' => '2025-2026',
            'starts_on' => '2025-09-01',
            'ends_on' => '2026-06-30',
        ]);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/students', [
                'first_name' => 'Auto',
                'last_name' => 'Fill',
                'middle_name' => 'Test',
                'classroom_id' => $classroom->id,
                'date_of_birth' => '2014-05-12',
                'place_of_birth' => 'Lubumbashi',
                'gender' => 'F',
                'nationality' => 'Congolaise',
                'enrollment_status' => 'actif',
                'order_number' => 'REG-AUTO-001',
                'enrolled_on' => '2025-09-02',
                'primary_phone' => '+243000000',
            ])
            ->assertCreated();

        $this->assertSame($current->id, $response->json('data.enrollment_school_year_id'));
    }

    public function test_creating_student_without_school_year_fails_when_no_current_year(): void
    {
        $classroom = $this->classroom();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/students', [
                'first_name' => 'No',
                'last_name' => 'Year',
                'middle_name' => 'Test',
                'classroom_id' => $classroom->id,
                'date_of_birth' => '2014-05-12',
                'place_of_birth' => 'Lubumbashi',
                'gender' => 'M',
                'nationality' => 'Congolaise',
                'enrollment_status' => 'actif',
                'order_number' => 'REG-NOY-001',
                'enrolled_on' => '2025-09-02',
                'primary_phone' => '+243000000',
            ])
            ->assertStatus(422);
    }

    public function test_creating_evaluation_in_archived_year_returns_locked(): void
    {
        $year = SchoolYear::factory()->archived()->create(['name' => '2020-2021']);
        $term = Term::factory()->create([
            'school_year_id' => $year->id,
            'starts_on' => '2020-09-01',
            'ends_on' => '2020-12-15',
        ]);
        $period = Period::factory()->create([
            'term_id' => $term->id,
            'starts_on' => '2020-09-01',
            'ends_on' => '2020-10-31',
        ]);
        $classroom = $this->classroom();
        $subject = Subject::factory()->create();
        $teacher = Teacher::factory()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/evaluations', [
                'classroom_id' => $classroom->id,
                'subject_id' => $subject->id,
                'term_id' => $term->id,
                'period_id' => $period->id,
                'teacher_id' => $teacher->id,
                'name' => 'Devoir 1',
                'type' => 'devoir',
                'held_on' => '2020-10-10',
                'max_value' => 20,
            ])
            ->assertStatus(423);
    }

    public function test_attendance_batch_in_archived_year_returns_locked(): void
    {
        $year = SchoolYear::factory()->archived()->create([
            'name' => '2020-2021',
            'starts_on' => '2020-09-01',
            'ends_on' => '2021-06-30',
        ]);
        $classroom = $this->classroom();
        $student = Student::factory()->create([
            'classroom_id' => $classroom->id,
            'enrollment_school_year_id' => $year->id,
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/attendances/batch', [
                'classroom_id' => $classroom->id,
                'date' => '2020-10-15',
                'records' => [
                    ['student_id' => $student->id, 'status' => Attendance::STATUS_ABSENT],
                ],
            ])
            ->assertStatus(423);
    }

    public function test_timetable_slot_in_archived_year_cannot_be_created(): void
    {
        $year = SchoolYear::factory()->archived()->create(['name' => '2020-2021']);
        $classroom = $this->classroom();
        $subject = Subject::factory()->create();
        $teacher = Teacher::factory()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/timetable-slots', [
                'classroom_id' => $classroom->id,
                'subject_id' => $subject->id,
                'teacher_id' => $teacher->id,
                'school_year_id' => $year->id,
                'day_of_week' => 1,
                'starts_at' => '08:00',
                'ends_at' => '09:00',
            ])
            ->assertStatus(423);
    }

    public function test_assignment_in_archived_year_cannot_be_created(): void
    {
        $year = SchoolYear::factory()->archived()->create(['name' => '2020-2021']);
        $classroom = $this->classroom();
        $subject = Subject::factory()->create();
        $teacher = Teacher::factory()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/assignments', [
                'teacher_id' => $teacher->id,
                'classroom_id' => $classroom->id,
                'subject_id' => $subject->id,
                'school_year_id' => $year->id,
            ])
            ->assertStatus(423);
    }

    public function test_setting_a_year_current_uncurrents_others_via_api(): void
    {
        $previous = SchoolYear::factory()->current()->create(['name' => '2024-2025']);
        $next = SchoolYear::factory()->create(['name' => '2025-2026']);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/v1/school-years/{$next->id}", [
                'name' => $next->name,
                'starts_on' => $next->starts_on->toDateString(),
                'ends_on' => $next->ends_on->toDateString(),
                'is_current' => true,
            ])
            ->assertOk();

        $this->assertFalse($previous->fresh()->is_current);
        $this->assertTrue($next->fresh()->is_current);
        $this->assertSame(1, SchoolYear::query()->where('is_current', true)->count());
    }
}
