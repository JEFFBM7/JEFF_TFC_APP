<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\ClassRoom;
use App\Models\Level;
use App\Models\SchoolClass;
use App\Models\SchoolOption;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentOptionChoiceTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{user: User, student: Student, year: SchoolYear, option: SchoolOption} */
    private function scaffoldCtebStudent(string $yearEndsOn): array
    {
        $levelCteb = Level::factory()->create(['order' => 21, 'has_options' => false, 'cycle' => Level::CYCLE_CTEB]);
        Level::factory()->create(['order' => 30, 'has_options' => true, 'cycle' => Level::CYCLE_SECONDAIRE]);

        $option = SchoolOption::query()->create([
            'name' => 'Scientifique',
            'abbreviation' => 'SCI',
            'cycle' => Level::CYCLE_SECONDAIRE,
            'filiere' => 'generale',
        ]);

        $year = SchoolYear::factory()->current()->create([
            'starts_on' => now()->subMonths(9)->toDateString(),
            'ends_on' => $yearEndsOn,
        ]);

        $schoolClass = SchoolClass::factory()->create([
            'school_year_id' => $year->id,
            'level_id' => $levelCteb->id,
            'school_option_id' => null,
        ]);
        $classroom = ClassRoom::factory()->create([
            'school_class_id' => $schoolClass->id,
            'level_id' => $levelCteb->id,
            'section' => 'A',
        ]);

        $user = User::factory()->create(['role' => UserRole::Eleve]);
        $student = Student::factory()->create([
            'user_id' => $user->id,
            'classroom_id' => $classroom->id,
            'enrollment_school_year_id' => $year->id,
        ]);

        return compact('user', 'student', 'year', 'option');
    }

    public function test_form_is_closed_before_the_one_week_window(): void
    {
        $s = $this->scaffoldCtebStudent(now()->addDays(30)->toDateString());

        $this->actingAs($s['user'], 'sanctum')
            ->getJson('/api/v1/student/option-choice')
            ->assertOk()
            ->assertJsonPath('data.eligible', true)
            ->assertJsonPath('data.open', false);

        $this->actingAs($s['user'], 'sanctum')
            ->putJson('/api/v1/student/option-choice', ['school_option_id' => $s['option']->id])
            ->assertForbidden();
    }

    public function test_cteb_student_can_submit_choice_within_window(): void
    {
        $s = $this->scaffoldCtebStudent(now()->addDays(5)->toDateString());

        $this->actingAs($s['user'], 'sanctum')
            ->getJson('/api/v1/student/option-choice')
            ->assertOk()
            ->assertJsonPath('data.eligible', true)
            ->assertJsonPath('data.open', true);

        $this->actingAs($s['user'], 'sanctum')
            ->putJson('/api/v1/student/option-choice', ['school_option_id' => $s['option']->id])
            ->assertCreated()
            ->assertJsonPath('data.option_name', 'Scientifique');

        $this->assertDatabaseHas('student_option_choices', [
            'student_id' => $s['student']->id,
            'school_year_id' => $s['year']->id,
            'school_option_id' => $s['option']->id,
        ]);

        $this->actingAs($s['user'], 'sanctum')
            ->getJson('/api/v1/student/option-choice')
            ->assertOk()
            ->assertJsonPath('data.choice.school_option_id', $s['option']->id);
    }

    public function test_non_terminal_cteb_student_is_not_eligible(): void
    {
        // Élève de primaire : le niveau suivant n'est pas à options.
        $levelP5 = Level::factory()->create(['order' => 14, 'has_options' => false, 'cycle' => Level::CYCLE_PRIMAIRE]);
        Level::factory()->create(['order' => 15, 'has_options' => false, 'cycle' => Level::CYCLE_PRIMAIRE]);

        $year = SchoolYear::factory()->current()->create([
            'starts_on' => now()->subMonths(9)->toDateString(),
            'ends_on' => now()->addDays(3)->toDateString(),
        ]);
        $schoolClass = SchoolClass::factory()->create([
            'school_year_id' => $year->id,
            'level_id' => $levelP5->id,
            'school_option_id' => null,
        ]);
        $classroom = ClassRoom::factory()->create([
            'school_class_id' => $schoolClass->id,
            'level_id' => $levelP5->id,
            'section' => 'A',
        ]);

        $user = User::factory()->create(['role' => UserRole::Eleve]);
        Student::factory()->create([
            'user_id' => $user->id,
            'classroom_id' => $classroom->id,
            'enrollment_school_year_id' => $year->id,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/student/option-choice')
            ->assertOk()
            ->assertJsonPath('data.eligible', false);
    }
}
