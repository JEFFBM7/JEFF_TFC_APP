<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\ClassRoom;
use App\Models\Message;
use App\Models\Student;
use App\Models\User;
use App\Support\AdminScopeContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Diffusion d'annonces (broadcast) à un groupe d'utilisateurs.
 *
 * Les annonces sont stockées comme des messages individuels (1 par destinataire)
 * partageant un même `broadcast_id` (uuid) et marqués `is_announcement = true`.
 * Cela permet :
 *   - La lecture/suppression individuelle (chaque destinataire gère "son" message).
 *   - Le badge non-lu fonctionne sans logique spécifique.
 *   - L'agrégation par broadcast_id côté envoyeur (vue "annonces envoyées").
 */
class BroadcastMessageService
{
    public const MAX_RECIPIENTS = 5000;

    public const AUDIENCE_ALL_USERS = 'all_users';

    public const AUDIENCE_ALL_PARENTS = 'all_parents';

    public const AUDIENCE_ALL_TEACHERS = 'all_teachers';

    public const AUDIENCE_ALL_STUDENTS = 'all_students';

    public const AUDIENCE_CLASSROOM = 'classroom';

    public const AUDIENCE_CYCLE = 'cycle';

    public const AUDIENCE_CUSTOM = 'custom';

    public const AUDIENCES = [
        self::AUDIENCE_ALL_USERS,
        self::AUDIENCE_ALL_PARENTS,
        self::AUDIENCE_ALL_TEACHERS,
        self::AUDIENCE_ALL_STUDENTS,
        self::AUDIENCE_CLASSROOM,
        self::AUDIENCE_CYCLE,
        self::AUDIENCE_CUSTOM,
    ];

