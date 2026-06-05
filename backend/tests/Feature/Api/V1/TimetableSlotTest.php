<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\ClassRoom;
use App\Models\Level;
use App\Models\SchoolYear;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TimetableSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimetableSlotTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    /** @return array{classroom:ClassRoom,subject:Subject,teacher:Teacher,year:SchoolYear} */
    private function context(): array
    {
        $level = Level::factory()->create();
        $classroom = ClassRoom::factory()->create(['level_id' => $level->id]);
        $subject = Subject::factory()->create(['name' => 'Maths']);
        $teacher = Teacher::factory()->create();
        $year = SchoolYear::factory()->create(['name' => '2025-2026']);

        return compact('classroom', 'subject', 'teacher', 'year');
    }

    public function test_parent_cannot_create_slot(): void
    {
        $user = User::factory()->create(['role' => UserRole::Parent]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/timetable-slots', [])
            ->assertForbidden();
    }

    public function test_admin_can_create_slot(): void
    {
        $ctx = $this->context();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/timetable-slots', [
                'classroom_id' => $ctx['classroom']->id,
                'subject_id' => $ctx['subject']->id,
                'teacher_id' => $ctx['teacher']->id,
                'school_year_id' => $ctx['year']->id,
                'day_of_week' => 1,
                'starts_at' => '08:00',
                'ends_at' => '09:00',
                'room' => 'A12',
            ])
            ->assertCreated()
            ->assertJsonPath('data.day_of_week', 1)
            ->assertJsonPath('data.room', 'A12');
    }

    public function test_end_must_be_after_start(): void
    {
        $ctx = $this->context();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/timetable-slots', [
                'classroom_id' => $ctx['classroom']->id,
                'subject_id' => $ctx['subject']->id,
                'teacher_id' => $ctx['teacher']->id,
                'school_year_id' => $ctx['year']->id,
                'day_of_week' => 1,
                'starts_at' => '10:00',
                'ends_at' => '09:00',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ends_at']);
    }

    public function test_overlapping_slot_in_same_classroom_rejected(): void
    {
        $ctx = $this->context();
        TimetableSlot::query()->create([
            'classroom_id' => $ctx['classroom']->id,
            'subject_id' => $ctx['subject']->id,
            'teacher_id' => $ctx['teacher']->id,
            'school_year_id' => $ctx['year']->id,
            'day_of_week' => 2,
            'starts_at' => '08:00',
            'ends_at' => '09:00',
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/timetable-slots', [
                'classroom_id' => $ctx['classroom']->id,
                'subject_id' => $ctx['subject']->id,
                'teacher_id' => $ctx['teacher']->id,
                'school_year_id' => $ctx['year']->id,
                'day_of_week' => 2,
                'starts_at' => '08:30',
                'ends_at' => '09:30',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at']);
    }

    public function test_filter_by_classroom(): void
    {
        $ctx = $this->context();
        TimetableSlot::query()->create([
            'classroom_id' => $ctx['classroom']->id,
            'subject_id' => $ctx['subject']->id,
            'teacher_id' => $ctx['teacher']->id,
            'school_year_id' => $ctx['year']->id,
            'day_of_week' => 3,
            'starts_at' => '10:00',
            'ends_at' => '11:00',
        ]);

        $level2 = Level::factory()->create();
        $other = ClassRoom::factory()->create(['level_id' => $level2->id, 'section' => 'A']);
        TimetableSlot::query()->create([
            'classroom_id' => $other->id,
            'subject_id' => $ctx['subject']->id,
            'teacher_id' => $ctx['teacher']->id,
            'school_year_id' => $ctx['year']->id,
            'day_of_week' => 3,
            'starts_at' => '10:00',
            'ends_at' => '11:00',
        ]);

        $res = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/timetable-slots?classroom_id='.$ctx['classroom']->id)
            ->assertOk();

        $this->assertCount(1, $res->json('data'));
    }

    public function test_filter_by_cycle(): void
    {
        $ctx = $this->context();
        $primaryLevel = Level::factory()->create(['cycle' => Level::CYCLE_PRIMAIRE]);
        $secondaryLevel = Level::factory()->create(['cycle' => Level::CYCLE_SECONDAIRE]);
        $primaryClassroom = ClassRoom::factory()->create(['level_id' => $primaryLevel->id, 'section' => 'A']);
        $secondaryClassroom = ClassRoom::factory()->create(['level_id' => $secondaryLevel->id, 'section' => 'A']);

        TimetableSlot::query()->create([
            'classroom_id' => $primaryClassroom->id,
            'subject_id' => $ctx['subject']->id,
            'teacher_id' => $ctx['teacher']->id,
            'school_year_id' => $ctx['year']->id,
            'day_of_week' => 3,
            'starts_at' => '10:00',
            'ends_at' => '11:00',
        ]);
        TimetableSlot::query()->create([
            'classroom_id' => $secondaryClassroom->id,
            'subject_id' => $ctx['subject']->id,
            'teacher_id' => $ctx['teacher']->id,
            'school_year_id' => $ctx['year']->id,
            'day_of_week' => 3,
            'starts_at' => '10:00',
            'ends_at' => '11:00',
        ]);

        $res = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/timetable-slots?cycle='.Level::CYCLE_PRIMAIRE)
            ->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame($primaryClassroom->id, $res->json('data.0.classroom_id'));
    }

    public function test_teacher_can_list_timetable_slots(): void
    {
        $teacher = User::factory()->create(['role' => UserRole::Enseignant]);

        $this->actingAs($teacher, 'sanctum')
            ->getJson('/api/v1/timetable-slots')
            ->assertOk();
    }

    public function test_teacher_only_lists_own_timetable_slots(): void
    {
        $ctx = $this->context();
        $teacherUser = User::factory()->create(['role' => UserRole::Enseignant]);
        $ownTeacher = Teacher::factory()->create(['user_id' => $teacherUser->id]);
        $otherTeacher = Teacher::factory()->create();

        TimetableSlot::query()->create([
            'classroom_id' => $ctx['classroom']->id,
            'subject_id' => $ctx['subject']->id,
            'teacher_id' => $ownTeacher->id,
            'school_year_id' => $ctx['year']->id,
            'day_of_week' => 1,
            'starts_at' => '08:00',
            'ends_at' => '09:00',
        ]);

        TimetableSlot::query()->create([
            'classroom_id' => $ctx['classroom']->id,
            'subject_id' => $ctx['subject']->id,
            'teacher_id' => $otherTeacher->id,
            'school_year_id' => $ctx['year']->id,
            'day_of_week' => 2,
            'starts_at' => '09:00',
            'ends_at' => '10:00',
        ]);

        $res = $this->actingAs($teacherUser, 'sanctum')
            ->getJson('/api/v1/timetable-slots')
            ->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame($ownTeacher->id, $res->json('data.0.teacher_id'));

        $this->actingAs($teacherUser, 'sanctum')
            ->getJson('/api/v1/timetable-slots?teacher_id='.$otherTeacher->id)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
