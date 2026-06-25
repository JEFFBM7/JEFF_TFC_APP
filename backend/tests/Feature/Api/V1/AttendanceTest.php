<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\ClassRoom;
use App\Models\Level;
use App\Models\ParentProfile;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Notifications\AttendanceThresholdNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    private function teacher(): User
    {
        return User::factory()->create(['role' => UserRole::Enseignant]);
    }

    private function secretariat(): User
    {
        return User::factory()->create(['role' => UserRole::Secretariat]);
    }

    /** @return array{classroom: ClassRoom, student: Student, subject: Subject} */
    private function setupClass(): array
    {
        $level = Level::factory()->create();
        $classroom = ClassRoom::factory()->create(['level_id' => $level->id, 'section' => 'A']);
        $student = Student::factory()->create(['classroom_id' => $classroom->id]);
        $subject = Subject::factory()->create(['name' => 'Français']);

        return compact('classroom', 'student', 'subject');
    }

    public function test_parent_cannot_access_roll_call(): void
    {
        $ctx = $this->setupClass();
        $parent = User::factory()->create(['role' => UserRole::Parent]);

        $this->actingAs($parent, 'sanctum')
            ->getJson('/api/v1/attendances/roll-call?'.http_build_query([
                'classroom_id' => $ctx['classroom']->id,
                'date' => '2026-05-01',
            ]))
            ->assertForbidden();
    }

    public function test_teacher_can_list_classrooms_for_attendance_flow(): void
    {
        $this->setupClass();
        $this->actingAs($this->teacher(), 'sanctum')
            ->getJson('/api/v1/classrooms')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_roll_call_returns_students_with_default_present(): void
    {
        $ctx = $this->setupClass();

        $res = $this->actingAs($this->teacher(), 'sanctum')
            ->getJson('/api/v1/attendances/roll-call?'.http_build_query([
                'classroom_id' => $ctx['classroom']->id,
                'date' => '2026-05-01',
                'subject_id' => $ctx['subject']->id,
            ]))
            ->assertOk();

        $rows = $res->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('present', $rows[0]['status']);
        $this->assertSame($ctx['student']->id, $rows[0]['student_id']);
    }

    public function test_attendances_default_to_current_school_year_and_allow_historical_filter(): void
    {
        $ctx = $this->setupClass();
        $oldYear = \App\Models\SchoolYear::factory()->create([
            'name' => '2024-2025',
            'starts_on' => '2024-09-01',
            'ends_on' => '2025-07-31',
        ]);
        \App\Models\SchoolYear::factory()->current()->create([
            'name' => '2025-2026',
            'starts_on' => '2025-09-01',
            'ends_on' => '2026-07-31',
        ]);

        Attendance::factory()->create([
            'student_id' => $ctx['student']->id,
            'classroom_id' => $ctx['classroom']->id,
            'date' => '2024-10-01',
            'status' => Attendance::STATUS_ABSENT,
        ]);
        Attendance::factory()->create([
            'student_id' => $ctx['student']->id,
            'classroom_id' => $ctx['classroom']->id,
            'date' => '2025-10-01',
            'status' => Attendance::STATUS_LATE,
        ]);

        $default = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/attendances')
            ->assertOk();

        $this->assertCount(1, $default->json('data'));
        $this->assertSame('2025-10-01', $default->json('data.0.date'));

        $historical = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/attendances?school_year_id='.$oldYear->id)
            ->assertOk();

        $this->assertCount(1, $historical->json('data'));
        $this->assertSame('2024-10-01', $historical->json('data.0.date'));
    }

    public function test_batch_save_creates_absence_unjustified_by_default(): void
    {
        $ctx = $this->setupClass();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/attendances/batch', [
                'classroom_id' => $ctx['classroom']->id,
                'subject_id' => $ctx['subject']->id,
                'date' => '2026-05-10',
                'records' => [
                    ['student_id' => $ctx['student']->id, 'status' => 'absent'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Présences enregistrées.');

        $this->assertDatabaseHas('attendances', [
            'student_id' => $ctx['student']->id,
            'status' => 'absent',
            'justified' => false,
        ]);
    }

    public function test_batch_save_preserves_justification_when_student_stays_absent(): void
    {
        $ctx = $this->setupClass();
        $att = Attendance::factory()->create([
            'student_id' => $ctx['student']->id,
            'classroom_id' => $ctx['classroom']->id,
            'subject_id' => $ctx['subject']->id,
            'date' => '2026-05-12',
            'status' => Attendance::STATUS_ABSENT,
            'justified' => true,
            'justification' => 'Certificat médical',
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/attendances/batch', [
                'classroom_id' => $ctx['classroom']->id,
                'subject_id' => $ctx['subject']->id,
                'date' => '2026-05-12',
                'records' => [
                    ['student_id' => $ctx['student']->id, 'status' => 'absent'],
                ],
            ])
            ->assertOk();

        $att->refresh();
        $this->assertTrue($att->justified);
        $this->assertSame('Certificat médical', $att->justification);
    }

    public function test_batch_save_clears_justification_when_marked_present(): void
    {
        $ctx = $this->setupClass();
        $att = Attendance::factory()->create([
            'student_id' => $ctx['student']->id,
            'classroom_id' => $ctx['classroom']->id,
            'subject_id' => $ctx['subject']->id,
            'date' => '2026-05-13',
            'status' => Attendance::STATUS_ABSENT,
            'justified' => true,
            'justification' => 'OK',
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/attendances/batch', [
                'classroom_id' => $ctx['classroom']->id,
                'subject_id' => $ctx['subject']->id,
                'date' => '2026-05-13',
                'records' => [
                    ['student_id' => $ctx['student']->id, 'status' => 'present'],
                ],
            ])
            ->assertOk();

        $att->refresh();
        $this->assertFalse($att->justified);
        $this->assertNull($att->justification);
    }

    public function test_secretariat_can_justify_absence(): void
    {
        $ctx = $this->setupClass();
        $att = Attendance::factory()->create([
            'student_id' => $ctx['student']->id,
            'classroom_id' => $ctx['classroom']->id,
            'subject_id' => $ctx['subject']->id,
            'date' => '2026-05-14',
            'status' => Attendance::STATUS_ABSENT,
            'justified' => false,
        ]);

        $this->actingAs($this->secretariat(), 'sanctum')
            ->patchJson("/api/v1/attendances/{$att->id}/justify", [
                'justified' => true,
                'justification' => 'Parents informés',
            ])
            ->assertOk()
            ->assertJsonPath('data.justified', true)
            ->assertJsonPath('data.justification', 'Parents informés');
    }

    public function test_alert_triggered_after_three_consecutive_unjustified_absences(): void
    {
        Carbon::setTestNow('2026-04-03 14:00:00');

        try {
            $ctx = $this->setupClass();
            $s = $ctx['student'];

            foreach (['2026-04-01', '2026-04-02', '2026-04-03'] as $day) {
                Attendance::factory()->create([
                    'student_id' => $s->id,
                    'classroom_id' => $ctx['classroom']->id,
                    'subject_id' => null,
                    'date' => $day,
                    'status' => Attendance::STATUS_ABSENT,
                    'justified' => false,
                ]);
            }

            $json = $this->actingAs($this->admin(), 'sanctum')
                ->getJson("/api/v1/students/{$s->id}/attendance-summary")
                ->assertOk()
                ->json('data.alert');

            $this->assertTrue($json['triggered']);
            $this->assertContains('consecutive_3', $json['reasons']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_alert_not_triggered_when_student_justification_deadline_passed(): void
    {
        Carbon::setTestNow('2026-05-25 12:00:00');

        try {
            $ctx = $this->setupClass();
            $s = $ctx['student'];

            foreach (['2026-05-20', '2026-05-21', '2026-05-22'] as $day) {
                Attendance::factory()->create([
                    'student_id' => $s->id,
                    'classroom_id' => $ctx['classroom']->id,
                    'subject_id' => null,
                    'date' => $day,
                    'status' => Attendance::STATUS_ABSENT,
                    'justified' => false,
                ]);
            }

            $json = $this->actingAs($this->admin(), 'sanctum')
                ->getJson("/api/v1/students/{$s->id}/attendance-summary")
                ->assertOk()
                ->json('data.alert');

            $this->assertFalse($json['triggered']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_student_summary_counts_absences(): void
    {
        $ctx = $this->setupClass();
        $s = $ctx['student'];

        Attendance::factory()->create([
            'student_id' => $s->id,
            'classroom_id' => $ctx['classroom']->id,
            'status' => Attendance::STATUS_ABSENT,
            'justified' => false,
        ]);
        Attendance::factory()->create([
            'student_id' => $s->id,
            'classroom_id' => $ctx['classroom']->id,
            'status' => Attendance::STATUS_LATE,
            'justified' => false,
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/v1/students/{$s->id}/attendance-summary")
            ->assertOk()
            ->assertJsonPath('data.total_absences', 1)
            ->assertJsonPath('data.late_count', 1);
    }

    public function test_parents_receive_email_when_attendance_threshold_reached(): void
    {
        Carbon::setTestNow('2026-04-04 09:00:00');

        try {
            Notification::fake();

            $ctx = $this->setupClass();
            $s = $ctx['student'];

            $parentUser = User::factory()->create([
                'role' => UserRole::Parent,
                'email' => 'parent.alert@example.test',
                'name' => 'Parent Alerte',
            ]);
            $profile = ParentProfile::factory()->create(['user_id' => $parentUser->id]);
            $s->parents()->attach($profile->id, ['relation' => 'mere']);

            foreach (['2026-04-01', '2026-04-02', '2026-04-03'] as $day) {
                Attendance::factory()->create([
                    'student_id' => $s->id,
                    'classroom_id' => $ctx['classroom']->id,
                    'subject_id' => null,
                    'date' => $day,
                    'status' => Attendance::STATUS_ABSENT,
                    'justified' => false,
                ]);
            }

            $this->actingAs($this->admin(), 'sanctum')
                ->postJson('/api/v1/attendances/batch', [
                    'classroom_id' => $ctx['classroom']->id,
                    'date' => '2026-04-04',
                    'records' => [
                        ['student_id' => $s->id, 'status' => 'absent'],
                    ],
                ])
                ->assertOk();

            Notification::assertSentTo($parentUser, AttendanceThresholdNotification::class);
            $this->assertDatabaseHas('attendance_alert_notification_logs', ['student_id' => $s->id]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_second_batch_same_day_does_not_resend_attendance_alert_email(): void
    {
        Carbon::setTestNow('2026-04-04 09:00:00');

        try {
            Notification::fake();

            $ctx = $this->setupClass();
            $s = $ctx['student'];

            $parentUser = User::factory()->create([
                'role' => UserRole::Parent,
                'email' => 'parent2@example.test',
            ]);
            $profile = ParentProfile::factory()->create(['user_id' => $parentUser->id]);
            $s->parents()->attach($profile->id, ['relation' => 'pere']);

            foreach (['2026-04-01', '2026-04-02', '2026-04-03'] as $day) {
                Attendance::factory()->create([
                    'student_id' => $s->id,
                    'classroom_id' => $ctx['classroom']->id,
                    'subject_id' => null,
                    'date' => $day,
                    'status' => Attendance::STATUS_ABSENT,
                    'justified' => false,
                ]);
            }

            $payload = [
                'classroom_id' => $ctx['classroom']->id,
                'date' => '2026-04-04',
                'records' => [
                    ['student_id' => $s->id, 'status' => 'absent'],
                ],
            ];

            $this->actingAs($this->admin(), 'sanctum')->postJson('/api/v1/attendances/batch', $payload)->assertOk();
            $this->actingAs($this->admin(), 'sanctum')->postJson('/api/v1/attendances/batch', $payload)->assertOk();

            Notification::assertSentTimes(AttendanceThresholdNotification::class, 1);
        } finally {
            Carbon::setTestNow();
        }
    }
}
