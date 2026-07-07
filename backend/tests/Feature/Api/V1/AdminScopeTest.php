<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\ClassRoom;
use App\Models\Evaluation;
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
use App\Support\AdminScopeContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_me_exposes_admin_scope_payload(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'admin_scope' => AdminScopeContext::PRIMARY_MATERNAL,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('admin_scope', AdminScopeContext::PRIMARY_MATERNAL)
            ->assertJsonPath('admin_scope_label', 'Admin cycle Primaire & Maternel')
            ->assertJsonPath('admin_cycles', [Level::CYCLE_MATERNEL, Level::CYCLE_PRIMAIRE])
            ->assertJsonPath('term_applicable_cycles', ['primaire']);
    }

    public function test_cycle_admin_is_blocked_from_global_administration_endpoints(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'admin_scope' => AdminScopeContext::SECONDARY_TECHNICAL,
        ]);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/users')->assertForbidden();
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/settings')->assertForbidden();
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/levels', [
            'name' => 'Nouveau niveau',
            'cycle' => Level::CYCLE_SECONDAIRE,
        ])->assertForbidden();
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/school-years', [
            'name' => '2026-2027',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-07-31',
        ])->assertForbidden();
    }

    public function test_cycle_admin_only_sees_students_and_classrooms_in_scope(): void
    {
        $primaryLevel = Level::factory()->create(['cycle' => Level::CYCLE_PRIMAIRE]);
        $secondaryLevel = Level::factory()->create(['cycle' => Level::CYCLE_SECONDAIRE]);
        $primaryClass = ClassRoom::factory()->create(['level_id' => $primaryLevel->id, 'section' => 'A']);
        $secondaryClass = ClassRoom::factory()->create(['level_id' => $secondaryLevel->id, 'section' => 'B']);
        $primaryStudent = Student::factory()->create(['classroom_id' => $primaryClass->id]);
        Student::factory()->create(['classroom_id' => $secondaryClass->id]);

        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'admin_scope' => AdminScopeContext::PRIMARY_MATERNAL,
        ]);

        $classrooms = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/classrooms')
            ->assertOk()
            ->json('data');
        $this->assertSame([$primaryClass->id], array_column($classrooms, 'id'));

        $students = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/students')
            ->assertOk()
            ->json('data');
        $this->assertSame([$primaryStudent->id], array_column($students, 'id'));
    }

    public function test_cycle_admin_cannot_create_classroom_outside_scope(): void
    {
        $primaryLevel = Level::factory()->create(['cycle' => Level::CYCLE_PRIMAIRE]);
        $secondaryLevel = Level::factory()->create(['cycle' => Level::CYCLE_SECONDAIRE]);

        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'admin_scope' => AdminScopeContext::PRIMARY_MATERNAL,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/classrooms', [
                'level_id' => $secondaryLevel->id,
                'section' => 'A',
            ])
            ->assertForbidden();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/classrooms', [
                'level_id' => $primaryLevel->id,
                'section' => 'A',
            ])
            ->assertCreated();
    }

    public function test_cycle_admin_cannot_access_student_timeline_outside_scope(): void
    {
        $secondaryLevel = Level::factory()->create(['cycle' => Level::CYCLE_SECONDAIRE]);
        $secondaryClass = ClassRoom::factory()->create(['level_id' => $secondaryLevel->id]);
        $student = Student::factory()->create(['classroom_id' => $secondaryClass->id]);

        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'admin_scope' => AdminScopeContext::PRIMARY_MATERNAL,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/students/{$student->id}/timeline")
            ->assertForbidden();
    }

    public function test_cycle_admin_cannot_unpublish_evaluation_outside_scope(): void
    {
        $schoolYear = SchoolYear::factory()->create();
        $term = Term::factory()->create(['school_year_id' => $schoolYear->id]);
        $period = Period::factory()->create(['term_id' => $term->id]);
        $secondaryLevel = Level::factory()->create(['cycle' => Level::CYCLE_SECONDAIRE]);
        $secondaryClass = ClassRoom::factory()->create(['level_id' => $secondaryLevel->id]);
        $subject = Subject::factory()->create();
        $evaluation = Evaluation::factory()->create([
            'classroom_id' => $secondaryClass->id,
            'subject_id' => $subject->id,
            'term_id' => $term->id,
            'period_id' => $period->id,
            'published_at' => now(),
        ]);

        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'admin_scope' => AdminScopeContext::PRIMARY_MATERNAL,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/evaluations/{$evaluation->id}/unpublish")
            ->assertForbidden();

        $this->assertNotNull($evaluation->fresh()->published_at);
    }

    public function test_cycle_admin_direct_parent_and_teacher_access_is_limited_to_scope(): void
    {
        $schoolYear = SchoolYear::factory()->current()->create();
        $secondaryLevel = Level::factory()->create(['cycle' => Level::CYCLE_SECONDAIRE]);
        $secondaryClass = ClassRoom::factory()->create(['level_id' => $secondaryLevel->id]);
        $student = Student::factory()->create([
            'classroom_id' => $secondaryClass->id,
            'enrollment_school_year_id' => $schoolYear->id,
        ]);
        $parent = ParentProfile::factory()->create();
        $parent->students()->attach($student->id, ['relation' => 'Parent']);

        $teacher = Teacher::factory()->create();
        TeacherAssignment::query()->create([
            'teacher_id' => $teacher->id,
            'classroom_id' => $secondaryClass->id,
            'school_year_id' => $schoolYear->id,
            'weekly_hours' => 4,
        ]);

        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'admin_scope' => AdminScopeContext::PRIMARY_MATERNAL,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/parents/{$parent->id}")
            ->assertForbidden();

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/teachers/{$teacher->id}")
            ->assertForbidden();
    }

    public function test_general_admin_must_provide_scope_when_creating_admin_user(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'admin_scope' => AdminScopeContext::GLOBAL,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/users', [
                'name' => 'Cycle Admin',
                'email' => 'cycle-admin@example.test',
                'password' => 'secret123',
                'role' => UserRole::Admin->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['admin_scope']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/users', [
                'name' => 'Cycle Admin',
                'email' => 'cycle-admin@example.test',
                'password' => 'secret123',
                'role' => UserRole::Admin->value,
                'admin_scope' => AdminScopeContext::SECONDARY_TECHNICAL,
            ])
            ->assertCreated()
            ->assertJsonPath('admin_scope', AdminScopeContext::SECONDARY_TECHNICAL);
    }

    public function test_global_admin_sees_trimestres_and_semestres(): void
    {
        $year = SchoolYear::factory()->create();
        app(\App\Services\TermGenerationService::class)->generateForYear($year);
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'admin_scope' => AdminScopeContext::GLOBAL,
        ]);

        $terms = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/school-years/'.$year->id)
            ->assertOk()
            ->json('data.terms');

        $this->assertCount(5, $terms);
        $this->assertContains(Term::CYCLE_PRIMAIRE, array_column($terms, 'applicable_cycle'));
        $this->assertContains(Term::CYCLE_SECONDAIRE, array_column($terms, 'applicable_cycle'));
    }

    public function test_primary_admin_only_sees_trimestres(): void
    {
        $year = SchoolYear::factory()->create();
        app(\App\Services\TermGenerationService::class)->generateForYear($year);
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'admin_scope' => AdminScopeContext::PRIMARY_MATERNAL,
        ]);

        $terms = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/school-years/'.$year->id)
            ->assertOk()
            ->json('data.terms');

        $this->assertCount(3, $terms);
        $this->assertSame(['primaire', 'primaire', 'primaire'], array_column($terms, 'applicable_cycle'));
    }

    public function test_secondary_admin_only_sees_semestres(): void
    {
        $year = SchoolYear::factory()->create();
        app(\App\Services\TermGenerationService::class)->generateForYear($year);
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'admin_scope' => AdminScopeContext::SECONDARY_TECHNICAL,
        ]);

        $terms = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/school-years/'.$year->id)
            ->assertOk()
            ->json('data.terms');

        $this->assertCount(2, $terms);
        $this->assertSame(['secondaire', 'secondaire'], array_column($terms, 'applicable_cycle'));
    }

    public function test_teacher_only_sees_calendar_for_assigned_cycles(): void
    {
        $year = SchoolYear::factory()->current()->create();
        app(\App\Services\TermGenerationService::class)->generateForYear($year);
        $primaryLevel = Level::factory()->create(['cycle' => Level::CYCLE_PRIMAIRE]);
        $primaryClass = ClassRoom::factory()->create(['level_id' => $primaryLevel->id]);
        $subject = Subject::factory()->create();
        $teacherUser = User::factory()->create(['role' => UserRole::Enseignant]);
        $teacher = Teacher::factory()->create(['user_id' => $teacherUser->id]);
        TeacherAssignment::query()->create([
            'teacher_id' => $teacher->id,
            'classroom_id' => $primaryClass->id,
            'subject_id' => $subject->id,
            'school_year_id' => $year->id,
        ]);

        $this->actingAs($teacherUser, 'sanctum')
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('term_applicable_cycles', ['primaire']);

        $terms = $this->actingAs($teacherUser, 'sanctum')
            ->getJson('/api/v1/school-years/current')
            ->assertOk()
            ->json('data.terms');

        $this->assertCount(3, $terms);
        $this->assertSame(['primaire', 'primaire', 'primaire'], array_column($terms, 'applicable_cycle'));
    }

    public function test_cycle_admin_only_sees_teachers_of_their_cycle(): void
    {
        $primaryTeacher = Teacher::factory()->create(['teacher_type' => Teacher::TYPE_PRIMAIRE, 'speciality' => null]);
        $secondaryTeacher = Teacher::factory()->create([
            'teacher_type' => Teacher::TYPE_SECONDAIRE,
            'speciality' => 'Mathématiques',
        ]);
        $unassignedSecondary = Teacher::factory()->create([
            'teacher_type' => Teacher::TYPE_SECONDAIRE,
            'speciality' => 'Français',
        ]);

        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'admin_scope' => AdminScopeContext::PRIMARY_MATERNAL,
        ]);

        $ids = collect($this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/teachers')
            ->assertOk()
            ->json('data'))
            ->pluck('id')
            ->all();

        $this->assertContains($primaryTeacher->id, $ids);
        $this->assertNotContains($secondaryTeacher->id, $ids);
        $this->assertNotContains($unassignedSecondary->id, $ids);
    }

    public function test_cycle_admin_can_create_teacher_of_their_cycle_only(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'admin_scope' => AdminScopeContext::SECONDARY_TECHNICAL,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/teachers', [
                'teacher_type' => Teacher::TYPE_PRIMAIRE,
                'name' => 'Mauvais type',
                'speciality' => 'Français',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['teacher_type']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/teachers', [
                'teacher_type' => Teacher::TYPE_SECONDAIRE,
                'name' => 'Prof Secondaire',
                'speciality' => 'Physique',
            ])
            ->assertCreated()
            ->assertJsonPath('data.teacher_type', Teacher::TYPE_SECONDAIRE);
    }

    public function test_cycle_admin_can_assign_primary_teacher_to_classroom(): void
    {
        $year = SchoolYear::factory()->current()->create();
        $level = Level::factory()->create(['cycle' => Level::CYCLE_PRIMAIRE]);
        $classroom = ClassRoom::factory()->create(['level_id' => $level->id, 'section' => 'A']);
        $subject = Subject::factory()->create(['name' => 'Calcul']);
        $classroom->subjects()->attach($subject->id, ['coefficient' => 2]);
        $teacher = Teacher::factory()->create(['teacher_type' => Teacher::TYPE_PRIMAIRE, 'speciality' => null]);

        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'admin_scope' => AdminScopeContext::PRIMARY_MATERNAL,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/teachers/{$teacher->id}/assign-classroom", [
                'classroom_id' => $classroom->id,
                'school_year_id' => $year->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.main_classroom.id', $classroom->id);
    }

    public function test_global_admin_can_deactivate_any_user(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $target = User::factory()->create(['role' => UserRole::Enseignant, 'is_active' => true]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/admin/users/{$target->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('is_active', false);

        $this->assertFalse($target->fresh()->is_active);
    }

    public function test_global_admin_cannot_deactivate_own_account(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/admin/users/{$admin->id}", ['is_active' => false])
            ->assertStatus(422);

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_editing_name_of_non_secondary_admin_is_forbidden(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $target = User::factory()->create(['role' => UserRole::Eleve, 'name' => 'Origine']);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/admin/users/{$target->id}", ['name' => 'Piraté'])
            ->assertForbidden();

        $this->assertSame('Origine', $target->fresh()->name);
    }
}
