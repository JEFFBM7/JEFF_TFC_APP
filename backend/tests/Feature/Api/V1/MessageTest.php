<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Events\MessageRealtimeUpdated;
use App\Models\ClassRoom;
use App\Models\Level;
use App\Models\Message;
use App\Models\ParentProfile;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class MessageTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserRole $role = UserRole::Admin): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function currentSchoolYear(): SchoolYear
    {
        return SchoolYear::query()->current()->first()
            ?? SchoolYear::factory()->current()->create();
    }

    private function classroom(string $cycle = Level::CYCLE_PRIMAIRE): ClassRoom
    {
        $level = Level::factory()->create(['cycle' => $cycle]);

        return ClassRoom::factory()->create(['level_id' => $level->id]);
    }

    private function assignTeacherToClassroom(Teacher $teacher, ClassRoom $classroom, ?SchoolYear $schoolYear = null): void
    {
        TeacherAssignment::query()->create([
            'teacher_id' => $teacher->id,
            'classroom_id' => $classroom->id,
            'subject_id' => Subject::factory()->create()->id,
            'school_year_id' => ($schoolYear ?? $this->currentSchoolYear())->id,
        ]);
    }

    private function attachParentToStudent(User $parentUser, Student $student): ParentProfile
    {
        $profile = ParentProfile::factory()->create(['user_id' => $parentUser->id]);
        $profile->students()->attach($student->id, ['relation' => 'Père']);

        return $profile;
    }

    // ─── Boîte de réception ──────────────────────────────────────────────────

    public function test_unauthenticated_cannot_access_inbox(): void
    {
        $this->getJson('/api/v1/messages/inbox')->assertUnauthorized();
    }

    public function test_inbox_returns_only_own_messages(): void
    {
        $alice = $this->user(UserRole::Enseignant);
        $bob = $this->user(UserRole::Parent);
        $charles = $this->user(UserRole::Admin);

        Message::factory()->create(['sender_id' => $bob->id, 'recipient_id' => $alice->id, 'subject' => 'Pour Alice']);
        Message::factory()->create(['sender_id' => $bob->id, 'recipient_id' => $charles->id, 'subject' => 'Pour Charles']);

        $res = $this->actingAs($alice, 'sanctum')
            ->getJson('/api/v1/messages/inbox')
            ->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame('Pour Alice', $res->json('data.0.subject'));
    }

    public function test_sent_returns_only_own_sent_messages(): void
    {
        $alice = $this->user(UserRole::Enseignant);
        $bob = $this->user(UserRole::Parent);
        $charles = $this->user(UserRole::Admin);

        Message::factory()->create(['sender_id' => $alice->id, 'recipient_id' => $bob->id, 'subject' => 'Alice envoie']);
        Message::factory()->create(['sender_id' => $charles->id, 'recipient_id' => $bob->id, 'subject' => 'Charles envoie']);

        $res = $this->actingAs($alice, 'sanctum')
            ->getJson('/api/v1/messages/sent')
            ->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame('Alice envoie', $res->json('data.0.subject'));
    }

    public function test_inbox_includes_own_thread_when_a_reply_is_received(): void
    {
        $parent = $this->user(UserRole::Parent);
        $teacher = $this->user(UserRole::Enseignant);

        $thread = Message::factory()->create([
            'sender_id' => $parent->id,
            'recipient_id' => $teacher->id,
            'subject' => 'Question parent',
            'read_at' => now(),
        ]);

        Message::factory()->create([
            'sender_id' => $teacher->id,
            'recipient_id' => $parent->id,
            'parent_message_id' => $thread->id,
            'subject' => 'Re: Question parent',
            'body' => 'Voici la réponse.',
            'read_at' => null,
        ]);

        $res = $this->actingAs($parent, 'sanctum')
            ->getJson('/api/v1/messages/inbox')
            ->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame($thread->id, $res->json('data.0.id'));
        $this->assertSame('Voici la réponse.', $res->json('data.0.replies.0.body'));
        $this->assertFalse($res->json('data.0.replies.0.is_read'));
    }

    // ─── Envoi ────────────────────────────────────────────────────────────────

    public function test_user_can_send_message(): void
    {
        $alice = $this->user(UserRole::Enseignant);
        $bob = $this->user(UserRole::Parent);
        $teacher = Teacher::factory()->create(['user_id' => $alice->id]);
        $schoolYear = $this->currentSchoolYear();
        $classroom = $this->classroom();
        $student = Student::factory()->create(['classroom_id' => $classroom->id]);

        $this->attachParentToStudent($bob, $student);
        $this->assignTeacherToClassroom($teacher, $classroom, $schoolYear);

        $this->actingAs($alice, 'sanctum')
            ->postJson('/api/v1/messages', [
                'recipient_id' => $bob->id,
                'subject' => 'Réunion parents',
                'body' => 'Bonjour, je vous contacte au sujet de votre enfant.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.subject', 'Réunion parents')
            ->assertJsonPath('data.sender_id', $alice->id)
            ->assertJsonPath('data.recipient_id', $bob->id);

        $this->assertDatabaseHas('messages', [
            'sender_id' => $alice->id,
            'recipient_id' => $bob->id,
            'subject' => 'Réunion parents',
        ]);
    }

    public function test_sending_message_dispatches_realtime_event_to_sender_and_recipient(): void
    {
        Event::fake([MessageRealtimeUpdated::class]);

        $alice = $this->user(UserRole::Enseignant);
        $bob = $this->user(UserRole::Parent);
        $teacher = Teacher::factory()->create(['user_id' => $alice->id]);
        $schoolYear = $this->currentSchoolYear();
        $classroom = $this->classroom();
        $student = Student::factory()->create(['classroom_id' => $classroom->id]);

        $this->attachParentToStudent($bob, $student);
        $this->assignTeacherToClassroom($teacher, $classroom, $schoolYear);

        $this->actingAs($alice, 'sanctum')
            ->postJson('/api/v1/messages', [
                'recipient_id' => $bob->id,
                'subject' => 'Réunion parents',
                'body' => 'Bonjour.',
            ])
            ->assertCreated();

        Event::assertDispatched(
            MessageRealtimeUpdated::class,
            fn (MessageRealtimeUpdated $event) => $event->userId === $alice->id
                && $event->payload['type'] === 'message.created',
        );
        Event::assertDispatched(
            MessageRealtimeUpdated::class,
            fn (MessageRealtimeUpdated $event) => $event->userId === $bob->id
                && $event->payload['unread_count'] === 1,
        );
    }

    public function test_cannot_send_message_to_self(): void
    {
        $alice = $this->user();

        $this->actingAs($alice, 'sanctum')
            ->postJson('/api/v1/messages', [
                'recipient_id' => $alice->id,
                'subject' => 'Test',
                'body' => 'Autoenvoi.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['recipient_id']);
    }

    public function test_can_reply_to_message(): void
    {
        $alice = $this->user(UserRole::Enseignant);
        $bob = $this->user(UserRole::Parent);
        $teacher = Teacher::factory()->create(['user_id' => $alice->id]);
        $schoolYear = $this->currentSchoolYear();
        $classroom = $this->classroom();
        $student = Student::factory()->create(['classroom_id' => $classroom->id]);

        $this->attachParentToStudent($bob, $student);
        $this->assignTeacherToClassroom($teacher, $classroom, $schoolYear);

        $parent = Message::factory()->create([
            'sender_id' => $bob->id,
            'recipient_id' => $alice->id,
            'subject' => 'Question',
        ]);

        $this->actingAs($alice, 'sanctum')
            ->postJson('/api/v1/messages', [
                'recipient_id' => $bob->id,
                'subject' => 'Re: Question',
                'body' => 'Bien noté.',
                'parent_message_id' => $parent->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.parent_message_id', $parent->id);
    }

    // ─── Lecture ──────────────────────────────────────────────────────────────

    public function test_show_marks_message_as_read(): void
    {
        $alice = $this->user(UserRole::Enseignant);
        $bob = $this->user(UserRole::Parent);

        $msg = Message::factory()->create([
            'sender_id' => $bob->id,
            'recipient_id' => $alice->id,
        ]);

        $this->assertNull($msg->read_at);

        $this->actingAs($alice, 'sanctum')
            ->getJson("/api/v1/messages/{$msg->id}")
            ->assertOk()
            ->assertJsonPath('data.is_read', true);

        $this->assertNotNull($msg->fresh()->read_at);
    }

    public function test_show_marks_thread_replies_as_read(): void
    {
        $alice = $this->user(UserRole::Enseignant);
        $bob = $this->user(UserRole::Parent);

        $thread = Message::factory()->create([
            'sender_id' => $alice->id,
            'recipient_id' => $bob->id,
            'read_at' => now(),
        ]);

        $reply = Message::factory()->create([
            'sender_id' => $alice->id,
            'recipient_id' => $bob->id,
            'parent_message_id' => $thread->id,
            'read_at' => null,
        ]);

        $this->actingAs($bob, 'sanctum')
            ->getJson("/api/v1/messages/{$thread->id}")
            ->assertOk()
            ->assertJsonPath('data.replies.0.is_read', true);

        $this->assertNotNull($reply->fresh()->read_at);
    }

    public function test_sender_cannot_see_message_of_others(): void
    {
        $alice = $this->user();
        $bob = $this->user();
        $eve = $this->user();

        $msg = Message::factory()->create([
            'sender_id' => $bob->id,
            'recipient_id' => $alice->id,
        ]);

        $this->actingAs($eve, 'sanctum')
            ->getJson("/api/v1/messages/{$msg->id}")
            ->assertForbidden();
    }

    // ─── Suppression logique ──────────────────────────────────────────────────

    public function test_recipient_can_soft_delete_message(): void
    {
        $alice = $this->user();
        $bob = $this->user();

        $msg = Message::factory()->create([
            'sender_id' => $bob->id,
            'recipient_id' => $alice->id,
        ]);

        $this->actingAs($alice, 'sanctum')
            ->deleteJson("/api/v1/messages/{$msg->id}")
            ->assertNoContent();

        $this->assertDatabaseHas('messages', [
            'id' => $msg->id,
            'deleted_by_recipient' => true,
        ]);

        // Le message n'apparaît plus dans la boîte de réception d'Alice
        $res = $this->actingAs($alice, 'sanctum')
            ->getJson('/api/v1/messages/inbox')
            ->assertOk();

        $this->assertCount(0, $res->json('data'));
    }

    public function test_sender_deleting_thread_root_hides_conversation_when_replies_exist(): void
    {
        $admin = $this->user();
        $parent = $this->user(UserRole::Parent);

        $root = Message::factory()->create([
            'sender_id' => $admin->id,
            'recipient_id' => $parent->id,
            'subject' => 'Suivi de Franci',
        ]);

        Message::factory()->create([
            'sender_id' => $parent->id,
            'recipient_id' => $admin->id,
            'parent_message_id' => $root->id,
            'subject' => 'Re: Suivi de Franci',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/messages/{$root->id}")
            ->assertNoContent();

        $inbox = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/messages/inbox')
            ->assertOk()
            ->json('data');

        $sent = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/messages/sent')
            ->assertOk()
            ->json('data');

        $this->assertCount(0, $inbox);
        $this->assertCount(0, $sent);

        $this->assertTrue($root->fresh()->deleted_by_sender);
    }

    // ─── Compteur non-lus ─────────────────────────────────────────────────────

    public function test_unread_count_reflects_unread_messages(): void
    {
        $alice = $this->user();
        $bob = $this->user();

        Message::factory()->count(3)->create(['sender_id' => $bob->id, 'recipient_id' => $alice->id]);
        Message::factory()->create(['sender_id' => $bob->id, 'recipient_id' => $alice->id, 'read_at' => now()]);

        $this->actingAs($alice, 'sanctum')
            ->getJson('/api/v1/messages/unread-count')
            ->assertOk()
            ->assertJsonPath('unread', 3);
    }

    // ─── Contacts ─────────────────────────────────────────────────────────────

    public function test_admin_contacts_returns_all_users_except_self(): void
    {
        $alice = $this->user();
        $this->user();
        $this->user();

        $res = $this->actingAs($alice, 'sanctum')
            ->getJson('/api/v1/messages/contacts')
            ->assertOk();

        $ids = collect($res->json('data'))->pluck('id');
        $this->assertNotContains($alice->id, $ids->toArray());
        $this->assertCount(2, $ids);
    }

    public function test_student_contacts_are_limited_to_admins_teachers_and_responsibles(): void
    {
        $studentUser = $this->user(UserRole::Eleve);
        $parentUser = $this->user(UserRole::Parent);
        $admin = $this->user(UserRole::Admin);
        $allowedTeacher = Teacher::factory()->create();
        $otherTeacher = Teacher::factory()->create();
        $otherParent = $this->user(UserRole::Parent);

        $schoolYear = $this->currentSchoolYear();
        $classroom = $this->classroom();
        $otherClassroom = $this->classroom();
        $student = Student::factory()->create([
            'user_id' => $studentUser->id,
            'classroom_id' => $classroom->id,
        ]);
        $otherStudent = Student::factory()->create(['classroom_id' => $otherClassroom->id]);

        $this->attachParentToStudent($parentUser, $student);
        $this->attachParentToStudent($otherParent, $otherStudent);
        $this->assignTeacherToClassroom($allowedTeacher, $classroom, $schoolYear);
        $this->assignTeacherToClassroom($otherTeacher, $otherClassroom, $schoolYear);

        $res = $this->actingAs($studentUser, 'sanctum')
            ->getJson('/api/v1/messages/contacts')
            ->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->all();

        $this->assertContains($admin->id, $ids);
        $this->assertContains($allowedTeacher->user_id, $ids);
        $this->assertContains($parentUser->id, $ids);
        $this->assertNotContains($otherTeacher->user_id, $ids);
        $this->assertNotContains($otherParent->id, $ids);
        $this->assertNotContains($studentUser->id, $ids);

        $teacherContact = collect($res->json('data'))->firstWhere('id', $allowedTeacher->user_id);
        $parentContact = collect($res->json('data'))->firstWhere('id', $parentUser->id);
        $this->assertSame([Level::CYCLE_PRIMAIRE], $teacherContact['cycles']);
        $this->assertSame([Level::CYCLE_PRIMAIRE], $parentContact['cycles']);
        $this->assertSame($classroom->id, $teacherContact['classrooms'][0]['id']);
        $this->assertSame($classroom->id, $parentContact['classrooms'][0]['id']);
    }

    public function test_teacher_contacts_are_limited_to_students_parents_admins_and_same_cycle_teachers(): void
    {
        $teacherUser = $this->user(UserRole::Enseignant);
        $teacher = Teacher::factory()->create(['user_id' => $teacherUser->id]);
        $admin = $this->user(UserRole::Admin);
        $studentUser = $this->user(UserRole::Eleve);
        $parentUser = $this->user(UserRole::Parent);
        $sameCycleTeacher = Teacher::factory()->create();
        $otherCycleTeacher = Teacher::factory()->create();
        $otherStudentUser = $this->user(UserRole::Eleve);

        $schoolYear = $this->currentSchoolYear();
        $classroom = $this->classroom(Level::CYCLE_PRIMAIRE);
        $sameCycleClassroom = $this->classroom(Level::CYCLE_PRIMAIRE);
        $otherCycleClassroom = $this->classroom(Level::CYCLE_SECONDAIRE);
        $student = Student::factory()->create([
            'user_id' => $studentUser->id,
            'classroom_id' => $classroom->id,
        ]);
        Student::factory()->create([
            'user_id' => $otherStudentUser->id,
            'classroom_id' => $sameCycleClassroom->id,
        ]);

        $this->attachParentToStudent($parentUser, $student);
        $this->assignTeacherToClassroom($teacher, $classroom, $schoolYear);
        $this->assignTeacherToClassroom($sameCycleTeacher, $sameCycleClassroom, $schoolYear);
        $this->assignTeacherToClassroom($otherCycleTeacher, $otherCycleClassroom, $schoolYear);

        $res = $this->actingAs($teacherUser, 'sanctum')
            ->getJson('/api/v1/messages/contacts')
            ->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->all();

        $this->assertContains($admin->id, $ids);
        $this->assertContains($studentUser->id, $ids);
        $this->assertContains($parentUser->id, $ids);
        $this->assertContains($sameCycleTeacher->user_id, $ids);
        $this->assertNotContains($otherCycleTeacher->user_id, $ids);
        $this->assertNotContains($otherStudentUser->id, $ids);
        $this->assertNotContains($teacherUser->id, $ids);

        $studentContact = collect($res->json('data'))->firstWhere('id', $studentUser->id);
        $sameCycleTeacherContact = collect($res->json('data'))->firstWhere('id', $sameCycleTeacher->user_id);
        $this->assertSame([Level::CYCLE_PRIMAIRE], $studentContact['cycles']);
        $this->assertSame([Level::CYCLE_PRIMAIRE], $sameCycleTeacherContact['cycles']);
        $this->assertSame($classroom->id, $studentContact['classrooms'][0]['id']);
        $this->assertSame($sameCycleClassroom->id, $sameCycleTeacherContact['classrooms'][0]['id']);
    }

    public function test_parent_contacts_are_limited_to_admins_children_and_children_teachers(): void
    {
        $parentUser = $this->user(UserRole::Parent);
        $parent = ParentProfile::factory()->create(['user_id' => $parentUser->id]);
        $admin = $this->user(UserRole::Admin);
        $childUser = $this->user(UserRole::Eleve);
        $allowedTeacher = Teacher::factory()->create();
        $otherTeacher = Teacher::factory()->create();
        $otherParent = $this->user(UserRole::Parent);

        $schoolYear = SchoolYear::factory()->current()->create();
        $classroom = ClassRoom::factory()->create();
        $student = Student::factory()->create([
            'user_id' => $childUser->id,
            'classroom_id' => $classroom->id,
        ]);
        $parent->students()->attach($student->id, ['relation' => 'Père']);

        TeacherAssignment::query()->create([
            'teacher_id' => $allowedTeacher->id,
            'classroom_id' => $classroom->id,
            'subject_id' => Subject::factory()->create()->id,
            'school_year_id' => $schoolYear->id,
        ]);

        $res = $this->actingAs($parentUser, 'sanctum')
            ->getJson('/api/v1/messages/contacts')
            ->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->all();

        $this->assertContains($admin->id, $ids);
        $this->assertContains($childUser->id, $ids);
        $this->assertContains($allowedTeacher->user_id, $ids);
        $this->assertNotContains($otherTeacher->user_id, $ids);
        $this->assertNotContains($otherParent->id, $ids);
        $this->assertNotContains($parentUser->id, $ids);

        $childContact = collect($res->json('data'))->firstWhere('id', $childUser->id);
        $teacherContact = collect($res->json('data'))->firstWhere('id', $allowedTeacher->user_id);
        $this->assertSame([Level::CYCLE_PRIMAIRE], $childContact['cycles']);
        $this->assertSame([Level::CYCLE_PRIMAIRE], $teacherContact['cycles']);
        $this->assertSame($classroom->id, $childContact['classrooms'][0]['id']);
        $this->assertSame($classroom->id, $teacherContact['classrooms'][0]['id']);
    }

    public function test_teacher_cannot_send_to_student_outside_assigned_classes(): void
    {
        $teacherUser = $this->user(UserRole::Enseignant);
        $teacher = Teacher::factory()->create(['user_id' => $teacherUser->id]);
        $outsideStudentUser = $this->user(UserRole::Eleve);
        $schoolYear = $this->currentSchoolYear();

        $this->assignTeacherToClassroom($teacher, $this->classroom(), $schoolYear);
        Student::factory()->create([
            'user_id' => $outsideStudentUser->id,
            'classroom_id' => $this->classroom()->id,
        ]);

        $this->actingAs($teacherUser, 'sanctum')
            ->postJson('/api/v1/messages', [
                'recipient_id' => $outsideStudentUser->id,
                'subject' => 'Question',
                'body' => 'Bonjour.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['recipient_id']);
    }

    public function test_parent_cannot_send_to_unrelated_user(): void
    {
        $parentUser = $this->user(UserRole::Parent);
        ParentProfile::factory()->create(['user_id' => $parentUser->id]);
        $otherTeacher = Teacher::factory()->create();

        $this->actingAs($parentUser, 'sanctum')
            ->postJson('/api/v1/messages', [
                'recipient_id' => $otherTeacher->user_id,
                'subject' => 'Question',
                'body' => 'Bonjour.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['recipient_id']);
    }
}
