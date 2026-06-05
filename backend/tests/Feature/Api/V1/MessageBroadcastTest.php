<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Events\MessageRealtimeUpdated;
use App\Models\ClassRoom;
use App\Models\Level;
use App\Models\Message;
use App\Models\ParentProfile;
use App\Models\Student;
use App\Models\User;
use App\Support\AdminScopeContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class MessageBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserRole $role = UserRole::Admin): User
    {
        return User::factory()->create(['role' => $role]);
    }

    public function test_non_admin_cannot_broadcast(): void
    {
        $teacher = $this->user(UserRole::Enseignant);

        $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/messages/broadcast', [
                'subject' => 'Test',
                'body' => 'Hello',
                'audience_type' => 'all_users',
            ])
            ->assertForbidden();
    }

    public function test_admin_can_broadcast_to_all_users(): void
    {
        $admin = $this->user(UserRole::Admin);
        $u1 = $this->user(UserRole::Enseignant);
        $u2 = $this->user(UserRole::Parent);
        $u3 = $this->user(UserRole::Eleve);

        $res = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/messages/broadcast', [
                'subject' => 'Réunion plénière',
                'body' => 'Tous les utilisateurs sont concernés.',
                'audience_type' => 'all_users',
            ])
            ->assertCreated();

        $this->assertSame(3, $res->json('recipients_count'));

        // 3 messages créés, partageant le même broadcast_id
        $messages = Message::query()->where('is_announcement', true)->get();
        $this->assertCount(3, $messages);
        $broadcastId = $messages->first()->broadcast_id;
        $this->assertNotNull($broadcastId);
        $this->assertTrue($messages->every(fn ($m) => $m->broadcast_id === $broadcastId));

        // Chaque destinataire doit voir l'annonce dans son inbox
        foreach ([$u1, $u2, $u3] as $recipient) {
            $this->assertDatabaseHas('messages', [
                'recipient_id' => $recipient->id,
                'sender_id' => $admin->id,
                'is_announcement' => true,
                'subject' => 'Réunion plénière',
            ]);
        }
    }

    public function test_broadcast_dispatches_realtime_events(): void
    {
        Event::fake([MessageRealtimeUpdated::class]);

        $admin = $this->user(UserRole::Admin);
        $parent = $this->user(UserRole::Parent);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/messages/broadcast', [
                'subject' => 'Annonce',
                'body' => 'Bonjour.',
                'audience_type' => 'all_parents',
            ])
            ->assertCreated();

        Event::assertDispatched(
            MessageRealtimeUpdated::class,
            fn (MessageRealtimeUpdated $event) => $event->userId === $parent->id
                && $event->payload['type'] === 'announcement.created'
                && $event->payload['unread_count'] === 1,
        );
        Event::assertDispatched(
            MessageRealtimeUpdated::class,
            fn (MessageRealtimeUpdated $event) => $event->userId === $admin->id
                && $event->payload['section'] === 'announcements',
        );
    }

    public function test_broadcast_to_all_parents_filters_by_role(): void
    {
        $admin = $this->user(UserRole::Admin);
        $this->user(UserRole::Enseignant);
        $parent1 = $this->user(UserRole::Parent);
        $parent2 = $this->user(UserRole::Parent);

        $res = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/messages/broadcast', [
                'subject' => 'Aux parents',
                'body' => 'Bonjour les parents.',
                'audience_type' => 'all_parents',
            ])
            ->assertCreated();

        $this->assertSame(2, $res->json('recipients_count'));

        $this->assertDatabaseHas('messages', ['recipient_id' => $parent1->id, 'is_announcement' => true]);
        $this->assertDatabaseHas('messages', ['recipient_id' => $parent2->id, 'is_announcement' => true]);
    }

    public function test_broadcast_to_classroom_targets_students_parents_and_teachers(): void
    {
        $admin = $this->user(UserRole::Admin);
        $level = Level::factory()->create();
        $classroom = ClassRoom::factory()->create(['level_id' => $level->id, 'section' => 'A']);

        // Élève avec compte
        $studentUser = $this->user(UserRole::Eleve);
        $student = Student::factory()->create([
            'classroom_id' => $classroom->id,
            'user_id' => $studentUser->id,
        ]);

        // Parent rattaché
        $parentUser = $this->user(UserRole::Parent);
        $parentProfile = ParentProfile::factory()->create(['user_id' => $parentUser->id]);
        $student->parents()->attach($parentProfile->id, ['relation' => 'mere']);

        // Élève sans rapport (pas dans la classe ciblée) — ne doit pas recevoir
        $otherClassroom = ClassRoom::factory()->create(['level_id' => $level->id, 'section' => 'B']);
        $otherStudentUser = $this->user(UserRole::Eleve);
        Student::factory()->create([
            'classroom_id' => $otherClassroom->id,
            'user_id' => $otherStudentUser->id,
        ]);

        $res = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/messages/broadcast', [
                'subject' => 'Sortie scolaire',
                'body' => 'Sortie prévue mardi.',
                'audience_type' => 'classroom',
                'classroom_id' => $classroom->id,
            ])
            ->assertCreated();

        $this->assertSame(2, $res->json('recipients_count')); // élève + parent

        $this->assertDatabaseHas('messages', ['recipient_id' => $studentUser->id, 'is_announcement' => true]);
        $this->assertDatabaseHas('messages', ['recipient_id' => $parentUser->id, 'is_announcement' => true]);
        $this->assertDatabaseMissing('messages', ['recipient_id' => $otherStudentUser->id, 'is_announcement' => true]);
    }

    public function test_broadcast_classroom_requires_classroom_id(): void
    {
        $admin = $this->user(UserRole::Admin);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/messages/broadcast', [
                'subject' => 'Test',
                'body' => 'Body',
                'audience_type' => 'classroom',
            ])
            ->assertStatus(422);
    }

    public function test_broadcast_custom_targets_user_ids(): void
    {
        $admin = $this->user(UserRole::Admin);
        $u1 = $this->user(UserRole::Enseignant);
        $u2 = $this->user(UserRole::Parent);
        $other = $this->user(UserRole::Parent);

        $res = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/messages/broadcast', [
                'subject' => 'Personnalisé',
                'body' => 'Pour vous deux.',
                'audience_type' => 'custom',
                'user_ids' => [$u1->id, $u2->id],
            ])
            ->assertCreated();

        $this->assertSame(2, $res->json('recipients_count'));

        $this->assertDatabaseHas('messages', ['recipient_id' => $u1->id, 'is_announcement' => true]);
        $this->assertDatabaseHas('messages', ['recipient_id' => $u2->id, 'is_announcement' => true]);
        $this->assertDatabaseMissing('messages', ['recipient_id' => $other->id, 'is_announcement' => true]);
    }

    public function test_broadcast_audience_count_endpoint(): void
    {
        $admin = $this->user(UserRole::Admin);
        $this->user(UserRole::Parent);
        $this->user(UserRole::Parent);
        $this->user(UserRole::Enseignant);

        $res = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/messages/broadcast/audience-count?'.http_build_query([
                'audience_type' => 'all_parents',
            ]))
            ->assertOk();

        $this->assertSame(2, $res->json('count'));
    }

    public function test_global_admin_broadcast_reaches_scoped_admins(): void
    {
        $globalAdmin = User::factory()->create([
            'role' => UserRole::Admin,
            'admin_scope' => AdminScopeContext::GLOBAL,
        ]);
        $scopedAdmin = User::factory()->create([
            'role' => UserRole::Admin,
            'admin_scope' => AdminScopeContext::SECONDARY_TECHNICAL,
        ]);
        $this->user(UserRole::Parent);

        $this->actingAs($globalAdmin, 'sanctum')
            ->postJson('/api/v1/messages/broadcast', [
                'subject' => 'Info générale',
                'body' => 'Message pour l\'école.',
                'audience_type' => 'all_parents',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('messages', [
            'recipient_id' => $scopedAdmin->id,
            'sender_id' => $globalAdmin->id,
            'is_announcement' => true,
            'subject' => 'Info générale',
        ]);

        $res = $this->actingAs($scopedAdmin, 'sanctum')
            ->getJson('/api/v1/messages/inbox')
            ->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame('Info générale', $res->json('data.0.subject'));
        $this->assertTrue($res->json('data.0.is_announcement'));
    }

    public function test_announcement_appears_in_recipient_inbox_with_flag(): void
    {
        $admin = $this->user(UserRole::Admin);
        $parent = $this->user(UserRole::Parent);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/messages/broadcast', [
                'subject' => 'Annonce',
                'body' => 'Bonjour',
                'audience_type' => 'all_parents',
            ])
            ->assertCreated();

        $res = $this->actingAs($parent, 'sanctum')
            ->getJson('/api/v1/messages/inbox')
            ->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertTrue($res->json('data.0.is_announcement'));
        $this->assertNotNull($res->json('data.0.broadcast_id'));
    }

    public function test_unread_announcements_are_pinned_in_inbox(): void
    {
        $admin = $this->user(UserRole::Admin);
        $parent = $this->user(UserRole::Parent);

        Message::factory()->create([
            'sender_id' => $admin->id,
            'recipient_id' => $parent->id,
            'subject' => 'Message récent',
            'created_at' => now()->addMinute(),
            'is_announcement' => false,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/messages/broadcast', [
                'subject' => 'Annonce épinglée',
                'body' => 'Important',
                'audience_type' => 'all_parents',
            ])
            ->assertCreated();

        $res = $this->actingAs($parent, 'sanctum')
            ->getJson('/api/v1/messages/inbox')
            ->assertOk();

        $this->assertSame('Annonce épinglée', $res->json('data.0.subject'));
        $this->assertTrue($res->json('data.0.is_announcement'));
    }

    public function test_admin_contacts_include_broadcast_audiences(): void
    {
        $admin = $this->user(UserRole::Admin);

        $res = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/messages/contacts')
            ->assertOk();

        $audienceTypes = collect($res->json('audiences'))->pluck('type')->all();
        $this->assertContains('all_parents', $audienceTypes);
        $this->assertContains('classroom', $audienceTypes);
    }

    public function test_cycle_admin_contact_metadata_is_limited_to_scope(): void
    {
        $primaryLevel = Level::factory()->create(['cycle' => Level::CYCLE_PRIMAIRE]);
        $secondaryLevel = Level::factory()->create(['cycle' => Level::CYCLE_SECONDAIRE]);
        $primaryClass = ClassRoom::factory()->create(['level_id' => $primaryLevel->id]);
        $secondaryClass = ClassRoom::factory()->create(['level_id' => $secondaryLevel->id]);
        $primaryStudent = Student::factory()->create(['classroom_id' => $primaryClass->id]);
        $secondaryStudent = Student::factory()->create(['classroom_id' => $secondaryClass->id]);
        $parentUser = $this->user(UserRole::Parent);
        $parentProfile = ParentProfile::factory()->create(['user_id' => $parentUser->id]);
        $parentProfile->students()->attach($primaryStudent->id, ['relation' => 'Parent']);
        $parentProfile->students()->attach($secondaryStudent->id, ['relation' => 'Parent']);

        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'admin_scope' => AdminScopeContext::PRIMARY_MATERNAL,
        ]);

        $res = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/messages/contacts')
            ->assertOk();

        $contact = collect($res->json('data'))->firstWhere('id', $parentUser->id);

        $this->assertNotNull($contact);
        $this->assertSame([Level::CYCLE_PRIMAIRE], $contact['cycles']);
        $this->assertSame([$primaryClass->id], array_column($contact['classrooms'], 'id'));
    }

    public function test_legacy_audience_string_is_supported(): void
    {
        $admin = $this->user(UserRole::Admin);
        $parent = $this->user(UserRole::Parent);

        $res = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/messages/broadcast', [
                'subject' => 'Ancien format',
                'body' => 'Compatibilité',
                'audience' => 'all_parents',
            ])
            ->assertCreated();

        $this->assertSame(1, $res->json('recipients_count'));
        $this->assertDatabaseHas('messages', [
            'recipient_id' => $parent->id,
            'subject' => 'Ancien format',
            'is_announcement' => true,
        ]);
    }

    public function test_sent_announcements_are_returned_by_broadcast_not_by_recipient_rows(): void
    {
        $admin = $this->user(UserRole::Admin);
        User::factory()->count(35)->create(['role' => UserRole::Parent]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/messages/broadcast', [
                'subject' => 'Première annonce',
                'body' => 'Premier contenu.',
                'audience_type' => 'all_parents',
            ])
            ->assertCreated();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/messages/broadcast', [
                'subject' => 'Deuxième annonce',
                'body' => 'Deuxième contenu.',
                'audience_type' => 'all_parents',
            ])
            ->assertCreated();

        $res = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/messages/sent?announcements=1')
            ->assertOk();

        $subjects = collect($res->json('data'))->pluck('subject')->all();
        $this->assertCount(2, $res->json('data'));
        $this->assertContains('Première annonce', $subjects);
        $this->assertContains('Deuxième annonce', $subjects);
        $this->assertSame(35, $res->json('data.0.recipients_count'));
        $this->assertSame(35, $res->json('data.1.recipients_count'));
        $this->assertCount(35, $res->json('data.0.broadcast_recipients'));
        $this->assertSame('parent', $res->json('data.0.broadcast_recipients.0.role'));
        $this->assertArrayHasKey('is_read', $res->json('data.0.broadcast_recipients.0'));
    }

    public function test_admin_can_update_own_broadcast(): void
    {
        $admin = $this->user(UserRole::Admin);
        $parent1 = $this->user(UserRole::Parent);
        $parent2 = $this->user(UserRole::Parent);

        $res = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/messages/broadcast', [
                'subject' => 'Ancienne annonce',
                'body' => 'Ancien contenu.',
                'audience_type' => 'all_parents',
            ])
            ->assertCreated();

        $broadcastId = $res->json('broadcast_id');

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/messages/broadcast/{$broadcastId}", [
                'subject' => 'Annonce corrigée',
                'body' => 'Contenu corrigé.',
            ])
            ->assertOk()
            ->assertJsonPath('updated_count', 2);

        foreach ([$parent1, $parent2] as $parent) {
            $this->assertDatabaseHas('messages', [
                'broadcast_id' => $broadcastId,
                'recipient_id' => $parent->id,
                'subject' => 'Annonce corrigée',
                'body' => 'Contenu corrigé.',
            ]);
        }
    }

    public function test_admin_cannot_update_another_sender_broadcast(): void
    {
        $admin = $this->user(UserRole::Admin);
        $otherAdmin = $this->user(UserRole::Admin);
        $parent = $this->user(UserRole::Parent);

        $res = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/messages/broadcast', [
                'subject' => 'Annonce',
                'body' => 'Contenu.',
                'audience_type' => 'all_parents',
            ])
            ->assertCreated();

        $this->actingAs($otherAdmin, 'sanctum')
            ->patchJson("/api/v1/messages/broadcast/{$res->json('broadcast_id')}", [
                'subject' => 'Tentative',
                'body' => 'Non autorisé.',
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('messages', [
            'recipient_id' => $parent->id,
            'subject' => 'Annonce',
            'body' => 'Contenu.',
        ]);
    }
}
