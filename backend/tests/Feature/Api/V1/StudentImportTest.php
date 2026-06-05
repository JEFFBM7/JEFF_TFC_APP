<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\ClassRoom;
use App\Models\Level;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Tests\TestCase;

class StudentImportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    private function csv(string $content): File
    {
        $path = tempnam(sys_get_temp_dir(), 'csv_');
        file_put_contents($path, $content);

        return new File('eleves.csv', fopen($path, 'rb'));
    }

    // ─── Template ────────────────────────────────────────────────────────

    public function test_admin_can_download_csv_template(): void
    {
        $res = $this->actingAs($this->admin(), 'sanctum')
            ->get('/api/v1/students/import/template')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $body = $res->streamedContent();
        $this->assertStringContainsString('first_name', $body);
        $this->assertStringContainsString('last_name', $body);
        $this->assertStringContainsString('postnom', $body);
    }

    public function test_non_admin_cannot_download_template(): void
    {
        $user = User::factory()->create(['role' => UserRole::Parent]);

        $this->actingAs($user, 'sanctum')
            ->get('/api/v1/students/import/template')
            ->assertForbidden();
    }

    // ─── Import succès ───────────────────────────────────────────────────

    public function test_admin_can_import_minimal_csv(): void
    {
        $csv = "last_name,postnom,first_name\nDiop,Ilunga,Marie\nMbeki,Kabongo,Paul\n";

        $res = $this->actingAs($this->admin(), 'sanctum')
            ->post('/api/v1/students/import', [
                'file' => $this->csv($csv),
            ])
            ->assertOk();

        $this->assertSame(2, $res->json('created'));
        $this->assertSame(0, $res->json('skipped'));
        $this->assertCount(0, $res->json('errors'));
        $this->assertDatabaseHas('students', ['first_name' => 'Marie', 'last_name' => 'Diop', 'middle_name' => 'Ilunga']);
        $this->assertDatabaseHas('students', ['first_name' => 'Paul', 'last_name' => 'Mbeki', 'middle_name' => 'Kabongo']);
    }

    public function test_import_creates_user_account_when_email_provided(): void
    {
        $level = Level::factory()->create([
            'name' => '7e CTEB',
            'cycle' => Level::CYCLE_CTEB,
            'order' => 20,
        ]);
        $classroom = ClassRoom::factory()->create(['level_id' => $level->id, 'section' => 'A']);
        $csv = "last_name,postnom,first_name,email,classroom\nKone,Ilunga,Lina,lina@test.com,{$classroom->full_name}\n";

        $res = $this->actingAs($this->admin(), 'sanctum')
            ->post('/api/v1/students/import', ['file' => $this->csv($csv)])
            ->assertOk();

        $this->assertSame(1, $res->json('created'));
        $this->assertCount(1, $res->json('credentials'));
        $this->assertSame('lina@test.com', $res->json('credentials.0.email'));
        $this->assertDatabaseHas('users', ['email' => 'lina@test.com', 'role' => 'eleve']);
    }

    public function test_import_ignores_student_account_email_before_cteb(): void
    {
        $level = Level::factory()->create([
            'name' => '6e primaire',
            'cycle' => Level::CYCLE_PRIMAIRE,
            'order' => 15,
        ]);
        $classroom = ClassRoom::factory()->create(['level_id' => $level->id, 'section' => 'A']);
        $csv = "last_name,postnom,first_name,email,classroom\nKone,Ilunga,Lina,lina@test.com,{$classroom->full_name}\n";

        $res = $this->actingAs($this->admin(), 'sanctum')
            ->post('/api/v1/students/import', ['file' => $this->csv($csv)])
            ->assertOk();

        $this->assertSame(1, $res->json('created'));
        $this->assertCount(0, $res->json('credentials'));
        $this->assertCount(1, $res->json('warnings'));
        $this->assertDatabaseMissing('users', ['email' => 'lina@test.com']);
    }

    public function test_import_resolves_classroom_by_name(): void
    {
        $level = Level::factory()->create(['name' => '6ème']);
        $classroom = ClassRoom::factory()->create([
            'level_id' => $level->id,
            'section' => 'A',
        ]);

        $csv = "last_name,postnom,first_name,classroom\nDiop,Ilunga,Marie,{$classroom->full_name}\n";

        $this->actingAs($this->admin(), 'sanctum')
            ->post('/api/v1/students/import', ['file' => $this->csv($csv)])
            ->assertOk()
            ->assertJsonPath('created', 1);

        $this->assertDatabaseHas('students', [
            'first_name' => 'Marie',
            'middle_name' => 'Ilunga',
            'classroom_id' => $classroom->id,
        ]);
    }

    public function test_import_updates_existing_student_without_school_year(): void
    {
        $year = SchoolYear::factory()->current()->create();
        $level = Level::factory()->create(['name' => '5e primaire']);
        $classroom = ClassRoom::factory()->create([
            'level_id' => $level->id,
            'section' => 'A',
        ]);
        $student = Student::factory()->create([
            'registration_number' => 'MAL-IMPORT-2026-0001',
            'enrollment_school_year_id' => null,
            'classroom_id' => null,
            'first_name' => 'Ancien',
            'last_name' => 'Nom',
            'middle_name' => 'Postnom',
        ]);

        $csv = "last_name,postnom,first_name,registration_number,classroom\nKakudji,Lukusa,Junior,MAL-IMPORT-2026-0001,{$classroom->full_name}\n";

        $res = $this->actingAs($this->admin(), 'sanctum')
            ->post('/api/v1/students/import?school_year_id='.$year->id, ['file' => $this->csv($csv)])
            ->assertOk();

        $this->assertSame(0, $res->json('created'));
        $this->assertSame(1, $res->json('updated'));
        $this->assertSame(0, $res->json('skipped'));
        $this->assertDatabaseHas('students', [
            'id' => $student->id,
            'registration_number' => 'MAL-IMPORT-2026-0001',
            'enrollment_school_year_id' => $year->id,
            'classroom_id' => $classroom->id,
            'first_name' => 'Junior',
            'last_name' => 'Kakudji',
            'middle_name' => 'Lukusa',
        ]);
    }

    // ─── Import erreurs ──────────────────────────────────────────────────

    public function test_import_rejects_missing_required_column(): void
    {
        $csv = "first_name\nMarie\n";

        $res = $this->actingAs($this->admin(), 'sanctum')
            ->post('/api/v1/students/import', ['file' => $this->csv($csv)])
            ->assertOk();

        $this->assertSame(0, $res->json('created'));
        $this->assertCount(1, $res->json('errors'));
    }

    public function test_import_skips_invalid_rows(): void
    {
        $csv = "last_name,postnom,first_name,gender\nDiop,Ilunga,Marie,F\n,Kasongo,Bob,X\nKone,Mbuyi,Lina,Z\n";

        $res = $this->actingAs($this->admin(), 'sanctum')
            ->post('/api/v1/students/import', ['file' => $this->csv($csv)])
            ->assertOk();

        $this->assertSame(1, $res->json('created'));
        $this->assertSame(2, $res->json('skipped'));
        $this->assertCount(2, $res->json('errors'));
    }

    public function test_import_rejects_duplicate_registration_number(): void
    {
        Student::factory()->create(['registration_number' => 'M001']);

        $csv = "last_name,postnom,first_name,registration_number\nDiop,Ilunga,Marie,M001\n";

        $res = $this->actingAs($this->admin(), 'sanctum')
            ->post('/api/v1/students/import', ['file' => $this->csv($csv)])
            ->assertOk();

        $this->assertSame(0, $res->json('created'));
        $this->assertSame(1, $res->json('skipped'));
    }
}
