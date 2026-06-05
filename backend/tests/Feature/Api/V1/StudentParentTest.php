<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\ClassRoom;
use App\Models\Level;
use App\Models\ParentProfile;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StudentParentTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    private function classroom(): ClassRoom
    {
        $level = Level::factory()->create();

        return ClassRoom::factory()->create(['level_id' => $level->id, 'section' => 'A']);
    }

    private function ctebClassroom(): ClassRoom
    {
        $level = Level::factory()->create([
            'name' => '7e CTEB',
            'cycle' => Level::CYCLE_CTEB,
            'order' => 20,
        ]);

        return ClassRoom::factory()->create(['level_id' => $level->id, 'section' => 'A']);
    }

    // ─── Parents ─────────────────────────────────────────────────────────────

    public function test_admin_can_create_parent(): void
    {
        $user = User::factory()->create(['role' => UserRole::Parent]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/parents', [
                'user_id' => $user->id,
                'phone' => '+243000000',
                'address' => 'Kinshasa',
            ])
            ->assertCreated()
            ->assertJsonPath('data.user.email', $user->email);
    }

    public function test_cannot_create_parent_with_non_parent_user(): void
    {
        $u = User::factory()->create(['role' => UserRole::Eleve]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/parents', ['user_id' => $u->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user_id']);
    }

    public function test_parent_unique_per_user(): void
    {
        $parent = ParentProfile::factory()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/parents', ['user_id' => $parent->user_id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user_id']);
    }

    public function test_filter_parents_by_child_cycle(): void
    {
        $primaryLevel = Level::factory()->create(['cycle' => Level::CYCLE_PRIMAIRE]);
        $secondaryLevel = Level::factory()->create(['cycle' => Level::CYCLE_SECONDAIRE]);
        $primaryClassroom = ClassRoom::factory()->create(['level_id' => $primaryLevel->id, 'section' => 'A']);
        $secondaryClassroom = ClassRoom::factory()->create(['level_id' => $secondaryLevel->id, 'section' => 'A']);
        $primaryParent = ParentProfile::factory()->create();
        $secondaryParent = ParentProfile::factory()->create();
        $primaryStudent = Student::factory()->create(['classroom_id' => $primaryClassroom->id]);
        $secondaryStudent = Student::factory()->create(['classroom_id' => $secondaryClassroom->id]);

        $primaryParent->students()->attach($primaryStudent->id, ['relation' => 'mere']);
        $secondaryParent->students()->attach($secondaryStudent->id, ['relation' => 'pere']);

        $res = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/parents?cycle='.Level::CYCLE_PRIMAIRE)
            ->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame($primaryParent->id, $res->json('data.0.id'));
    }

    public function test_parent_detail_filters_children_by_school_year(): void
    {
        $classroom = $this->classroom();
        $currentYear = SchoolYear::factory()->current()->create(['name' => '2026-2027']);
        $oldYear = SchoolYear::factory()->create(['name' => '2025-2026']);
        $parent = ParentProfile::factory()->create();
        $currentStudent = Student::factory()->create([
            'classroom_id' => $classroom->id,
            'enrollment_school_year_id' => $currentYear->id,
            'last_name' => 'Courant',
        ]);
        $oldStudent = Student::factory()->create([
            'classroom_id' => $classroom->id,
            'enrollment_school_year_id' => $oldYear->id,
            'last_name' => 'Ancien',
        ]);

        $parent->students()->attach($currentStudent->id, ['relation' => 'mere']);
        $parent->students()->attach($oldStudent->id, ['relation' => 'mere']);

        $default = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/parents/'.$parent->id)
            ->assertOk();

        $this->assertCount(1, $default->json('data.students'));
        $this->assertSame($currentStudent->id, $default->json('data.students.0.id'));

        $historical = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/parents/'.$parent->id.'?school_year_id='.$oldYear->id)
            ->assertOk();

        $this->assertCount(1, $historical->json('data.students'));
        $this->assertSame($oldStudent->id, $historical->json('data.students.0.id'));
    }

    // ─── Students ────────────────────────────────────────────────────────────

    public function test_admin_can_create_student(): void
    {
        $classroom = $this->classroom();
        $year = SchoolYear::factory()->create([
            'name' => '2026-2027',
            'starts_on' => '2026-09-01',
        ]);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/students', [
                'first_name' => 'Marie',
                'last_name' => 'Kabongo',
                'middle_name' => 'Ilunga',
                'classroom_id' => $classroom->id,
                'enrollment_school_year_id' => $year->id,
                'date_of_birth' => '2014-05-12',
                'place_of_birth' => 'Lubumbashi',
                'gender' => 'F',
                'nationality' => 'Congolaise',
                'enrollment_status' => 'actif',
                'enrolled_on' => '2026-09-02',
                'primary_phone' => '+243000000',
            ])
            ->assertCreated()
            ->assertJsonPath('data.full_name', 'Kabongo Ilunga Marie')
            ->assertJsonPath('data.classroom_id', $classroom->id)
            ->assertJsonPath('data.user_id', null)
            ->assertJsonPath('data.student_portal_status', 'not_created_until_7e')
            ->assertJsonPath('meta.portal_credentials.0.role', 'parent')
            ->assertJsonPath('meta.portal_credentials.0.login_type', 'telephone');

        $this->assertStringStartsWith('MAL-2026-', $response->json('data.registration_number'));
        $this->assertStringStartsWith('ORD-2026-', $response->json('data.order_number'));
        $this->assertNotEmpty($response->json('meta.portal_credentials.0.password'));
    }

    public function test_admin_creates_student_portal_account_from_cteb(): void
    {
        $classroom = $this->ctebClassroom();
        $year = SchoolYear::factory()->create([
            'name' => '2026-2027',
            'starts_on' => '2026-09-01',
        ]);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/students', [
                'first_name' => 'Aline',
                'last_name' => 'Mutombo',
                'middle_name' => 'Kanku',
                'classroom_id' => $classroom->id,
                'enrollment_school_year_id' => $year->id,
                'date_of_birth' => '2012-05-12',
                'place_of_birth' => 'Lubumbashi',
                'gender' => 'F',
                'nationality' => 'Congolaise',
                'enrollment_status' => 'actif',
                'enrolled_on' => '2026-09-02',
                'primary_phone' => '+243000000',
            ])
            ->assertCreated()
            ->assertJsonPath('data.student_portal_status', 'active')
            ->assertJsonPath('meta.portal_credentials.0.role', 'eleve')
            ->assertJsonPath('meta.portal_credentials.0.login_type', 'matricule');

        $this->assertSame($response->json('data.registration_number'), $response->json('meta.portal_credentials.0.login'));
        $this->assertNotEmpty($response->json('meta.portal_credentials.0.password'));
        $this->assertNotNull($response->json('data.user_id'));
        $this->assertDatabaseHas('users', [
            'id' => $response->json('data.user_id'),
            'role' => 'eleve',
            'is_active' => true,
        ]);
    }

    public function test_updating_primary_student_to_cteb_creates_student_portal_account(): void
    {
        $primaryClassroom = $this->classroom();
        $ctebClassroom = $this->ctebClassroom();
        $year = SchoolYear::factory()->create(['starts_on' => '2026-09-01']);

        $created = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/students', [
                'first_name' => 'Junior',
                'last_name' => 'Kabasele',
                'middle_name' => 'Mbuyi',
                'classroom_id' => $primaryClassroom->id,
                'enrollment_school_year_id' => $year->id,
                'date_of_birth' => '2013-05-12',
                'place_of_birth' => 'Lubumbashi',
                'gender' => 'M',
                'nationality' => 'Congolaise',
                'enrollment_status' => 'actif',
                'enrolled_on' => '2026-09-02',
                'primary_phone' => '+243000000',
            ])
            ->assertCreated()
            ->assertJsonPath('data.student_portal_status', 'not_created_until_7e');

        $updated = $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/students/'.$created->json('data.id'), [
                'first_name' => 'Junior',
                'last_name' => 'Kabasele',
                'middle_name' => 'Mbuyi',
                'classroom_id' => $ctebClassroom->id,
                'enrollment_school_year_id' => $year->id,
                'date_of_birth' => '2013-05-12',
                'place_of_birth' => 'Lubumbashi',
                'gender' => 'M',
                'nationality' => 'Congolaise',
                'enrollment_status' => 'actif',
                'enrolled_on' => '2026-09-02',
                'primary_phone' => '+243000000',
            ])
            ->assertOk()
            ->assertJsonPath('data.student_portal_status', 'active')
            ->assertJsonPath('meta.portal_credentials.0.role', 'eleve')
            ->assertJsonPath('meta.portal_credentials.0.generated', true);

        $this->assertDatabaseHas('users', [
            'id' => $updated->json('data.user_id'),
            'role' => 'eleve',
            'is_active' => true,
        ]);
    }

    public function test_updating_cteb_student_to_primary_disables_student_portal_account(): void
    {
        $primaryClassroom = $this->classroom();
        $ctebClassroom = $this->ctebClassroom();
        $year = SchoolYear::factory()->create(['starts_on' => '2026-09-01']);

        $created = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/students', [
                'first_name' => 'Sarah',
                'last_name' => 'Nkulu',
                'middle_name' => 'Ilunga',
                'classroom_id' => $ctebClassroom->id,
                'enrollment_school_year_id' => $year->id,
                'date_of_birth' => '2012-05-12',
                'place_of_birth' => 'Lubumbashi',
                'gender' => 'F',
                'nationality' => 'Congolaise',
                'enrollment_status' => 'actif',
                'enrolled_on' => '2026-09-02',
                'primary_phone' => '+243000000',
            ])
            ->assertCreated()
            ->assertJsonPath('data.student_portal_status', 'active');

        $student = Student::query()->with('user')->findOrFail($created->json('data.id'));
        $student->user->createToken('test')->plainTextToken;
        $this->assertSame(1, DB::table('personal_access_tokens')->where('tokenable_id', $student->user_id)->count());

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/students/'.$student->id, [
                'first_name' => 'Sarah',
                'last_name' => 'Nkulu',
                'middle_name' => 'Ilunga',
                'classroom_id' => $primaryClassroom->id,
                'enrollment_school_year_id' => $year->id,
                'date_of_birth' => '2012-05-12',
                'place_of_birth' => 'Lubumbashi',
                'gender' => 'F',
                'nationality' => 'Congolaise',
                'enrollment_status' => 'actif',
                'enrolled_on' => '2026-09-02',
                'primary_phone' => '+243000000',
            ])
            ->assertOk()
            ->assertJsonPath('data.student_portal_status', 'disabled_until_7e');

        $this->assertDatabaseHas('users', [
            'id' => $student->user_id,
            'is_active' => false,
        ]);
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $student->user_id)->count());

        $this->postJson('/api/v1/auth/login', [
            'identifier' => $created->json('data.registration_number'),
            'password' => $created->json('meta.portal_credentials.0.password'),
        ])->assertUnprocessable();
    }

    public function test_creating_student_creates_and_attaches_parents_from_dossier(): void
    {
        $classroom = $this->classroom();
        $year = SchoolYear::factory()->create();

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/students', [
                'first_name' => 'Jean',
                'last_name' => 'Tshibangu',
                'middle_name' => 'Kasongo',
                'classroom_id' => $classroom->id,
                'enrollment_school_year_id' => $year->id,
                'date_of_birth' => '2013-03-10',
                'place_of_birth' => 'Lubumbashi',
                'gender' => 'M',
                'nationality' => 'Congolaise',
                'enrollment_status' => 'actif',
                'order_number' => 'REG-PARENT-001',
                'enrolled_on' => '2026-09-02',
                'father_name' => 'Pierre Tshibangu',
                'mother_name' => 'Marie Kasongo',
                'primary_phone' => '+243810000001',
                'secondary_phone' => '+243810000002',
                'parent_email' => 'pierre.tshibangu@example.test',
                'residential_address' => 'Quartier Golf',
            ])
            ->assertCreated()
            ->assertJsonCount(2, 'data.parents');

        $student = Student::query()
            ->with('parents.user')
            ->findOrFail($response->json('data.id'));

        $this->assertCount(2, $student->parents);
        $this->assertTrue($student->parents->contains(
            fn (ParentProfile $parent) => $parent->pivot->relation === 'pere'
                && $parent->user?->name === 'Pierre Tshibangu'
                && $parent->user?->email === 'pierre.tshibangu@example.test'
                && $parent->phone === '+243810000001'
                && $parent->address === 'Quartier Golf',
        ));
        $this->assertTrue($student->parents->contains(
            fn (ParentProfile $parent) => $parent->pivot->relation === 'mere'
                && $parent->user?->name === 'Marie Kasongo'
                && $parent->phone === '+243810000002',
        ));
    }

    public function test_filter_students_by_classroom(): void
    {
        $c1 = $this->classroom();
        $c2 = ClassRoom::factory()->create(['level_id' => Level::factory()->create()->id, 'section' => 'B']);
        Student::factory()->create(['classroom_id' => $c1->id, 'last_name' => 'AAA']);
        Student::factory()->create(['classroom_id' => $c2->id, 'last_name' => 'BBB']);

        $res = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/students?classroom_id='.$c1->id)
            ->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame('AAA', $res->json('data.0.last_name'));
    }

    public function test_students_default_to_current_school_year_and_allow_historical_filter(): void
    {
        $classroom = $this->classroom();
        $oldYear = SchoolYear::factory()->create(['name' => '2024-2025']);
        $currentYear = SchoolYear::factory()->current()->create(['name' => '2025-2026']);

        Student::factory()->create([
            'classroom_id' => $classroom->id,
            'enrollment_school_year_id' => $oldYear->id,
            'last_name' => 'Ancien',
        ]);
        Student::factory()->create([
            'classroom_id' => $classroom->id,
            'enrollment_school_year_id' => $currentYear->id,
            'last_name' => 'Courant',
        ]);

        $default = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/students')
            ->assertOk();

        $this->assertCount(1, $default->json('data'));
        $this->assertSame('Courant', $default->json('data.0.last_name'));

        $historical = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/students?school_year_id='.$oldYear->id)
            ->assertOk();

        $this->assertCount(1, $historical->json('data'));
        $this->assertSame('Ancien', $historical->json('data.0.last_name'));
    }

    public function test_new_current_school_year_does_not_load_previous_students(): void
    {
        $classroom = $this->classroom();
        $oldYear = SchoolYear::factory()->current()->create(['name' => '2024-2025']);
        $newYear = SchoolYear::factory()->create(['name' => '2025-2026']);

        Student::factory()->create([
            'classroom_id' => $classroom->id,
            'enrollment_school_year_id' => $oldYear->id,
            'last_name' => 'Ancien',
        ]);
        Student::factory()->create([
            'classroom_id' => $classroom->id,
            'enrollment_school_year_id' => null,
            'last_name' => 'SansAnnee',
        ]);

        $newYear->forceFill(['is_current' => true])->save();

        $default = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/students')
            ->assertOk();

        $this->assertCount(0, $default->json('data'));
    }

    public function test_filter_students_by_cycle(): void
    {
        $primaryLevel = Level::factory()->create(['cycle' => Level::CYCLE_PRIMAIRE]);
        $secondaryLevel = Level::factory()->create(['cycle' => Level::CYCLE_SECONDAIRE]);
        $primaryClassroom = ClassRoom::factory()->create(['level_id' => $primaryLevel->id, 'section' => 'A']);
        $secondaryClassroom = ClassRoom::factory()->create(['level_id' => $secondaryLevel->id, 'section' => 'A']);

        Student::factory()->create(['classroom_id' => $primaryClassroom->id, 'last_name' => 'Primaire']);
        Student::factory()->create(['classroom_id' => $secondaryClassroom->id, 'last_name' => 'Secondaire']);

        $res = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/students?cycle='.Level::CYCLE_PRIMAIRE)
            ->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame('Primaire', $res->json('data.0.last_name'));
    }

    public function test_search_students_by_name(): void
    {
        $c = $this->classroom();
        Student::factory()->create(['classroom_id' => $c->id, 'first_name' => 'Marie', 'last_name' => 'Kabongo']);
        Student::factory()->create(['classroom_id' => $c->id, 'first_name' => 'Paul', 'last_name' => 'Mbuyi']);

        $res = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/students?search=Marie')
            ->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame('Marie', $res->json('data.0.first_name'));
    }

    public function test_unique_registration_number(): void
    {
        Student::factory()->create(['registration_number' => 'EDU-001']);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/students', [
                'first_name' => 'A',
                'last_name' => 'B',
                'middle_name' => 'C',
                'classroom_id' => $this->classroom()->id,
                'enrollment_school_year_id' => SchoolYear::factory()->create()->id,
                'date_of_birth' => '2014-05-12',
                'place_of_birth' => 'Lubumbashi',
                'gender' => 'M',
                'nationality' => 'Congolaise',
                'registration_number' => 'EDU-001',
                'enrollment_status' => 'actif',
                'order_number' => 'REG-002',
                'enrolled_on' => '2026-09-02',
                'primary_phone' => '+243000001',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['registration_number']);
    }

    // ─── Liaison parent ↔ élève ─────────────────────────────────────────────

    public function test_can_attach_parent_to_student(): void
    {
        $student = Student::factory()->create();
        $parent = ParentProfile::factory()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/students/{$student->id}/parents", [
                'parent_profile_id' => $parent->id,
                'relation' => 'mere',
            ])
            ->assertOk();

        $this->assertDatabaseHas('parent_student', [
            'student_id' => $student->id,
            'parent_profile_id' => $parent->id,
            'relation' => 'mere',
        ]);
    }

    public function test_can_detach_parent_from_student(): void
    {
        $student = Student::factory()->create();
        $parent = ParentProfile::factory()->create();
        $student->parents()->attach($parent->id, ['relation' => 'pere']);

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/v1/students/{$student->id}/parents/{$parent->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('parent_student', [
            'student_id' => $student->id,
            'parent_profile_id' => $parent->id,
        ]);
    }

    public function test_invalid_relation_rejected(): void
    {
        $student = Student::factory()->create();
        $parent = ParentProfile::factory()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/students/{$student->id}/parents", [
                'parent_profile_id' => $parent->id,
                'relation' => 'cousin',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['relation']);
    }
}
