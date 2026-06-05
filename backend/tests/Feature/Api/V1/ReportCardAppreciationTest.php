<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\AppSetting;
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
use App\Notifications\LowAverageNotification;
use App\Notifications\ReportCardPublishedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ReportCardAppreciationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    /** @return array{student:Student,term:Term} */
    private function context(): array
    {
        $year = SchoolYear::factory()->create(['name' => '2025-2026']);
        $term = Term::factory()->create(['school_year_id' => $year->id, 'name' => 'T1', 'position' => 1]);
        $student = Student::factory()->create(['enrollment_school_year_id' => $year->id]);
        $term = $term;

        return compact('student', 'term');
    }

    // ─── Appréciations ───────────────────────────────────────────────────

    public function test_admin_can_create_and_update_appreciation(): void
    {
        $ctx = $this->context();

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/v1/students/{$ctx['student']->id}/appreciations/{$ctx['term']->id}", [
                'content' => 'Élève sérieux, à encourager.',
            ])
            ->assertOk()
            ->assertJsonPath('data.content', 'Élève sérieux, à encourager.');

        $this->assertDatabaseHas('report_card_appreciations', [
            'student_id' => $ctx['student']->id,
            'term_id' => $ctx['term']->id,
            'content' => 'Élève sérieux, à encourager.',
        ]);

        // Update
        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/v1/students/{$ctx['student']->id}/appreciations/{$ctx['term']->id}", [
                'content' => 'Bilan modifié.',
            ])
            ->assertOk()
            ->assertJsonPath('data.content', 'Bilan modifié.');

        $this->assertSame(1, \App\Models\ReportCardAppreciation::query()->count());
    }

    public function test_parent_cannot_create_appreciation(): void
    {
        $ctx = $this->context();
        $parent = User::factory()->create(['role' => UserRole::Parent]);

        $this->actingAs($parent, 'sanctum')
            ->putJson("/api/v1/students/{$ctx['student']->id}/appreciations/{$ctx['term']->id}", [
                'content' => 'X',
            ])
            ->assertForbidden();
    }

    public function test_appreciation_appears_in_report_card_json(): void
    {
        $ctx = $this->context();

        \App\Models\ReportCardAppreciation::query()->create([
            'student_id' => $ctx['student']->id,
            'term_id' => $ctx['term']->id,
            'content' => 'Très bon trimestre.',
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/v1/students/{$ctx['student']->id}/report-cards/{$ctx['term']->id}")
            ->assertOk()
            ->assertJsonPath('data.appreciation', 'Très bon trimestre.');
    }

    // ─── Clôture trimestre ────────────────────────────────────────────────

    public function test_parent_cannot_close_term(): void
    {
        $ctx = $this->context();
        $parent = User::factory()->create(['role' => UserRole::Parent]);

        $this->actingAs($parent, 'sanctum')
            ->postJson("/api/v1/terms/{$ctx['term']->id}/close")
            ->assertForbidden();
    }

    public function test_admin_can_close_term_and_emails_parents(): void
    {
        Notification::fake();

        $ctx = $this->context();

        $parentUser = User::factory()->create([
            'role' => UserRole::Parent,
            'email' => 'maman@test.com',
        ]);
        $profile = ParentProfile::factory()->create(['user_id' => $parentUser->id]);
        $ctx['student']->parents()->attach($profile->id, ['relation' => 'mere']);

        $res = $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/terms/{$ctx['term']->id}/close")
            ->assertOk();

        $this->assertNotNull($res->json('closed_at'));
        $this->assertSame(1, $res->json('students_notified'));
        $this->assertSame(1, $res->json('parents_notified'));

        Notification::assertSentTo($parentUser, ReportCardPublishedNotification::class);
        $this->assertNotNull($ctx['term']->fresh()->closed_at);
    }

    public function test_close_term_sends_low_average_notification_when_enabled(): void
    {
        Notification::fake();

        $year = SchoolYear::factory()->create(['name' => '2025-2026']);
        $term = Term::factory()->create(['school_year_id' => $year->id, 'name' => 'T1', 'position' => 1]);
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
        ]);
        Grade::query()->create([
            'evaluation_id' => $evaluation->id,
            'student_id' => $student->id,
            'value' => 7,
            'absent' => false,
        ]);

        $parentUser = User::factory()->create(['role' => UserRole::Parent, 'email' => 'parent.low@test.com']);
        $profile = ParentProfile::factory()->create(['user_id' => $parentUser->id]);
        $student->parents()->attach($profile->id, ['relation' => 'mere']);

        AppSetting::set('grades.low_average_threshold', 8.0);
        AppSetting::set('grades.notify_parents_on_low_average', true);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/terms/{$term->id}/close")
            ->assertOk()
            ->assertJsonPath('low_average_alerts', 1);

        Notification::assertSentTo($parentUser, LowAverageNotification::class);
    }

    public function test_already_closed_term_returns_422(): void
    {
        $ctx = $this->context();
        $ctx['term']->update(['closed_at' => now()]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/terms/{$ctx['term']->id}/close")
            ->assertStatus(422);
    }

    public function test_term_resource_exposes_closed_state(): void
    {
        $ctx = $this->context();

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/terms?school_year_id='.$ctx['term']->school_year_id)
            ->assertOk()
            ->assertJsonPath('data.0.is_closed', false);
    }
}
