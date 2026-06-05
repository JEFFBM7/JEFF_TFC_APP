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
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportsCsvTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    public function test_parent_cannot_access_attendance_csv(): void
    {
        $parent = User::factory()->create(['role' => UserRole::Parent]);

        $this->actingAs($parent, 'sanctum')
            ->get('/api/v1/reports/attendance/csv')
            ->assertForbidden();
    }

    public function test_attendance_csv_returns_text_csv(): void
    {
        $level = Level::factory()->create();
        $classroom = ClassRoom::factory()->create(['level_id' => $level->id, 'section' => 'A']);
        $student = Student::factory()->create(['classroom_id' => $classroom->id]);
        Attendance::factory()->create([
            'student_id' => $student->id,
            'classroom_id' => $classroom->id,
            'status' => Attendance::STATUS_ABSENT,
            'justified' => false,
        ]);

        $res = $this->actingAs($this->admin(), 'sanctum')
            ->get('/api/v1/reports/attendance/csv')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $body = $res->streamedContent();
        $this->assertStringContainsString('classe', $body);
        $this->assertStringContainsString('absent_non_justifie', $body);
        $this->assertStringContainsString($classroom->full_name, $body);
    }

    public function test_class_ranking_csv_returns_sorted_rows(): void
    {
        $level = Level::factory()->create();
        $classroom = ClassRoom::factory()->create(['level_id' => $level->id, 'section' => 'B']);
        $subject = Subject::factory()->create(['name' => 'Maths']);
        $classroom->subjects()->attach($subject->id, ['coefficient' => 1]);

        $year = SchoolYear::factory()->create(['name' => '2025-2026']);
        $term = Term::factory()->create([
            'school_year_id' => $year->id,
            'name' => 'T1',
            'position' => 1,
        ]);

        $top = Student::factory()->create([
            'classroom_id' => $classroom->id,
            'enrollment_school_year_id' => $year->id,
            'first_name' => 'Top',
            'last_name' => 'Student',
            'middle_name' => 'Ilunga',
        ]);
        $low = Student::factory()->create([
            'classroom_id' => $classroom->id,
            'enrollment_school_year_id' => $year->id,
            'first_name' => 'Low',
            'last_name' => 'Student',
            'middle_name' => 'Ilunga',
        ]);

        $eval = Evaluation::factory()->create([
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
            'term_id' => $term->id,
        ]);
        Grade::query()->create(['evaluation_id' => $eval->id, 'student_id' => $top->id, 'value' => 18]);
        Grade::query()->create(['evaluation_id' => $eval->id, 'student_id' => $low->id, 'value' => 8]);

        $res = $this->actingAs($this->admin(), 'sanctum')
            ->get("/api/v1/reports/classrooms/{$classroom->id}/ranking/{$term->id}/csv")
            ->assertOk();

        $body = $res->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($body))));
        // ligne 0 = BOM + en-tête, ligne 1 = top (rang 1), ligne 2 = low (rang 2)
        $this->assertStringContainsString('Student Ilunga Top', $lines[1]);
        $this->assertStringContainsString('Student Ilunga Low', $lines[2]);
    }

    public function test_student_evolution_csv_lists_all_terms(): void
    {
        $year = SchoolYear::factory()->create(['name' => '2025-2026']);
        Term::factory()->create(['school_year_id' => $year->id, 'name' => 'T1', 'position' => 1]);
        Term::factory()->create(['school_year_id' => $year->id, 'name' => 'T2', 'position' => 2]);
        Term::factory()->create(['school_year_id' => $year->id, 'name' => 'T3', 'position' => 3]);

        $student = Student::factory()->create();

        $res = $this->actingAs($this->admin(), 'sanctum')
            ->get("/api/v1/reports/students/{$student->id}/evolution/csv?school_year_id={$year->id}")
            ->assertOk();

        $body = $res->streamedContent();
        $this->assertStringContainsString('T1', $body);
        $this->assertStringContainsString('T2', $body);
        $this->assertStringContainsString('T3', $body);
    }
}
