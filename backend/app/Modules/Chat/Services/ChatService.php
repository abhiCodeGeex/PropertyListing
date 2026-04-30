<?php

namespace App\Modules\Chat\Services;

use App\Models\Property;
use App\Models\PropertyTenant;
use App\Models\User;
use App\Modules\Chat\Events\ChatCreated;
use App\Modules\Chat\Events\ChatUpdated;
use App\Modules\Chat\Events\MessageRead;
use App\Modules\Chat\Events\MessageSent;
use App\Modules\Chat\Events\UserTyping;
use App\Modules\Chat\Models\Chat;
use App\Modules\Chat\Models\ChatMessage;
use App\Modules\Chat\Models\ChatParticipant;
use App\Modules\Chat\Models\MessageAttachment;
use App\Modules\Chat\Repositories\ChatRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatService
{
    public function __construct(
        private readonly ChatRepository $repository,
        private readonly ChatPermissionService $permissions,
        private readonly ChatPresenceService $presence
    ) {
    }

    public function paginateChats(User $user, array $filters = []): LengthAwarePaginator
    {
        return $this->repository->paginateForUser($user, $filters);
    }

    public function getVisibleChat(User $user, int $chatId): Chat
    {
        return $this->repository->findVisibleForUser($user, $chatId);
    }

    public function getMessages(Chat $chat, array $filters = []): array
    {
        $perPage = max(1, min((int) ($filters['per_page'] ?? 30), 100));
        $beforeId = isset($filters['before_id']) ? (int) $filters['before_id'] : null;

        $query = $this->repository->messageQuery($chat);

        if ($beforeId) {
            $query->where('id', '<', $beforeId);
        }

        $messages = $query->limit($perPage + 1)->get();
        $hasMore = $messages->count() > $perPage;

        if ($hasMore) {
            $messages = $messages->slice(0, $perPage);
        }

        return [
            'messages' => $messages->reverse()->values(),
            'has_more' => $hasMore,
            'next_before_id' => $messages->last()?->id,
        ];
    }

    public function createPrivateChat(User $actor, int $recipientId): Chat
    {
        $recipient = User::query()->findOrFail($recipientId);
        $this->permissions->assertCanCreatePrivateChat($actor, $recipient);

        $privateKey = $this->privateKey($actor->id, $recipient->id);

        $chat = DB::transaction(function () use ($actor, $recipient, $privateKey) {
            $existing = $this->repository->findExistingPrivateChat($privateKey);

            if ($existing) {
                if ($existing->trashed()) {
                    $existing->restore();
                }

                $this->restoreOrCreateParticipant($existing->id, $actor->id, $actor->id);
                $this->restoreOrCreateParticipant($existing->id, $recipient->id, $actor->id);

                return $existing;
            }

            $chat = Chat::query()->create([
                'type' => Chat::TYPE_PRIVATE,
                'private_key' => $privateKey,
                'created_by' => $actor->id,
                'title' => null,
                'description' => null,
                'meta' => [
                    'created_via' => 'api',
                ],
            ]);

            $this->restoreOrCreateParticipant($chat->id, $actor->id, $actor->id);
            $this->restoreOrCreateParticipant($chat->id, $recipient->id, $actor->id);

            return $chat;
        });

        $chat = $this->repository->findVisibleForUser($actor, $chat->id);
        event(new ChatCreated($chat, $actor->id));

        return $chat;
    }

    public function createGroupChat(User $actor, array $participantIds, array $payload): Chat
    {
        $participantIds = collect($participantIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0 && $id !== (int) $actor->id)
            ->unique()
            ->values();

        $participants = User::query()
            ->whereIn('id', $participantIds)
            ->get();

        if ($participants->count() !== $participantIds->count()) {
            throw ValidationException::withMessages([
                'participant_ids' => ['One or more selected users do not exist.'],
            ]);
        }

        $property = ! empty($payload['property_id'])
            ? Property::query()->findOrFail((int) $payload['property_id'])
            : null;

        $this->permissions->assertCanCreateGroupChat($actor, $participants, $property);

        $chat = DB::transaction(function () use ($actor, $participants, $payload, $property) {
            $chat = Chat::query()->create([
                'type' => Chat::TYPE_GROUP,
                'created_by' => $actor->id,
                'property_id' => $property?->id,
                'title' => $this->sanitizeTitle((string) $payload['title']),
                'description' => $this->nullableText($payload['description'] ?? null),
                'meta' => [
                    'created_via' => 'api',
                ],
            ]);

            $this->restoreOrCreateParticipant($chat->id, $actor->id, $actor->id);

            foreach ($participants as $participant) {
                $this->restoreOrCreateParticipant($chat->id, $participant->id, $actor->id);
            }

            return $chat;
        });

        $chat = $this->repository->findVisibleForUser($actor, $chat->id);
        event(new ChatCreated($chat, $actor->id));

        return $chat;
    }

    public function deleteGroupChat(User $actor, Chat $chat): void
    {
        $this->permissions->assertCanManageGroup($actor, $chat, 'delete this group chat');

        DB::transaction(function () use ($chat) {
            ChatParticipant::query()
                ->where('chat_id', $chat->id)
                ->whereNull('deleted_at')
                ->delete();

            $chat->delete();
        });

        event(new ChatUpdated(
            chat: $chat,
            action: 'deleted',
            actorId: (int) $actor->id,
            affectedUserId: null,
            chatDeleted: true,
            participantCount: 0
        ));
    }

    public function removeGroupParticipant(User $actor, Chat $chat, int $participantUserId): array
    {
        $this->permissions->assertCanManageGroup($actor, $chat, 'remove participants from this group chat');

        $participant = ChatParticipant::query()
            ->where('chat_id', $chat->id)
            ->where('user_id', $participantUserId)
            ->whereNull('deleted_at')
            ->first();

        if (! $participant) {
            throw ValidationException::withMessages([
                'user_id' => ['Selected user is not an active participant in this group chat.'],
            ]);
        }

        $chatDeleted = false;
        $remainingParticipants = 0;

        DB::transaction(function () use ($chat, $participant, &$chatDeleted, &$remainingParticipants) {
            $participant->delete();

            $remainingParticipants = ChatParticipant::query()
                ->where('chat_id', $chat->id)
                ->whereNull('deleted_at')
                ->count();

            if ($remainingParticipants === 0) {
                $chat->delete();
                $chatDeleted = true;
            }
        });

        event(new ChatUpdated(
            chat: $chat,
            action: 'participant_removed',
            actorId: (int) $actor->id,
            affectedUserId: $participantUserId,
            chatDeleted: $chatDeleted,
            participantCount: $remainingParticipants
        ));

        return [
            'chat_id' => $chat->id,
            'removed_user_id' => $participantUserId,
            'chat_deleted' => $chatDeleted,
        ];
    }

    /**
     * @param  array<int, UploadedFile>  $files
     */
    public function sendMessage(User $actor, Chat $chat, array $payload, array $files = []): ChatMessage
    {
        $type = strtolower((string) $payload['type']);
        $body = $this->nullableText($payload['message'] ?? null);
        $this->validateMessagePayload($type, $body, $files);

        $message = DB::transaction(function () use ($actor, $chat, $type, $body, $files) {
            $message = ChatMessage::query()->create([
                'chat_id' => $chat->id,
                'sender_id' => $actor->id,
                'type' => $type,
                'body' => $body,
                'meta' => [
                    'attachment_count' => count($files),
                ],
            ]);

            $this->storeAttachments($message, $actor, $files);

            $chat->forceFill([
                'last_message_id' => $message->id,
                'last_message_at' => now(),
            ])->save();

            ChatParticipant::query()
                ->where('chat_id', $chat->id)
                ->where('user_id', $actor->id)
                ->whereNull('deleted_at')
                ->update([
                    'last_read_message_id' => $message->id,
                    'last_read_at' => now(),
                    'unread_count' => 0,
                ]);

            ChatParticipant::query()
                ->where('chat_id', $chat->id)
                ->where('user_id', '!=', $actor->id)
                ->whereNull('deleted_at')
                ->increment('unread_count');

            return $message;
        });

        $message = $this->repository->messageQuery($chat)->whereKey($message->id)->firstOrFail();
        event(new MessageSent($message));

        return $message;
    }

    public function markChatRead(User $actor, Chat $chat, ?int $messageId = null): array
    {
        $participant = $this->repository->findParticipant($chat, $actor->id);

        if (! $participant) {
            abort(403, 'Unauthorized');
        }

        $targetMessageId = $messageId ?: ChatMessage::query()
            ->where('chat_id', $chat->id)
            ->whereNull('deleted_at')
            ->max('id');

        if (! $targetMessageId) {
            return [
                'chat_id' => $chat->id,
                'message_id' => null,
                'unread_count' => 0,
                'read_at' => now()->toIso8601String(),
            ];
        }

        $targetMessage = ChatMessage::query()
            ->where('chat_id', $chat->id)
            ->whereKey($targetMessageId)
            ->firstOrFail();

        $currentLastRead = (int) ($participant->last_read_message_id ?? 0);
        $newLastRead = max($currentLastRead, (int) $targetMessage->id);
        $readAt = now();

        DB::transaction(function () use ($chat, $actor, $participant, $currentLastRead, $newLastRead, $readAt) {
            $messageIds = ChatMessage::query()
                ->where('chat_id', $chat->id)
                ->where('sender_id', '!=', $actor->id)
                ->where('id', '>', $currentLastRead)
                ->where('id', '<=', $newLastRead)
                ->whereNull('deleted_at')
                ->pluck('id')
                ->all();

            if ($messageIds !== []) {
                $rows = array_map(fn ($id) => [
                    'message_id' => $id,
                    'user_id' => $actor->id,
                    'read_at' => $readAt,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $messageIds);

                DB::table('message_reads')->upsert(
                    $rows,
                    ['message_id', 'user_id'],
                    ['read_at', 'updated_at']
                );
            }

            $remainingUnread = ChatMessage::query()
                ->where('chat_id', $chat->id)
                ->where('sender_id', '!=', $actor->id)
                ->where('id', '>', $newLastRead)
                ->whereNull('deleted_at')
                ->count();

            $participant->forceFill([
                'last_read_message_id' => $newLastRead,
                'last_read_at' => $readAt,
                'unread_count' => $remainingUnread,
            ])->save();
        });

        event(new MessageRead(
            chat: $chat,
            reader: $actor,
            messageId: $newLastRead,
            readAt: $readAt->toIso8601String()
        ));

        return [
            'chat_id' => $chat->id,
            'message_id' => $newLastRead,
            'unread_count' => ChatParticipant::query()
                ->where('chat_id', $chat->id)
                ->where('user_id', $actor->id)
                ->whereNull('deleted_at')
                ->value('unread_count') ?? 0,
            'read_at' => $readAt->toIso8601String(),
        ];
    }

    public function dispatchTyping(User $actor, Chat $chat, bool $typing = true): void
    {
        event(new UserTyping($chat, $actor, $typing));
    }

    public function markPresenceOnline(User $user): void
    {
        $this->presence->markOnline($user->id);
    }

    public function markPresenceOffline(User $user): void
    {
        $this->presence->markOffline($user->id);
    }

    public function relatedContext(User $actor, array $filters = []): array
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $requestedPropertyId = ! empty($filters['property_id']) ? (int) $filters['property_id'] : null;

        $properties = $this->accessibleProperties($actor, $requestedPropertyId);
        $propertyIds = $properties->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($actor->hasRole('super-admin')) {
            $userQuery = User::query()->with('roles')->where('id', '!=', $actor->id);

            if ($search !== '') {
                $userQuery->where(function ($builder) use ($search) {
                    $builder->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            }

            $users = $userQuery->orderBy('name')->limit(100)->get();
        } else {
            $userIds = $this->relatedUserIds($actor, $propertyIds);
            $userQuery = User::query()->with('roles')->whereIn('id', $userIds)->where('id', '!=', $actor->id);

            if ($search !== '') {
                $userQuery->where(function ($builder) use ($search) {
                    $builder->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            }

            $users = $userQuery->orderBy('name')->get();
        }

        return [
            'can_create_group' => $this->permissions->canCreateGroup($actor),
            'properties' => $properties->map(fn (Property $property) => [
                'id' => $property->id,
                'property_name' => $property->property_name,
                'city' => $property->city,
                'state' => $property->state,
                'owner' => $property->owner ? [
                    'id' => $property->owner->id,
                    'name' => $property->owner->name,
                    'email' => $property->owner->email,
                ] : null,
                'manager' => $property->manager ? [
                    'id' => $property->manager->id,
                    'name' => $property->manager->name,
                    'email' => $property->manager->email,
                ] : null,
            ])->values()->all(),
            'users' => $users->map(function (User $user) use ($actor, $requestedPropertyId, $properties) {
                $property = $requestedPropertyId
                    ? $properties->firstWhere('id', $requestedPropertyId)
                    : $properties->first();

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->roles->pluck('name')->values()->all(),
                    'can_message' => $this->permissions->canCreatePrivateChat($actor, $user),
                    'group_eligible' => $property ? $this->permissions->canIncludeInPropertyGroup($actor, $user, $property) : false,
                ];
            })->values()->all(),
        ];
    }

    public function downloadAttachment(Chat $chat, MessageAttachment $attachment): StreamedResponse
    {
        $attachment->loadMissing('message');

        if ((int) $attachment->message?->chat_id !== (int) $chat->id) {
            abort(404, 'Attachment not found.');
        }

        return Storage::disk($attachment->disk)->download(
            $attachment->path,
            $attachment->original_name,
            [
                'Content-Type' => $attachment->mime_type,
            ]
        );
    }

    private function privateKey(int $firstUserId, int $secondUserId): string
    {
        $ids = [$firstUserId, $secondUserId];
        sort($ids);

        return implode(':', $ids);
    }

    private function restoreOrCreateParticipant(int $chatId, int $userId, int $addedBy): void
    {
        $participant = ChatParticipant::query()
            ->withTrashed()
            ->where('chat_id', $chatId)
            ->where('user_id', $userId)
            ->first();

        if ($participant) {
            if ($participant->trashed()) {
                $participant->restore();
            }

            $participant->forceFill([
                'added_by' => $addedBy,
                'joined_at' => $participant->joined_at ?? now(),
            ])->save();

            return;
        }

        ChatParticipant::query()->create([
            'chat_id' => $chatId,
            'user_id' => $userId,
            'added_by' => $addedBy,
            'joined_at' => now(),
        ]);
    }

    /**
     * @param  array<int, UploadedFile>  $files
     */
    private function storeAttachments(ChatMessage $message, User $actor, array $files): void
    {
        foreach ($files as $file) {
            $extension = strtolower((string) $file->getClientOriginalExtension());
            $generatedName = Str::uuid()->toString().($extension !== '' ? '.'.$extension : '');
            $path = $file->storeAs(
                'chat/'.$message->chat_id.'/messages/'.$message->id,
                $generatedName,
                'local'
            );

            MessageAttachment::query()->create([
                'message_id' => $message->id,
                'uploaded_by' => $actor->id,
                'disk' => 'local',
                'path' => $path,
                'file_name' => $generatedName,
                'original_name' => $this->sanitizeFilename($file->getClientOriginalName()),
                'mime_type' => (string) $file->getClientMimeType(),
                'size' => (int) $file->getSize(),
            ]);
        }
    }

    /**
     * @param  array<int, UploadedFile>  $files
     */
    private function validateMessagePayload(string $type, ?string $body, array $files): void
    {
        if (! in_array($type, ChatMessage::TYPES, true)) {
            throw ValidationException::withMessages([
                'type' => ['Unsupported message type.'],
            ]);
        }

        if ($type === ChatMessage::TYPE_TEXT && $body === null) {
            throw ValidationException::withMessages([
                'message' => ['Text messages cannot be empty.'],
            ]);
        }

        if (in_array($type, [ChatMessage::TYPE_FILE, ChatMessage::TYPE_IMAGE], true) && $files === []) {
            throw ValidationException::withMessages([
                'attachments' => ['File and image messages require at least one attachment.'],
            ]);
        }

        if ($type === ChatMessage::TYPE_IMAGE) {
            foreach ($files as $file) {
                if (! str_starts_with((string) $file->getClientMimeType(), 'image/')) {
                    throw ValidationException::withMessages([
                        'attachments' => ['Image messages only accept image attachments.'],
                    ]);
                }
            }
        }
    }

    private function sanitizeTitle(string $value): string
    {
        $sanitized = trim(strip_tags($value));

        if ($sanitized === '') {
            throw ValidationException::withMessages([
                'title' => ['Group title is required.'],
            ]);
        }

        return preg_replace('/\s+/', ' ', $sanitized) ?: $sanitized;
    }

    private function nullableText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $sanitized = trim(strip_tags($value));

        if ($sanitized === '') {
            return null;
        }

        return preg_replace("/\r\n?/", "\n", $sanitized) ?: $sanitized;
    }

    private function sanitizeFilename(string $value): string
    {
        return trim(str_replace(['\\', '/'], '-', $value));
    }

    private function accessibleProperties(User $actor, ?int $propertyId = null): EloquentCollection
    {
        $query = Property::query()
            ->with([
                'owner:id,name,email',
                'manager:id,name,email',
            ])
            ->orderBy('property_name');

        if ($propertyId) {
            $query->whereKey($propertyId);
        }

        if ($actor->hasRole('super-admin')) {
            return $query->get();
        }

        $query->where(function ($builder) use ($actor) {
            $hasCondition = false;

            if ($actor->hasRole('owner')) {
                $builder->where('user_id', $actor->id);
                $hasCondition = true;
            }

            if ($actor->hasRole('property_manager')) {
                $method = $hasCondition ? 'orWhere' : 'where';
                $builder->{$method}('manager_id', $actor->id);
                $hasCondition = true;
            }

            if ($actor->hasRole('tenant')) {
                $method = $hasCondition ? 'orWhereHas' : 'whereHas';
                $builder->{$method}('tenants', fn ($tenantQuery) => $tenantQuery->where('users.id', $actor->id));
            }
        });

        return $query->get();
    }

    /**
     * @param  array<int, int>  $propertyIds
     * @return Collection<int, int>
     */
    private function relatedUserIds(User $actor, array $propertyIds): Collection
    {
        if ($propertyIds === []) {
            return collect();
        }

        $properties = Property::query()
            ->whereIn('id', $propertyIds)
            ->get(['id', 'user_id', 'manager_id']);

        $tenantIds = PropertyTenant::query()
            ->whereIn('property_id', $propertyIds)
            ->pluck('tenant_id');

        $ids = collect();

        if ($actor->hasRole('owner')) {
            $ids = $ids
                ->merge($tenantIds)
                ->merge($properties->pluck('manager_id')->filter());
        }

        if ($actor->hasRole('property_manager')) {
            $superAdminIds = User::role('super-admin')->pluck('users.id');
            $ids = $ids
                ->merge($tenantIds)
                ->merge($properties->pluck('user_id')->filter())
                ->merge($superAdminIds);
        }

        if ($actor->hasRole('tenant')) {
            $ids = $ids
                ->merge($properties->pluck('user_id')->filter())
                ->merge($properties->pluck('manager_id')->filter());
        }

        return $ids
            ->filter(fn ($id) => ! is_null($id) && (int) $id !== (int) $actor->id)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }
}
