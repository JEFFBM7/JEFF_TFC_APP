<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Events\MessageRealtimeUpdated;
use App\Jobs\SendWebPush;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MessageRequest;
use App\Http\Resources\Api\V1\MessageResource;
use App\Models\Message;
use App\Models\ParentProfile;
use App\Models\Student;
use App\Models\TeacherAssignment;
use App\Models\User;
use App\Services\BroadcastMessageService;
use App\Services\MessageRecipientService;
use App\Support\AdminScopeContext;
use App\Support\SchoolYearContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MessageController extends Controller
{
    public function __construct(
        private readonly BroadcastMessageService $broadcaster,
        private readonly MessageRecipientService $recipientService,
    ) {}

    /** Boîte de réception de l'utilisateur connecté. */
    public function inbox(Request $request): AnonymousResourceCollection
    {
        $userId = $request->user()->id;

        $messages = Message::query()
            ->whereNull('parent_message_id')
            ->where(function ($query) use ($userId): void {
                $query
                    ->where(function ($query) use ($userId): void {
                        $query
                            ->where('recipient_id', $userId)
                            ->where('deleted_by_recipient', false);
                    })
                    ->orWhereHas('replies', function ($query) use ($userId): void {
                        $query->visibleToUser($userId);
                    });
            })
            ->where(function ($query) use ($userId): void {
                $query
                    ->where('sender_id', '!=', $userId)
                    ->orWhere('deleted_by_sender', false);
            })
            ->where(function ($query) use ($userId): void {
                $query
                    ->where('recipient_id', '!=', $userId)
                    ->orWhere('deleted_by_recipient', false);
            })
            ->with(['sender', 'recipient', 'replies.sender', 'replies.recipient'])
            ->withCount('replies')
            ->orderByDesc('is_announcement')
            ->orderByRaw('read_at IS NULL DESC')
            ->orderByDesc('created_at')
            ->paginate(30);

        return MessageResource::collection($messages);
    }

    /** Messages envoyés. */
    public function sent(Request $request): AnonymousResourceCollection
    {
        if ($request->boolean('announcements')) {
            return $this->sentAnnouncements($request);
        }

        $userId = $request->user()->id;

        $messages = Message::query()
            ->where('sender_id', $userId)
            ->where('deleted_by_sender', false)
            ->whereNull('parent_message_id')
            ->with(['sender', 'recipient', 'replies.sender', 'replies.recipient'])
            ->withCount('replies')
            ->orderByDesc('created_at')
            ->paginate(30);

        return MessageResource::collection($messages);
    }

    /** Diffusions envoyées, agrégées par annonce. */
    private function sentAnnouncements(Request $request): AnonymousResourceCollection
    {
        $userId = $request->user()->id;

        $representativeIds = Message::query()
            ->selectRaw('MAX(id)')
            ->where('sender_id', $userId)
            ->where('deleted_by_sender', false)
            ->whereNull('parent_message_id')
            ->where('is_announcement', true)
            ->whereNotNull('broadcast_id')
            ->groupBy('broadcast_id');

        $messages = Message::query()
            ->whereIn('id', $representativeIds)
            ->select('messages.*')
            ->selectSub(function ($query) use ($userId): void {
                $query
                    ->from('messages as broadcast_messages')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('broadcast_messages.broadcast_id', 'messages.broadcast_id')
                    ->where('broadcast_messages.sender_id', $userId)
                    ->where('broadcast_messages.deleted_by_sender', false)
                    ->where('broadcast_messages.is_announcement', true);
            }, 'recipients_count')
            ->with(['sender', 'recipient'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(30);

        $broadcastIds = $messages
            ->getCollection()
            ->pluck('broadcast_id')
            ->filter()
            ->values();

        $recipientsByBroadcast = Message::query()
            ->whereIn('broadcast_id', $broadcastIds)
            ->where('sender_id', $userId)
            ->where('deleted_by_sender', false)
            ->where('is_announcement', true)
            ->with('recipient:id,name,email,role')
            ->orderBy('recipient_id')
            ->get()
            ->groupBy('broadcast_id');

        $messages->getCollection()->each(function (Message $message) use ($recipientsByBroadcast): void {
            $recipients = $recipientsByBroadcast
                ->get($message->broadcast_id, collect())
                ->map(fn (Message $row) => [
                    'id' => $row->recipient?->id,
                    'name' => $row->recipient?->name,
                    'email' => $row->recipient?->email,
                    'role' => $row->recipient?->role,
                    'is_read' => $row->is_read,
                    'read_at' => $row->read_at,
                ])
                ->filter(fn (array $recipient) => $recipient['id'] !== null)
                ->values();

            $message->setAttribute('broadcast_recipients', $recipients->all());
        });

        return MessageResource::collection($messages);
    }

    /** Affiche un message + ses réponses. Marque comme lu si destinataire. */
    public function show(Request $request, Message $message): MessageResource
    {
        $userId = $request->user()->id;

        $this->authorizeAccess($message, $userId);

        $messageIds = [$message->id];
        if ($message->parent_message_id === null) {
            $messageIds = array_merge(
                $messageIds,
                Message::query()
                    ->where('parent_message_id', $message->id)
                    ->pluck('id')
                    ->all(),
            );
        }

        $readCount = Message::query()
            ->whereIn('id', $messageIds)
            ->where('recipient_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $message->refresh();
        $message->load([
            'sender',
            'recipient',
            'replies' => fn ($query) => $query->visibleToUser($userId)->with(['sender', 'recipient']),
        ]);

        if ($readCount > 0) {
            $this->dispatchRealtimeUpdate($request, [$userId], 'message.read', $message, 'messages');
        }

        return MessageResource::make($message);
    }

    /** Envoie un nouveau message ou une réponse. */
    public function store(MessageRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['sender_id'] = $request->user()->id;

        $message = Message::query()->create($data);
        $message->load(['sender', 'recipient']);

        $this->dispatchRealtimeUpdate(
            $request,
            [$message->sender_id, $message->recipient_id],
            'message.created',
            $message,
            'messages',
        );

        return MessageResource::make($message)->response()->setStatusCode(201);
    }

    /** Suppression logique : masque tout le fil pour l'utilisateur connecté. */
    public function destroy(Request $request, Message $message): JsonResponse
    {
        $userId = $request->user()->id;

        $this->authorizeAccess($message, $userId);

        $root = $message->parent_message_id === null
            ? $message
            : Message::query()->find($message->parent_message_id);

        $threadQuery = $root
            ? Message::query()->where(function ($query) use ($root): void {
                $query
                    ->where('id', $root->id)
                    ->orWhere('parent_message_id', $root->id);
            })
            : Message::query()->whereKey($message->id);

        $threadQuery->get()->each(function (Message $threadMessage) use ($userId): void {
            $updates = [];

            if ($threadMessage->sender_id === $userId) {
                $updates['deleted_by_sender'] = true;
            }

            if ($threadMessage->recipient_id === $userId) {
                $updates['deleted_by_recipient'] = true;
            }

            if ($updates !== []) {
                $threadMessage->update($updates);
            }
        });

        $message->refresh();

        $this->dispatchRealtimeUpdate($request, [$userId], 'message.deleted', $message, 'messages');

        return response()->json(null, 204);
    }

    /** Nombre de messages non lus (pour le badge de navigation). */
    public function unreadCount(Request $request): JsonResponse
    {
        $count = Message::query()
            ->where('recipient_id', $request->user()->id)
            ->where('deleted_by_recipient', false)
            ->whereNull('read_at')
            ->count();

        return response()->json(['unread' => $count]);
    }

    /** Liste des utilisateurs à qui l'on peut écrire. */
    public function contacts(Request $request): JsonResponse
    {
        $users = $this->recipientService
            ->contactsFor($request->user())
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
                'cycles' => $this->cyclesForContact($request->user(), $u)->values(),
                'classrooms' => $this->classroomsForContact($request->user(), $u)->values(),
            ]);

        $payload = ['data' => $users];

        if (in_array($request->user()->role, [UserRole::Admin, UserRole::Secretariat], true)) {
            $payload['audiences'] = [
                ['type' => BroadcastMessageService::AUDIENCE_ALL_USERS, 'label' => 'Tous les utilisateurs'],
                ['type' => BroadcastMessageService::AUDIENCE_ALL_PARENTS, 'label' => 'Tous les parents'],
                ['type' => BroadcastMessageService::AUDIENCE_ALL_TEACHERS, 'label' => 'Tous les enseignants'],
                ['type' => BroadcastMessageService::AUDIENCE_ALL_STUDENTS, 'label' => 'Tous les élèves'],
                ['type' => BroadcastMessageService::AUDIENCE_CLASSROOM, 'label' => 'Classe', 'requires' => 'classroom_id'],
                ['type' => BroadcastMessageService::AUDIENCE_CYCLE, 'label' => 'Cycle', 'requires' => 'cycle'],
                ['type' => BroadcastMessageService::AUDIENCE_CUSTOM, 'label' => 'Sélection personnalisée', 'requires' => 'user_ids'],
            ];
        }

        return response()->json($payload);
    }

    /**
     * @return Collection<int, array{id: int, name: string, cycle: string|null}>
     */
    private function classroomsForContact(User $actor, User $contact): Collection
    {
        return match ($contact->role) {
            UserRole::Eleve => $this->studentClassrooms($actor, $contact),
            UserRole::Parent => $this->parentClassrooms($actor, $contact),
            UserRole::Enseignant => $this->teacherClassrooms($actor, $contact),
            default => collect(),
        };
    }

    /** @return Collection<int, string> */
    private function cyclesForContact(User $actor, User $contact): Collection
    {
        return match ($contact->role) {
            UserRole::Eleve => $this->studentCycles($actor, $contact),
            UserRole::Parent => $this->parentCycles($actor, $contact),
            UserRole::Enseignant => $this->teacherCycles($actor, $contact),
            default => collect(),
        };
    }

    /**
     * @return Collection<int, array{id: int, name: string, cycle: string|null}>
     */
    private function studentClassrooms(User $actor, User $studentUser): Collection
    {
        $query = Student::query()
            ->where('user_id', $studentUser->id)
            ->with('classroom.level:id,cycle,name');
        $this->applyActorStudentScope($query, $actor);

        return $query
            ->get()
            ->pluck('classroom')
            ->filter()
            ->map(fn ($classroom) => [
                'id' => (int) $classroom->id,
                'name' => $classroom->full_name,
                'cycle' => $classroom->level?->cycle,
            ])
            ->unique('id')
            ->values();
    }

    /**
     * @return Collection<int, array{id: int, name: string, cycle: string|null}>
     */
    private function parentClassrooms(User $actor, User $parentUser): Collection
    {
        $profile = ParentProfile::query()
            ->where('user_id', $parentUser->id)
            ->first();

        if ($profile === null) {
            return collect();
        }

        $students = $profile->students()->with('classroom.level:id,cycle,name');
        $this->applyActorStudentScope($students, $actor);

        return $students
            ->get()
            ->pluck('classroom')
            ->filter()
            ->map(fn ($classroom) => [
                'id' => (int) $classroom->id,
                'name' => $classroom->full_name,
                'cycle' => $classroom->level?->cycle,
            ])
            ->unique('id')
            ->values();
    }

    /**
     * @return Collection<int, array{id: int, name: string, cycle: string|null}>
     */
    private function teacherClassrooms(User $actor, User $teacherUser): Collection
    {
        $query = TeacherAssignment::query()
            ->whereHas('teacher', fn ($teacherQuery) => $teacherQuery->where('user_id', $teacherUser->id))
            ->with('classroom.level:id,cycle,name');

        $this->applyActorAssignmentScope($query, $actor);

        $currentSchoolYearId = SchoolYearContext::currentId();
        if ($currentSchoolYearId !== null) {
            $query->where('school_year_id', $currentSchoolYearId);
        }

        return $query
            ->get()
            ->pluck('classroom')
            ->filter()
            ->map(fn ($classroom) => [
                'id' => (int) $classroom->id,
                'name' => $classroom->full_name,
                'cycle' => $classroom->level?->cycle,
            ])
            ->unique('id')
            ->values();
    }

    /** @return Collection<int, string> */
    private function studentCycles(User $actor, User $studentUser): Collection
    {
        $query = Student::query()
            ->where('user_id', $studentUser->id)
            ->with('classroom.level:id,cycle');
        $this->applyActorStudentScope($query, $actor);

        return $query
            ->get()
            ->pluck('classroom.level.cycle')
            ->filter()
            ->unique()
            ->values();
    }

    /** @return Collection<int, string> */
    private function parentCycles(User $actor, User $parentUser): Collection
    {
        $profile = ParentProfile::query()
            ->where('user_id', $parentUser->id)
            ->first();

        if ($profile === null) {
            return collect();
        }

        $students = $profile->students()->with('classroom.level:id,cycle');
        $this->applyActorStudentScope($students, $actor);

        return $students
            ->get()
            ->pluck('classroom.level.cycle')
            ->filter()
            ->unique()
            ->values();
    }

    /** @return Collection<int, string> */
    private function teacherCycles(User $actor, User $teacherUser): Collection
    {
        $query = TeacherAssignment::query()
            ->whereHas('teacher', fn ($teacherQuery) => $teacherQuery->where('user_id', $teacherUser->id))
            ->with('classroom.level:id,cycle');

        $this->applyActorAssignmentScope($query, $actor);

        $currentSchoolYearId = SchoolYearContext::currentId();
        if ($currentSchoolYearId !== null) {
            $query->where('school_year_id', $currentSchoolYearId);
        }

        return $query
            ->get()
            ->pluck('classroom.level.cycle')
            ->filter()
            ->unique()
            ->values();
    }

    private function applyActorStudentScope($query, User $actor): void
    {
        if ($actor->role !== UserRole::Admin || AdminScopeContext::isGlobalAdmin($actor)) {
            return;
        }

        $query->whereHas('classroom.level', fn ($levelQuery) => $levelQuery
            ->whereIn('cycle', AdminScopeContext::allowedCycles($actor)));
    }

    private function applyActorAssignmentScope($query, User $actor): void
    {
        if ($actor->role !== UserRole::Admin || AdminScopeContext::isGlobalAdmin($actor)) {
            return;
        }

        $query->whereHas('classroom.level', fn ($levelQuery) => $levelQuery
            ->whereIn('cycle', AdminScopeContext::allowedCycles($actor)));
    }

    /**
     * Diffusion d'une annonce à un groupe (admin/secrétariat uniquement).
     * POST /api/v1/messages/broadcast
     */
    public function broadcast(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! in_array($user->role, [UserRole::Admin, UserRole::Secretariat], true)) {
            abort(403, 'Seuls l\'administration et le secrétariat peuvent diffuser une annonce.');
        }

        $payload = $this->normalizeBroadcastPayload($request->all());

        $data = validator($payload, [
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'audience_type' => ['required', Rule::in(BroadcastMessageService::AUDIENCES)],
            'classroom_id' => ['nullable', 'integer', 'exists:classrooms,id'],
            'cycle' => ['nullable', 'string', Rule::in(['maternel', 'primaire', 'cteb', 'secondaire'])],
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ])->validate();

        // Cohérence : audience_type → champ requis
        if ($data['audience_type'] === BroadcastMessageService::AUDIENCE_CLASSROOM
            && empty($data['classroom_id'])) {
            abort(422, 'classroom_id est requis pour une diffusion à une classe.');
        }
        if ($data['audience_type'] === BroadcastMessageService::AUDIENCE_CYCLE
            && empty($data['cycle'])) {
            abort(422, 'cycle est requis pour une diffusion par cycle.');
        }
        if (! empty($data['cycle'])) {
            AdminScopeContext::assertCycleAllowed($user, $data['cycle']);
        }
        if (! empty($data['classroom_id'])) {
            AdminScopeContext::assertClassroomAllowed($user, (int) $data['classroom_id']);
        }
        if ($data['audience_type'] === BroadcastMessageService::AUDIENCE_CUSTOM
            && empty($data['user_ids'])) {
            abort(422, 'user_ids est requis pour une diffusion personnalisée.');
        }

        $result = $this->broadcaster->send($user, $data['subject'], $data['body'], $data);

        if ($result['broadcast_id'] !== '') {
            $announcements = Message::query()
                ->where('broadcast_id', $result['broadcast_id'])
                ->with(['sender', 'recipient'])
                ->get();

            foreach ($announcements as $announcement) {
                $this->dispatchRealtimeUpdate(
                    $request,
                    [$announcement->recipient_id],
                    'announcement.created',
                    $announcement,
                    'announcements',
                    ['broadcast_id' => $result['broadcast_id']],
                );
            }

            $this->dispatchRealtimeUpdate(
                $request,
                [$user->id],
                'announcement.created',
                $announcements->first(),
                'announcements',
                [
                    'broadcast_id' => $result['broadcast_id'],
                    'recipients_count' => $result['recipients_count'],
                ],
            );
        }

        return response()->json([
            'message' => sprintf('Annonce envoyée à %d destinataire(s).', $result['recipients_count']),
            'broadcast_id' => $result['broadcast_id'],
            'recipients_count' => $result['recipients_count'],
        ], 201);
    }

    public function updateBroadcast(Request $request, string $broadcastId): JsonResponse
    {
        $user = $request->user();
        if (! in_array($user->role, [UserRole::Admin, UserRole::Secretariat], true)) {
            abort(403, 'Seuls l\'administration et le secrétariat peuvent modifier une annonce.');
        }

        $data = validator($request->all(), [
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
        ])->validate();

        $updated = Message::query()
            ->where('broadcast_id', $broadcastId)
            ->where('sender_id', $user->id)
            ->where('is_announcement', true)
            ->update([
                'subject' => $data['subject'],
                'body' => $data['body'],
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            abort(404, 'Annonce introuvable.');
        }

        $announcements = Message::query()
            ->where('broadcast_id', $broadcastId)
            ->where('sender_id', $user->id)
            ->where('is_announcement', true)
            ->with(['sender', 'recipient'])
            ->get();

        foreach ($announcements as $announcement) {
            $this->dispatchRealtimeUpdate(
                $request,
                [$announcement->recipient_id],
                'announcement.updated',
                $announcement,
                'announcements',
                ['broadcast_id' => $broadcastId],
            );
        }

        $this->dispatchRealtimeUpdate(
            $request,
            [$user->id],
            'announcement.updated',
            $announcements->first(),
            'announcements',
            ['broadcast_id' => $broadcastId, 'updated_count' => $updated],
        );

        return response()->json([
            'message' => 'Annonce modifiée.',
            'broadcast_id' => $broadcastId,
            'updated_count' => $updated,
        ]);
    }

    /** Aperçu du nombre de destinataires pour une audience donnée (utile au compose). */
    public function broadcastAudienceCount(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! in_array($user->role, [UserRole::Admin, UserRole::Secretariat], true)) {
            abort(403);
        }

        $payload = $this->normalizeBroadcastPayload($request->all());

        $data = validator($payload, [
            'audience_type' => ['required', Rule::in(BroadcastMessageService::AUDIENCES)],
            'classroom_id' => ['nullable', 'integer', 'exists:classrooms,id'],
            'cycle' => ['nullable', 'string', Rule::in(['maternel', 'primaire', 'cteb', 'secondaire'])],
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ])->validate();

        $count = $this->broadcaster
            ->resolveRecipients($data, $user)
            ->filter(fn ($u) => $u->id !== $user->id)
            ->unique('id')
            ->count();

        return response()->json(['count' => $count]);
    }

    /**
     * @param  iterable<int, int>  $userIds
     * @param  array<string, mixed>  $extra
     */
    private function dispatchRealtimeUpdate(
        Request $request,
        iterable $userIds,
        string $type,
        ?Message $message = null,
        string $section = 'messages',
        array $extra = [],
    ): void {
        $ids = collect($userIds)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        foreach ($ids as $userId) {
            $payload = array_filter([
                'type' => $type,
                'section' => $section,
                'message' => $message ? MessageResource::make($message)->resolve($request) : null,
                'unread_count' => $this->unreadCountFor($userId),
                ...$extra,
            ], fn ($value) => $value !== null);

            try {
                MessageRealtimeUpdated::dispatch($userId, $payload);
            } catch (\Throwable $exception) {
                Log::warning('Realtime message dispatch failed.', [
                    'user_id' => $userId,
                    'type' => $type,
                    'section' => $section,
                    'error' => $exception->getMessage(),
                ]);
            }

            // Notification Web Push (uniquement nouveau contenu, jamais à l'expéditeur).
            if (in_array($type, ['message.created', 'announcement.created'], true)
                && (! $message || $userId !== $message->sender_id)) {
                $isAnnouncement = $type === 'announcement.created' || $section === 'announcements';
                SendWebPush::dispatch($userId, [
                    'title' => $isAnnouncement
                        ? 'Nouvelle annonce'
                        : 'Message de '.($message?->sender?->name ?? 'EduConnect'),
                    'body' => Str::limit((string) ($message?->subject ?: 'Vous avez reçu un nouveau message.'), 120),
                    'url' => '/messages',
                    'tag' => 'message-'.($message?->id ?? $userId),
                ]);
            }
        }
    }

    private function unreadCountFor(int $userId): int
    {
        return Message::query()
            ->where('recipient_id', $userId)
            ->where('deleted_by_recipient', false)
            ->whereNull('read_at')
            ->count();
    }

    private function authorizeAccess(Message $message, int $userId): void
    {
        if ($message->sender_id !== $userId && $message->recipient_id !== $userId) {
            abort(403, 'Accès refusé.');
        }
    }

    /** @param array<string, mixed> $payload */
    private function normalizeBroadcastPayload(array $payload): array
    {
        if (isset($payload['user_ids']) && is_string($payload['user_ids'])) {
            $payload['user_ids'] = collect(explode(',', $payload['user_ids']))
                ->map(fn (string $id) => trim($id))
                ->filter(fn (string $id) => $id !== '')
                ->values()
                ->all();
        }

        if (isset($payload['audience_type']) || ! isset($payload['audience'])) {
            return $payload;
        }

        $audience = (string) $payload['audience'];
        if (in_array($audience, [
            BroadcastMessageService::AUDIENCE_ALL_USERS,
            BroadcastMessageService::AUDIENCE_ALL_PARENTS,
            BroadcastMessageService::AUDIENCE_ALL_TEACHERS,
            BroadcastMessageService::AUDIENCE_ALL_STUDENTS,
        ], true)) {
            $payload['audience_type'] = $audience;

            return $payload;
        }

        if (str_starts_with($audience, 'classroom:')) {
            $payload['audience_type'] = BroadcastMessageService::AUDIENCE_CLASSROOM;
            $payload['classroom_id'] = (int) substr($audience, strlen('classroom:'));

            return $payload;
        }

        if (str_starts_with($audience, 'cycle:')) {
            $payload['audience_type'] = BroadcastMessageService::AUDIENCE_CYCLE;
            $payload['cycle'] = substr($audience, strlen('cycle:'));

            return $payload;
        }

        if (str_starts_with($audience, 'custom_user_ids:')) {
            $payload['audience_type'] = BroadcastMessageService::AUDIENCE_CUSTOM;
            $rawIds = substr($audience, strlen('custom_user_ids:'));
            $ids = json_decode($rawIds, true);
            $payload['user_ids'] = is_array($ids) ? array_values($ids) : [];
        }

        return $payload;
    }
}
