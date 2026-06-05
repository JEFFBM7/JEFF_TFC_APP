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
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParentPortalTest extends TestCase
{
    use RefreshDatabase;

    private function makeParentWithChild(): array
    {
        $parentUser = User::factory()->create(['role' => UserRole::Parent, 'name' => 'Papa Test']);
        $profile = ParentProfile::factory()->create(['user_id' => $parentUser->id]);

        $level = Level::factory()->create();
        $classroom = ClassRoom::factory()->create(['level_id' => $level->id, 'section' => 'A']);
        $student = Student::factory()->create([
            'classroom_id' => $classroom->id,
            'first_name' => 'Junior',
            'last_name' => 'Test',
            'middle_name' => 'Ilunga',
        ]);
        $profile->students()->attach($student->id, ['relation' => 'pere']);

        return compact('parentUser', 'profile', 'student', 'classroom', 'level');
    }

    // ─── Accès ──────────────────────────────────────────────────────────

    public function test_admin_cannot_access_parent_portal(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/parent/dashboard')
            ->assertForbidden();
    }

    public function test_enseignant_cannot_access_parent_portal(): void
    {
        $teacher = User::factory()->create(['role' => UserRole::Enseignant]);

        $this->actingAs($teacher, 'sanctum')
            ->getJson('/api/v1/parent/children')
            ->assertForbidden();
    }

    // ─── Dashboard ──────────────────────────────────────────────────────

    public function test_parent_sees_dashboard_with_children(): void
    {
        $ctx = $this->makeParentWithChild();

        $res = $this->actingAs($ctx['parentUser'], 'sanctum')
            ->getJson('/api/v1/parent/dashboard')
            ->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame('Test Ilunga Junior', $res->json('data.0.full_name'));
        $this->assertArrayHasKey('wellbeing', $res->json('data.0'));
        $this->assertArrayHasKey('recent', $res->json('data.0'));
        $this->assertArrayHasKey('upcoming', $res->json('data.0'));
        $this->assertArrayHasKey('unread_messages', $res->json());
    }

    public function test_parent_dashboard_shows_grade_trend_when_two_terms_have_averages(): void
    {
        $ctx = $this->makeParentWithChild();
        $year = SchoolYear::factory()->create(['name' => '2025-2026', 'is_current' => true]);
        $ctx['student']->update(['enrollment_school_year_id' => $year->id]);
        $term1 = Term::factory()->create([
            'school_year_id' => $year->id,
            'name' => 'T1',
            'position' => 1,
            'applicable_cycle' => Term::CYCLE_PRIMAIRE,
            'starts_on' => '2025-09-01',
            'ends_on' => '2025-12-20',
        ]);
        $term2 = Term::factory()->create([
            'school_year_id' => $year->id,
            'name' => 'T2',
            'position' => 2,
            'applicable_cycle' => Term::CYCLE_PRIMAIRE,
            'starts_on' => '2026-01-05',
            'ends_on' => '2026-03-28',
        ]);
        $subject = Subject::factory()->create(['name' => 'Maths']);
        $ctx['classroom']->subjects()->attach($subject->id, ['coefficient' => 2]);

        foreach ([$term1, $term2] as $i => $term) {
            $eval = Evaluation::factory()->create([
                'classroom_id' => $ctx['classroom']->id,
                'subject_id' => $subject->id,
                'term_id' => $term->id,
                'published_at' => now(),
            ]);
            Grade::query()->create([
                'evaluation_id' => $eval->id,
                'student_id' => $ctx['student']->id,
                'value' => $i === 0 ? 12 : 16,
                'absent' => false,
            ]);
        }

        $res = $this->actingAs($ctx['parentUser'], 'sanctum')
            ->getJson('/api/v1/parent/dashboard')
            ->assertOk();

        $this->assertCount(1, $res->json('data'));
        $wellbeing = $res->json('data.0.wellbeing');
        $this->assertNotNull($wellbeing);
        $this->assertNotNull($wellbeing['average']);
        $this->assertSame('up', $wellbeing['trend']);
    }

    // ─── Liste enfants ──────────────────────────────────────────────────

    public function test_parent_sees_only_own_children(): void
    {
        $ctx = $this->makeParentWithChild();

        $otherStudent = Student::factory()->create([
            'classroom_id' => $ctx['classroom']->id,
            'first_name' => 'Other',
            'last_name' => 'Kid',
        ]);

        $res = $this->actingAs($ctx['parentUser'], 'sanctum')
            ->getJson('/api/v1/parent/children')
            ->assertOk();

        $ids = collect($res->json('data'))->pluck('id');
        $this->assertContains($ctx['student']->id, $ids->toArray());
        $this->assertNotContains($otherStudent->id, $ids->toArray());
    }

    // ─── Bulletin ───────────────────────────────────────────────────────

    public function test_parent_can_view_child_report_card(): void
    {
        $ctx = $this->makeParentWithChild();

        $year = SchoolYear::factory()->create(['name' => '2025-2026']);
        $term = Term::factory()->create(['school_year_id' => $year->id, 'name' => 'T1', 'position' => 1]);
        $subject = Subject::factory()->create(['name' => 'Maths']);
        $ctx['classroom']->subjects()->attach($subject->id, ['coefficient' => 3]);

        $eval = Evaluation::factory()->create([
            'classroom_id' => $ctx['classroom']->id,
            'subject_id' => $subject->id,
            'term_id' => $term->id,
            'published_at' => now(),
        ]);
        Grade::query()->create([
            'evaluation_id' => $eval->id,
            'student_id' => $ctx['student']->id,
            'value' => 15,
            'absent' => false,
        ]);

        $res = $this->actingAs($ctx['parentUser'], 'sanctum')
            ->getJson("/api/v1/parent/children/{$ctx['student']->id}/report-card/{$term->id}")
            ->assertOk();

        $this->assertEquals(15.0, $res->json('data.overall_average'));
    }

    public function test_parent_cannot_view_other_child_report_card(): void
    {
        $ctx = $this->makeParentWithChild();

        $otherStudent = Student::factory()->create(['classroom_id' => $ctx['classroom']->id]);
        $year = SchoolYear::factory()->create(['name' => '2024-2025']);
        $term = Term::factory()->create(['school_year_id' => $year->id, 'name' => 'T1', 'position' => 1]);

        $this->actingAs($ctx['parentUser'], 'sanctum')
            ->getJson("/api/v1/parent/children/{$otherStudent->id}/report-card/{$term->id}")
            ->assertForbidden();
    }

    public function test_parent_can_download_child_report_card_pdf(): void
    {
        $ctx = $this->makeParentWithChild();

        $year = SchoolYear::factory()->create(['name' => '2023-2024']);
        $term = Term::factory()->create(['school_year_id' => $year->id, 'name' => 'T2', 'position' => 2]);

        $this->actingAs($ctx['parentUser'], 'sanctum')
            ->getJson("/api/v1/parent/children/{$ctx['student']->id}/report-card/{$term->id}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    // ─── Absences ────────────────────────────────────────────────────────

    public function test_parent_sees_child_attendances(): void
    {
        $ctx = $this->makeParentWithChild();

        Attendance::factory()->create([
            'student_id' => $ctx['student']->id,
            'classroom_id' => $ctx['classroom']->id,
            'status' => Attendance::STATUS_ABSENT,
            'justified' => false,
        ]);

        $res = $this->actingAs($ctx['parentUser'], 'sanctum')
            ->getJson("/api/v1/parent/children/{$ctx['student']->id}/attendances")
            ->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame('absent', $res->json('data.0.status'));
    }

    public function test_parent_portal_uses_current_year_duplicate_child_when_link_points_to_another_year(): void
    {
        $currentYear = SchoolYear::factory()->current()->create([
            'name' => '2025-2026',
            'starts_on' => now()->subMonth()->toDateString(),
            'ends_on' => now()->addMonth()->toDateString(),
        ]);
        $otherYear = SchoolYear::factory()->create(['name' => '2030-2031']);
        $parentUser = User::factory()->create(['role' => UserRole::Parent, 'name' => 'Parent Test']);
        $profile = ParentProfile::factory()->create(['user_id' => $parentUser->id]);
        $level = Level::factory()->create();
        $linkedClassroom = ClassRoom::factory()->create(['level_id' => $level->id, 'section' => 'A']);
        $currentClassroom = ClassRoom::factory()->create(['level_id' => $level->id, 'section' => 'B']);

        $linkedStudent = Student::factory()->create([
            'classroom_id' => $linkedClassroom->id,
            'enrollment_school_year_id' => $otherYear->id,
            'first_name' => 'Jeff',
            'last_name' => 'BOPE',
            'middle_name' => 'MIKOBI',
        ]);
        $profile->students()->attach($linkedStudent->id, ['relation' => 'pere']);

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
            'justified' => false,
            'student_justification' => 'Je suis malade',
            'student_justified_at' => now(),
        ]);

        $this->actingAs($parentUser, 'sanctum')
            ->getJson('/api/v1/parent/children')
            ->assertOk()
            ->assertJsonPath('data.0.id', $actualStudent->id);

        $this->actingAs($parentUser, 'sanctum')
            ->getJson("/api/v1/parent/children/{$linkedStudent->id}/attendances")
            ->assertOk()
            ->assertJsonPath('data.0.id', $attendance->id)
            ->assertJsonPath('data.0.student_id', $actualStudent->id);

        $this->actingAs($parentUser, 'sanctum')
            ->patchJson("/api/v1/parent/children/{$linkedStudent->id}/attendances/{$attendance->id}/justify", [
                'justification' => 'Confirmé par le responsable',
            ])
            ->assertOk()
            ->assertJsonPath('data.justified', true);
    }

    public function test_parent_sees_child_attendance_summary(): void
    {
        $ctx = $this->makeParentWithChild();

        Attendance::factory()->count(2)->create([
            'student_id' => $ctx['student']->id,
            'classroom_id' => $ctx['classroom']->id,
            'status' => Attendance::STATUS_ABSENT,
            'justified' => false,
        ]);
        Attendance::factory()->create([
            'student_id' => $ctx['student']->id,
            'classroom_id' => $ctx['classroom']->id,
            'status' => Attendance::STATUS_LATE,
        ]);

        $this->actingAs($ctx['parentUser'], 'sanctum')
            ->getJson("/api/v1/parent/children/{$ctx['student']->id}/attendance-summary")
            ->assertOk()
            ->assertJsonPath('data.total_absences', 2)
            ->assertJsonPath('data.late_count', 1);
    }

    // ─── Confirmation d'une justification élève par le parent ────────────

    public function test_parent_can_confirm_child_absence_after_student_justification(): void
    {
        $ctx = $this->makeParentWithChild();

        $att = Attendance::factory()->create([
            'student_id' => $ctx['student']->id,
            'classroom_id' => $ctx['classroom']->id,
            'status' => Attendance::STATUS_ABSENT,
            'justified' => false,
            'student_justification' => 'Je suis malade',
            'student_justified_at' => now(),
        ]);

        $this->actingAs($ctx['parentUser'], 'sanctum')
            ->patchJson("/api/v1/parent/children/{$ctx['student']->id}/attendances/{$att->id}/justify", [
                'justification' => 'Rendez-vous médical',
            ])
            ->assertOk()
            ->assertJsonPath('data.justified', true)
            ->assertJsonPath('data.justification', 'Rendez-vous médical');

        $this->assertDatabaseHas('attendances', [
            'id' => $att->id,
            'justified' => true,
            'justification' => 'Rendez-vous médical',
            'justified_by' => $ctx['parentUser']->id,
        ]);
    }

    public function test_parent_can_confirm_child_late_after_student_justification(): void
    {
        $ctx = $this->makeParentWithChild();

        $att = Attendance::factory()->create([
            'student_id' => $ctx['student']->id,
            'classroom_id' => $ctx['classroom']->id,
            'status' => Attendance::STATUS_LATE,
            'justified' => false,
            'student_justification' => 'Bus en panne',
            'student_justified_at' => now(),
        ]);

        $this->actingAs($ctx['parentUser'], 'sanctum')
            ->patchJson("/api/v1/parent/children/{$ctx['student']->id}/attendances/{$att->id}/justify", [])
            ->assertOk()
            ->assertJsonPath('data.justified', true)
            ->assertJsonPath('data.justification', 'Bus en panne');
    }

    public function test_parent_cannot_justify_other_child_absence(): void
    {
        $ctx = $this->makeParentWithChild();

        $otherStudent = Student::factory()->create(['classroom_id' => $ctx['classroom']->id]);
        $att = Attendance::factory()->create([
            'student_id' => $otherStudent->id,
            'classroom_id' => $ctx['classroom']->id,
            'status' => Attendance::STATUS_ABSENT,
            'justified' => false,
        ]);

        $this->actingAs($ctx['parentUser'], 'sanctum')
            ->patchJson("/api/v1/parent/children/{$otherStudent->id}/attendances/{$att->id}/justify", [
                'justification' => 'X',
            ])
            ->assertForbidden();
    }

    public function test_parent_cannot_confirm_present(): void
    {
        $ctx = $this->makeParentWithChild();

        $att = Attendance::factory()->create([
            'student_id' => $ctx['student']->id,
            'classroom_id' => $ctx['classroom']->id,
            'status' => Attendance::STATUS_PRESENT,
            'student_justification' => 'Test',
            'student_justified_at' => now(),
        ]);

        $this->actingAs($ctx['parentUser'], 'sanctum')
            ->patchJson("/api/v1/parent/children/{$ctx['student']->id}/attendances/{$att->id}/justify", [
                'justification' => 'Test',
            ])
            ->assertStatus(422);
    }

    public function test_parent_cannot_confirm_before_student_justification(): void
    {
        $ctx = $this->makeParentWithChild();

        $att = Attendance::factory()->create([
            'student_id' => $ctx['student']->id,
            'classroom_id' => $ctx['classroom']->id,
            'status' => Attendance::STATUS_ABSENT,
            'justified' => false,
        ]);

        $this->actingAs($ctx['parentUser'], 'sanctum')
            ->patchJson("/api/v1/parent/children/{$ctx['student']->id}/attendances/{$att->id}/justify", [])
            ->assertStatus(422);
    }

    // ─── Trimestres ──────────────────────────────────────────────────────

    public function test_parent_can_list_terms(): void
    {
        $year = SchoolYear::factory()->create(['name' => '2022-2023']);
        Term::factory()->create(['school_year_id' => $year->id, 'name' => 'Trim1', 'position' => 1]);

        $ctx = $this->makeParentWithChild();

        $res = $this->actingAs($ctx['parentUser'], 'sanctum')
            ->getJson('/api/v1/parent/terms')
            ->assertOk();

        $this->assertGreaterThanOrEqual(1, count($res->json('data')));
    }

    public function test_parent_child_terms_only_lists_applicable_cycle(): void
    {
        $ctx = $this->makeParentWithChild();
        $year = SchoolYear::factory()->create(['name' => '2026-2027', 'is_current' => true]);
        $ctx['student']->update(['enrollment_school_year_id' => $year->id]);

        Term::factory()->create([
            'school_year_id' => $year->id,
            'name' => '1er Trimestre',
            'position' => 1,
            'applicable_cycle' => Term::CYCLE_PRIMAIRE,
        ]);
        Term::factory()->create([
            'school_year_id' => $year->id,
            'name' => '1er Semestre',
            'position' => 2,
            'applicable_cycle' => Term::CYCLE_SECONDAIRE,
            'term_type' => Term::TYPE_SEMESTRE,
        ]);

        $res = $this->actingAs($ctx['parentUser'], 'sanctum')
            ->getJson("/api/v1/parent/children/{$ctx['student']->id}/terms")
            ->assertOk();

        $names = collect($res->json('data'))->pluck('name');
        $this->assertContains('1er Trimestre', $names->all());
        $this->assertNotContains('1er Semestre', $names->all());
        $this->assertSame(Term::CYCLE_PRIMAIRE, $res->json('meta.applicable_cycle'));
    }

    public function test_parent_cannot_load_report_card_for_wrong_cycle_term(): void
    {
        $ctx = $this->makeParentWithChild();
        $year = SchoolYear::factory()->create(['name' => '2026-2027', 'is_current' => true]);
        $ctx['student']->update(['enrollment_school_year_id' => $year->id]);

        $secondaryTerm = Term::factory()->create([
            'school_year_id' => $year->id,
            'name' => '1er Semestre',
            'position' => 2,
            'applicable_cycle' => Term::CYCLE_SECONDAIRE,
            'term_type' => Term::TYPE_SEMESTRE,
        ]);

        $this->actingAs($ctx['parentUser'], 'sanctum')
            ->getJson("/api/v1/parent/children/{$ctx['student']->id}/report-card/{$secondaryTerm->id}")
            ->assertForbidden();
    }
}
