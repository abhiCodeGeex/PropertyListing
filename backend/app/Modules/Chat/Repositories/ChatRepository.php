<?php

namespace App\Modules\Chat\Repositories;

use App\Models\User;
use App\Modules\Chat\Models\Chat;
use App\Modules\Chat\Models\ChatMessage;
use App\Modules\Chat\Models\ChatParticipant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ChatRepository
{
    public function paginateForUser(User $user, array $filters = []): LengthAwarePaginator
    {
        $query = $this->baseChatQuery($user);

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);

            $query->where(function (Builder $builder) use ($search) {
                $builder->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('participants.user', function (Builder $userQuery) use ($search) {
                        $userQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    })
                    ->orWhereHas('lastMessage', function (Builder $messageQuery) use ($search) {
                        $messageQuery->where('body', 'like', "%{$search}%");
                    });
            });
        }

        $perPage = max(1, min((int) ($filters['per_page'] ?? 20), 50));

        return $query
            ->orderByRaw('COALESCE(last_message_at, updated_at) DESC')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findVisibleForUser(User $user, int $chatId): Chat
    {
        return $this->detailQuery($user)
            ->whereKey($chatId)
            ->firstOrFail();
    }

    public function findExistingPrivateChat(string $privateKey): ?Chat
    {
        return Chat::query()
            ->withTrashed()
            ->with([
                'participants.user.roles',
                'property.owner',
                'property.manager',
                'lastMessage.sender',
                'lastMessage.attachments',
            ])
            ->where('type', Chat::TYPE_PRIVATE)
            ->where('private_key', $privateKey)
            ->first();
    }

    public function detailQuery(User $user): Builder
    {
        return Chat::query()
            ->whereHas('participants', function (Builder $builder) use ($user) {
                $builder->where('user_id', $user->id)->whereNull('deleted_at');
            })
            ->with([
                'creator:id,name,email',
                'property.owner:id,name,email',
                'property.manager:id,name,email',
                'participants' => fn ($participantQuery) => $participantQuery
                    ->whereNull('deleted_at')
                    ->with(['user.roles'])
                    ->orderBy('id'),
                'lastMessage.sender:id,name,email',
                'lastMessage.attachments',
            ]);
    }

    public function messageQuery(Chat $chat): Builder
    {
        return ChatMessage::query()
            ->where('chat_id', $chat->id)
            ->whereNull('deleted_at')
            ->with([
                'sender:id,name,email',
                'attachments',
                'reads.user:id,name,email',
            ])
            ->latest('id');
    }

    public function findParticipant(Chat $chat, int $userId): ?ChatParticipant
    {
        return ChatParticipant::query()
            ->where('chat_id', $chat->id)
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->first();
    }

    public function activeParticipantIds(Chat $chat): Collection
    {
        return ChatParticipant::query()
            ->where('chat_id', $chat->id)
            ->whereNull('deleted_at')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id);
    }

    private function baseChatQuery(User $user): Builder
    {
        return Chat::query()
            ->whereHas('participants', function (Builder $builder) use ($user) {
                $builder->where('user_id', $user->id)->whereNull('deleted_at');
            })
            ->with([
                'property.owner:id,name,email',
                'property.manager:id,name,email',
                'participants' => fn ($participantQuery) => $participantQuery
                    ->whereNull('deleted_at')
                    ->with(['user.roles'])
                    ->orderBy('id'),
                'lastMessage.sender:id,name,email',
                'lastMessage.attachments',
            ]);
    }
}