    /**
     * @param array{
     *   audience_type: string,
     *   classroom_id?: int|null,
     *   cycle?: string|null,
     *   user_ids?: array<int, int>
     * } $audience
     *
     * @return array{broadcast_id: string, recipients_count: int, recipient_ids: array<int, int>}
     */
    public function send(User $sender, string $subject, string $body, array $audience): array
    {
        $recipients = $this->resolveRecipients($audience, $sender)
            ->filter(fn (User $u) => $u->id !== $sender->id)
            ->unique('id')
            ->values();

        if ($recipients->count() > self::MAX_RECIPIENTS) {
            abort(422, sprintf(
                'Audience trop large : %d destinataires. Limite autorisée : %d.',
                $recipients->count(),
                self::MAX_RECIPIENTS,
            ));
        }

        if ($recipients->isEmpty()) {
            return ['broadcast_id' => '', 'recipients_count' => 0, 'recipient_ids' => []];
        }

        $broadcastId = (string) Str::uuid();
        $now = now();

        $rows = $recipients->map(fn (User $u) => [
            'sender_id' => $sender->id,
            'recipient_id' => $u->id,
            'parent_message_id' => null,
            'subject' => $subject,
            'body' => $body,
            'is_announcement' => true,
            'broadcast_id' => $broadcastId,
            'read_at' => null,
            'deleted_by_sender' => false,
            'deleted_by_recipient' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        DB::transaction(function () use ($rows): void {
            foreach (array_chunk($rows, 200) as $chunk) {
                Message::query()->insert($chunk);
            }
        });

        return [
            'broadcast_id' => $broadcastId,
            'recipients_count' => count($rows),
            'recipient_ids' => $recipients->pluck('id')->map(fn ($id) => (int) $id)->all(),
        ];
    }

    /**
     * @param array{
     *   audience_type: string,
     *   classroom_id?: int|null,
     *   cycle?: string|null,
     *   user_ids?: array<int, int>
     * } $audience
     *
     * @return Collection<int, User>
     */
    public function resolveRecipients(array $audience, ?User $sender = null): Collection
    {
        $type = $audience['audience_type'];

        $recipients = match ($type) {
            self::AUDIENCE_ALL_USERS => User::query()->select(['id', 'name', 'email', 'role'])->get(),
            self::AUDIENCE_ALL_PARENTS => User::query()->where('role', UserRole::Parent)->get(),
            self::AUDIENCE_ALL_TEACHERS => User::query()->where('role', UserRole::Enseignant)->get(),
            self::AUDIENCE_ALL_STUDENTS => User::query()->where('role', UserRole::Eleve)->get(),
            self::AUDIENCE_CLASSROOM => $this->classroomAudience((int) ($audience['classroom_id'] ?? 0)),
            self::AUDIENCE_CYCLE => $this->cycleAudience((string) ($audience['cycle'] ?? '')),
            self::AUDIENCE_CUSTOM => User::query()
                ->whereIn('id', array_values($audience['user_ids'] ?? []))
                ->get(),
            default => new Collection,
        };

        if ($sender?->role === UserRole::Admin && ! AdminScopeContext::isGlobalAdmin($sender)) {
            $allowedIds = $this->scopedUserIds($sender);

            $recipients = new Collection(
                $recipients
                    ->filter(fn (User $user) => $allowedIds->contains((int) $user->id))
                    ->values()
                    ->all(),
            );
        }

        if ($sender !== null && AdminScopeContext::isGlobalAdmin($sender)) {
            return $this->appendScopedAdminRecipients($recipients);
        }

        return $recipients;
    }

    /**
     * Les administrateurs de cycle doivent recevoir les annonces de l'admin général,
     * même lorsque la diffusion cible parents, enseignants ou un cycle précis.
     *
     * @param  Collection<int, User>  $recipients
     * @return Collection<int, User>
     */
    private function appendScopedAdminRecipients(Collection $recipients): Collection
    {
        $scopedAdmins = User::query()
            ->where('role', UserRole::Admin)
            ->whereIn('admin_scope', [
                AdminScopeContext::PRIMARY_MATERNAL,
                AdminScopeContext::SECONDARY_TECHNICAL,
            ])
            ->get();

        if ($scopedAdmins->isEmpty()) {
            return $recipients;
        }

        return new Collection(
            $recipients
                ->concat($scopedAdmins)
                ->unique('id')
                ->values()
                ->all(),
        );
    }

    /** @return \Illuminate\Support\Collection<int, int> */
    private function scopedUserIds(User $sender): \Illuminate\Support\Collection
    {
        $classroomIds = AdminScopeContext::allowedClassroomIds($sender);
        $studentIds = Student::query()
            ->whereIn('classroom_id', $classroomIds)
            ->pluck('id');

        $userIds = collect();
        $userIds = $userIds->merge(
            Student::query()->whereIn('classroom_id', $classroomIds)->whereNotNull('user_id')->pluck('user_id'),
        );
        $userIds = $userIds->merge(
            Student::query()
                ->whereIn('id', $studentIds)
                ->with('parents.user:id')
                ->get()
                ->flatMap(fn ($student) => $student->parents->map(fn ($parent) => $parent->user?->id))
                ->filter(),
        );
        $userIds = $userIds->merge(
            ClassRoom::query()
                ->whereIn('id', $classroomIds)
                ->with('teacherAssignments.teacher.user:id')
                ->get()
                ->flatMap(fn (ClassRoom $classroom) => $classroom->teacherAssignments->map(fn ($assignment) => $assignment->teacher?->user?->id))
                ->filter(),
        );

        return $userIds->map(fn ($id) => (int) $id)->unique()->values();
    }

    /**
     * Pour une classe : élèves + parents des élèves + enseignants assignés.
     *
     * @return Collection<int, User>
     */
    private function classroomAudience(int $classroomId): Collection
    {
        if ($classroomId <= 0) {
            return new Collection;
        }

        $userIds = collect();

        $studentUserIds = Student::query()
            ->where('classroom_id', $classroomId)
            ->whereNotNull('user_id')
            ->pluck('user_id');
        $userIds = $userIds->merge($studentUserIds);

        // Parents des élèves de la classe
        $parentUserIds = Student::query()
            ->where('classroom_id', $classroomId)
            ->with('parents.user:id')
            ->get()
            ->flatMap(fn ($s) => $s->parents->map(fn ($p) => $p->user?->id))
            ->filter();
        $userIds = $userIds->merge($parentUserIds);

        // Enseignants affectés à la classe (toute matière)
        $classroom = ClassRoom::query()->with('teacherAssignments.teacher.user:id')->find($classroomId);
        if ($classroom !== null) {
            $teacherUserIds = $classroom->teacherAssignments
                ->map(fn ($a) => $a->teacher?->user?->id)
                ->filter();
            $userIds = $userIds->merge($teacherUserIds);
        }

        $ids = $userIds->unique()->values()->all();
        if (empty($ids)) {
            return new Collection;
        }

        /** @var Collection<int, User> $users */
        $users = User::query()->whereIn('id', $ids)->get();

        return $users;
    }

    /**
     * Pour un cycle (maternel, primaire, cteb, secondaire) : tous les élèves +
     * parents des élèves + enseignants des classes du cycle.
     *
     * @return Collection<int, User>
     */
    private function cycleAudience(string $cycle): Collection
    {
        if ($cycle === '') {
            return new Collection;
        }

        $classroomIds = ClassRoom::query()
            ->whereHas('level', fn ($q) => $q->where('cycle', $cycle))
            ->pluck('id')
            ->all();

        if (empty($classroomIds)) {
            return new Collection;
        }

        $userIds = collect();

        $studentUserIds = Student::query()
            ->whereIn('classroom_id', $classroomIds)
            ->whereNotNull('user_id')
            ->pluck('user_id');
        $userIds = $userIds->merge($studentUserIds);

        $parentUserIds = Student::query()
            ->whereIn('classroom_id', $classroomIds)
            ->with('parents.user:id')
            ->get()
            ->flatMap(fn ($s) => $s->parents->map(fn ($p) => $p->user?->id))
            ->filter();
        $userIds = $userIds->merge($parentUserIds);

        $teacherUserIds = ClassRoom::query()
            ->whereIn('id', $classroomIds)
            ->with('teacherAssignments.teacher.user:id')
            ->get()
            ->flatMap(fn (ClassRoom $c) => $c->teacherAssignments->map(fn ($a) => $a->teacher?->user?->id))
            ->filter();
        $userIds = $userIds->merge($teacherUserIds);

        $ids = $userIds->unique()->values()->all();
        if (empty($ids)) {
            return new Collection;
        }

        /** @var Collection<int, User> $users */
        $users = User::query()->whereIn('id', $ids)->get();

        return $users;
    }
}
